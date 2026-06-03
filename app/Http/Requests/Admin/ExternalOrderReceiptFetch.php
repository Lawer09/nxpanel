<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ExternalOrderReceiptFetch extends FormRequest
{
    /**
     * Allow admin users to query third-party order receipts.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate receipt query filters and pagination parameters.
     */
    public function rules(): array
    {
        return [
            'provider' => 'nullable|string|max:32',
            'status' => 'nullable|string|in:pending,processed,failed',
            'externalOrderId' => 'nullable|string|max:64',
            'userId' => 'nullable|integer|min:1',
            'localOrderId' => 'nullable|integer|min:1',
            'transactionId' => 'nullable|string|max:128',
            'page' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
        ];
    }
}
