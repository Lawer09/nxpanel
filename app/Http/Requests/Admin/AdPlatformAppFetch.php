<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdPlatformAppFetch extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sourcePlatform' => 'nullable|string|max:32',
            'accountId'      => 'nullable|integer',
            'keyword'        => 'nullable|string|max:128',
            'page'           => 'nullable|integer|min:1',
            'pageSize'       => 'nullable|integer|min:1|max:200',
        ];
    }

    public function messages(): array
    {
        return [
            'pageSize.min' => '每页条数最小为1',
            'pageSize.max' => '每页条数最大为200',
        ];
    }
}
