<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\ExternalOrderReceipt;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class WooCommerceOrderReceiptService
{
    /**
     * Receive a paid WooCommerce order and process it exactly once by external order ID.
     */
    public function receive(array $payload): array
    {
        $externalOrderId = (string) data_get($payload, 'order.order_id');
        $receipt = $this->createReceipt($externalOrderId, $payload);

        if (!$receipt->wasRecentlyCreated) {
            return $this->formatResult($receipt, true);
        }

        try {
            DB::transaction(function () use ($receipt, $payload) {
                $lockedReceipt = ExternalOrderReceipt::lockForUpdate()->findOrFail($receipt->id);
                $this->processReceipt($lockedReceipt, $payload);
            });
        } catch (Throwable $e) {
            Log::error('WooCommerce order receipt failed', [
                'external_order_id' => $externalOrderId,
                'error' => $e->getMessage(),
            ]);

            $receipt->refresh();
            $this->markFailed($receipt, $e->getMessage());
        }

        return $this->formatResult($receipt->refresh(), false);
    }

    /**
     * Create the idempotency receipt; if a duplicate insert races, return the existing row.
     */
    private function createReceipt(string $externalOrderId, array $payload): ExternalOrderReceipt
    {
        try {
            return ExternalOrderReceipt::create([
                'provider' => ExternalOrderReceipt::PROVIDER_WOOCOMMERCE,
                'external_order_id' => $externalOrderId,
                'status' => ExternalOrderReceipt::STATUS_PENDING,
                'product_id' => (int) data_get($payload, 'items.0.product_id'),
                'transaction_id' => $this->nullableString(data_get($payload, 'order.transaction_id')),
                'payload' => $payload,
            ]);
        } catch (QueryException $e) {
            $existing = ExternalOrderReceipt::where('provider', ExternalOrderReceipt::PROVIDER_WOOCOMMERCE)
                ->where('external_order_id', $externalOrderId)
                ->first();

            if ($existing) {
                $existing->wasRecentlyCreated = false;
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * Match user/product mapping, create a local order, and invoke the existing paid flow.
     */
    private function processReceipt(ExternalOrderReceipt $receipt, array $payload): void
    {
        $deviceId = trim((string) data_get($payload, 'tracking.device_id'));
        $email = $deviceId . '@apple.com';
        $user = User::where('email', $email)->first();

        if (!$user) {
            throw new ApiException('user_not_found');
        }

        $productId = (int) data_get($payload, 'items.0.product_id');
        $mapping = $this->getProductMapping($productId);
        if (!$mapping) {
            throw new ApiException('product_mapping_not_found');
        }

        $plan = Plan::find((int) $mapping['plan_id']);
        if (!$plan) {
            throw new ApiException('plan_not_found');
        }

        $period = PlanService::getPeriodKey((string) $mapping['period']);
        (new PlanService($plan))->validatePurchase($user, $period);

        $order = $this->createLocalOrder($user, $plan, $period, $payload);
        $receipt->fill([
            'user_id' => $user->id,
            'local_order_id' => $order->id,
            'product_id' => $productId,
            'plan_id' => $plan->id,
            'period' => $period,
            'transaction_id' => $this->nullableString(data_get($payload, 'order.transaction_id')),
        ])->save();

        $callbackNo = $this->nullableString(data_get($payload, 'order.transaction_id')) ?: $receipt->external_order_id;
        if (!(new OrderService($order))->paid($callbackNo)) {
            throw new ApiException('local_order_paid_failed');
        }

        $receipt->refresh();
        $receipt->status = ExternalOrderReceipt::STATUS_PROCESSED;
        $receipt->error_message = null;
        $receipt->save();
    }

    /**
     * Create a local order using the paid amount reported by WooCommerce.
     */
    private function createLocalOrder(User $user, Plan $plan, string $period, array $payload): Order
    {
        $paidAmount = $this->amountToCents((string) data_get($payload, 'order.total', '0'));
        $order = new Order([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'period' => $period,
            'trade_no' => Helper::generateOrderNo(),
            'total_amount' => $paidAmount,
        ]);

        $orderService = new OrderService($order);
        $orderService->setOrderType($user);
        $order->total_amount = $paidAmount;
        $orderService->setInvite($user);

        if (!$order->save()) {
            throw new ApiException('local_order_create_failed');
        }

        return $order;
    }

    /**
     * Resolve WooCommerce product mapping from the admin setting.
     */
    private function getProductMapping(int $productId): ?array
    {
        $mappings = admin_setting('woocommerce_product_mappings', []);
        if (is_string($mappings)) {
            $mappings = json_decode($mappings, true) ?: [];
        }

        $mapping = $mappings[(string) $productId] ?? $mappings[$productId] ?? null;
        if (!is_array($mapping) || empty($mapping['plan_id']) || empty($mapping['period'])) {
            return null;
        }

        return $mapping;
    }

    /**
     * Mark a receipt as failed while preserving the raw payload for manual repair.
     */
    private function markFailed(ExternalOrderReceipt $receipt, string $message): void
    {
        if ($receipt->status === ExternalOrderReceipt::STATUS_PROCESSED) {
            return;
        }

        $receipt->status = ExternalOrderReceipt::STATUS_FAILED;
        $receipt->error_message = substr($message, 0, 500);
        $receipt->save();
    }

    private function formatResult(ExternalOrderReceipt $receipt, bool $duplicate): array
    {
        return [
            'received' => true,
            'processed' => $receipt->status === ExternalOrderReceipt::STATUS_PROCESSED,
            'duplicate' => $duplicate,
            'externalOrderId' => $receipt->external_order_id,
            'localOrderId' => $receipt->local_order_id,
            'status' => $receipt->status,
            'reason' => $receipt->status === ExternalOrderReceipt::STATUS_FAILED ? $receipt->error_message : null,
        ];
    }

    private function amountToCents(string $amount): int
    {
        $normalized = trim($amount);
        if ($normalized === '') {
            return 0;
        }

        return (int) round(((float) $normalized) * 100);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
