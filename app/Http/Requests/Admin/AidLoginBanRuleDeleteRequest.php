<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AidLoginBanRuleDeleteRequest extends FormRequest
{
    /**
     * Allow admin users to delete AID login ban rules.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate the rule id.
     */
    public function rules(): array
    {
        return [
            'id' => 'required|integer|exists:aid_login_ban_rules,id',
        ];
    }
}
