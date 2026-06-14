<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BlockedUserIpFetchRequest extends FormRequest
{
    /**
     * Allow admin users to query blocked registration IP records.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate query filters and pagination parameters.
     */
    public function rules(): array
    {
        return [
            'ip' => 'nullable|string|max:45',
            'bannedUserId' => 'nullable|integer|min:1',
            'operatorUserId' => 'nullable|integer|min:1',
            'current' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
        ];
    }
}
