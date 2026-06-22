<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AidLoginBanRuleFetchRequest extends FormRequest
{
    /**
     * Allow admin users to query AID login ban rules.
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
            'enabled' => 'nullable|boolean',
            'packageName' => 'nullable|string|max:191',
            'country' => 'nullable|string|max:16',
            'current' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
        ];
    }
}
