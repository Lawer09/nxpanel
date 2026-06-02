<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class WooCommerceOrderPaidRequest extends FormRequest
{
    /**
     * Allow authenticated application clients to submit WooCommerce order events.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate the minimum payload required to create or record an external order.
     */
    public function rules(): array
    {
        return [
            'event' => ['required', 'string', 'in:woocommerce_order_paid'],
            'time' => ['nullable', 'date'],
            'site' => ['nullable', 'array'],
            'site.name' => ['nullable', 'string', 'max:100'],
            'site.url' => ['nullable', 'url', 'max:255'],
            'order' => ['required', 'array'],
            'order.order_id' => ['required', 'integer', 'min:1'],
            'order.order_number' => ['nullable', 'string', 'max:64'],
            'order.status' => ['required', 'string', 'in:processing,completed'],
            'order.currency' => ['nullable', 'string', 'max:10'],
            'order.total' => ['required', 'numeric', 'min:0'],
            'order.payment_method' => ['nullable', 'string', 'max:64'],
            'order.payment_method_title' => ['nullable', 'string', 'max:128'],
            'order.transaction_id' => ['nullable', 'string', 'max:128'],
            'order.customer_id' => ['nullable', 'integer', 'min:0'],
            'order.billing_email' => ['nullable', 'email', 'max:255'],
            'order.date_paid' => ['nullable', 'date'],
            'tracking' => ['required', 'array'],
            'tracking.custom_tg_id' => ['nullable', 'string', 'max:64'],
            'tracking.device_id' => ['required', 'string', 'max:255'],
            'tracking._vpn_sync_done' => ['nullable', 'string', 'max:20'],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'array'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.total' => ['nullable', 'numeric', 'min:0'],
            'trigger' => ['required', 'string', 'in:processing,completed'],
        ];
    }
}
