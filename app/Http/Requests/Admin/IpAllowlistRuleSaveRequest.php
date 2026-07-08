<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class IpAllowlistRuleSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:191',
            'enabled' => 'nullable|boolean',
            'countries' => 'nullable|array',
            'countries.*' => 'required|string|max:16|distinct',
            'projectCodes' => 'nullable|array',
            'projectCodes.*' => 'required|string|max:100|distinct',
            'packageNames' => 'nullable|array',
            'packageNames.*' => 'required|string|max:191|distinct',
            'reason' => 'nullable|string|max:500',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (
                empty($this->input('countries', []))
                && empty($this->input('projectCodes', []))
                && empty($this->input('packageNames', []))
            ) {
                $validator->errors()->add(
                    'conditions',
                    'At least one of countries, projectCodes, or packageNames is required.'
                );
            }
        });
    }
}
