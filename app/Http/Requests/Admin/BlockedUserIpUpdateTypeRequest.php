<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BlockedUserIpUpdateTypeRequest extends FormRequest
{
    /**
     * Allow admin users to update blocked registration IP type.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate the blocked IP record id and target type.
     */
    public function rules(): array
    {
        return [
            'id' => 'required|integer|min:1',
            'type' => 'required|string|in:normal,dangerous',
        ];
    }
}
