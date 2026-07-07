<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BlockedUserIpBatchBlockRequest extends FormRequest
{
    /**
     * Allow admin users to batch block IP records.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate IP records and optional user-ban behavior.
     */
    public function rules(): array
    {
        return [
            'ips' => 'required|array|min:1|max:500',
            'ips.*' => 'required|ip',
            'type' => 'nullable|string|in:normal,dangerous',
            'banUsers' => 'nullable|boolean',
            'reason' => 'nullable|string|max:500',
        ];
    }
}
