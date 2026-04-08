<?php

namespace App\Http\Controllers\V3\User;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ExternalPayment\ExternalPaymentVerifierFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\V1\User\OrderController as V1OrderController;

class OrderController extends V1OrderController
{
    /**
     * 外部支付记录提交
     * 
     * 用于客户端内支付（如 Apple IAP、Google Play）完成后，提交消费记录到后端
     * 后端会创建订单并走完整的支付流程，触发所有钩子（佣金、礼品卡、通知等）
     * 
     * POST /api/v1/user/order/externalPayment
     */
    public function externalPayment(Request $request)
    {
        $validated = $request->validate([
            'plan_id' => 'required|integer|exists:App\Models\Plan,id',
            'period' => 'required|string',
            'transaction_id' => 'required|string|max:255',
            'payment_source' => 'required|string|in:apple_iap,google_play,trust',
            'amount' => 'nullable|integer|min:0',
            'receipt' => 'nullable|string',
            'product_id' => 'nullable|string',
        ]);

        $user = User::findOrFail($request->user()->id);
        $transactionId = $validated['transaction_id'];
        $paymentSource = $validated['payment_source'];

        try {
            return DB::transaction(function () use ($user, $validated, $transactionId, $paymentSource) {
                // 1. 防重复：检查 transaction_id 是否已处理
                $existingOrder = Order::where('callback_no', $transactionId)->first();
                if ($existingOrder) {
                    Log::warning('External payment duplicate transaction', [
                        'user_id' => $user->id,
                        'transaction_id' => $transactionId,
                        'existing_order_id' => $existingOrder->id
                    ]);
                    throw new ApiException('此交易已处理，请勿重复提交');
                }

                // 2. 凭证验证（可选）
                $verificationResult = null;
                if (admin_setting('external_payment_verify_enable', 0)) {
                    $verificationResult = $this->verifyExternalPayment(
                        $paymentSource,
                        $transactionId,
                        $validated['receipt'] ?? null,
                        [
                            'product_id' => $validated['product_id'] ?? null,
                            'amount' => $validated['amount'] ?? 0,
                        ]
                    );
                }

                // 3. 创建订单
                $plan = Plan::findOrFail($validated['plan_id']);
                $order = OrderService::createFromRequest(
                    $user,
                    $plan,
                    $validated['period']
                );

                // 4. 记录外部支付信息
                $order->payment_id = 0;  // 外部支付没有内部支付方式ID
                $order->callback_no = $transactionId;
                $order->paid_at = time();
                
                // 记录实际支付金额（如果提供）
                if (isset($validated['amount'])) {
                    $order->total_amount = $validated['amount'];
                }

                // 记录验证结果到元数据
                if ($verificationResult) {
                    $order->metadata = array_merge($order->metadata ?? [], [
                        'external_payment' => [
                            'source' => $paymentSource,
                            'transaction_id' => $transactionId,
                            'verified' => true,
                            'verification_data' => $verificationResult
                        ]
                    ]);
                } else {
                    $order->metadata = array_merge($order->metadata ?? [], [
                        'external_payment' => [
                            'source' => $paymentSource,
                            'transaction_id' => $transactionId,
                            'verified' => false
                        ]
                    ]);
                }

                $order->save();

                // 5. 标记已支付并触发订单处理
                $orderService = new OrderService($order);
                if (!$orderService->paid($transactionId)) {
                    throw new ApiException('订单处理失败');
                }

                Log::info('External payment processed successfully', [
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'trade_no' => $order->trade_no,
                    'transaction_id' => $transactionId,
                    'payment_source' => $paymentSource
                ]);

                return $this->ok([
                    'trade_no' => $order->trade_no,
                    'order_id' => $order->id,
                    'status' => $order->status,
                    'message' => '支付成功'
                ]);
            });
        } catch (ApiException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('External payment failed', [
                'user_id' => $user->id,
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error([500, '支付处理失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 验证外部支付凭证
     */
    private function verifyExternalPayment(
        string $paymentSource,
        string $transactionId,
        ?string $receipt,
        array $metadata
    ): array {
        // 获取验证器配置
        $config = match ($paymentSource) {
            'apple_iap' => [
                'shared_secret' => admin_setting('apple_iap_shared_secret', ''),
            ],
            'google_play' => [
                'package_name' => admin_setting('google_play_package_name', ''),
                'access_token' => admin_setting('google_play_access_token', ''),
            ],
            default => []
        };

        $verifier = ExternalPaymentVerifierFactory::make($paymentSource, $config);
        return $verifier->verify($transactionId, $receipt, $metadata);
    }
}

