<?php

namespace App\Http\Requests\Admin;

use App\Services\PlanService;
use Illuminate\Foundation\Http\FormRequest;

class WooCommerceOrderMappingSaveRequest extends FormRequest
{
    /**
     * Allow admin users to maintain WooCommerce product mappings.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate WooCommerce product to local plan/period mapping payload.
     */
    public function rules(): array
    {
        return [
            'mappings' => 'required|array',
            'mappings.*.product_id' => 'required|integer|min:1|distinct',
            'mappings.*.plan_id' => 'required|integer|exists:v2_plan,id',
            'mappings.*.period' => 'required|string|in:' . implode(',', PlanService::getNewPeriods()),
        ];
    }
}
