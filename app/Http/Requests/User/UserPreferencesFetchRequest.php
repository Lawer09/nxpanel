<?php

namespace App\Http\Requests\User;

use App\Services\UserPreferenceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserPreferencesFetchRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $keys = $this->query('keys', $this->input('keys'));
        if (is_string($keys) && $keys !== '') {
            $keys = [$keys];
        }

        if ($keys !== null) {
            $this->merge(['keys' => $keys]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'keys' => 'nullable|array',
            'keys.*' => [
                'required',
                'string',
                'max:191',
                Rule::in(UserPreferenceService::allowedKeys()),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'keys.array' => '偏好配置Key格式不正确',
            'keys.*.in' => '偏好配置Key不允许同步',
        ];
    }
}
