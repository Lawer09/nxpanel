<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BlockedUserIpBatchDeleteRequest extends FormRequest
{
    /**
     * Allow admin users to batch delete blocked registration IP records.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate blocked IP record ids.
     */
    public function rules(): array
    {
        return [
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|min:1',
        ];
    }
}
