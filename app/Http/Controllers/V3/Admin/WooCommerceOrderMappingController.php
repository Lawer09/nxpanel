<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\WooCommerceOrderMappingSaveRequest;
use App\Models\Plan;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;

class WooCommerceOrderMappingController extends Controller
{
    /**
     * Fetch WooCommerce product mappings used by third-party order callbacks.
     */
    public function fetch(): JsonResponse
    {
        $storedMappings = admin_setting('woocommerce_product_mappings', []);
        if (is_string($storedMappings)) {
            $storedMappings = json_decode($storedMappings, true) ?: [];
        }

        $planIds = collect($storedMappings)
            ->pluck('plan_id')
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();

        $plansById = Plan::query()
            ->whereIn('id', $planIds)
            ->pluck('name', 'id');

        $mappings = collect($storedMappings)
            ->map(function (array $mapping, string|int $productId) use ($plansById) {
                $planId = (int) ($mapping['plan_id'] ?? 0);

                return [
                    'product_id' => (int) $productId,
                    'plan_id' => $planId,
                    'plan_name' => $plansById[$planId] ?? null,
                    'period' => (string) ($mapping['period'] ?? ''),
                ];
            })
            ->sortBy('product_id')
            ->values()
            ->all();

        return $this->ok([
            'mappings' => $mappings,
            'periods' => PlanService::getNewPeriods(),
        ]);
    }

    /**
     * Save WooCommerce product mappings into the existing admin setting store.
     */
    public function save(WooCommerceOrderMappingSaveRequest $request): JsonResponse
    {
        $items = $request->validated('mappings');
        $mappings = [];

        foreach ($items as $item) {
            $mappings[(string) $item['product_id']] = [
                'plan_id' => (int) $item['plan_id'],
                'period' => (string) $item['period'],
            ];
        }

        admin_setting([
            'woocommerce_product_mappings' => $mappings,
        ]);

        return $this->ok(true);
    }
}
