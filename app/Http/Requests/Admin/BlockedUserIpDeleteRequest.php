<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BlockedUserIpDeleteRequest extends FormRequest
{
    /**
     * Allow admin users to delete blocked registration IP records.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate the blocked IP record id.
     */
    public function rules(): array
    {
        return [
            'id' => 'required|integer|min:1',
        ];
    }
}
