<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectAdAccountStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'projectId'           => 'required|integer',
            'adPlatformAccountId' => 'required|integer',
            'platformCode'        => 'required|string|max:50',
            'externalAppId'       => 'nullable|string|max:100',
            'externalAdUnitId'    => 'nullable|string|max:100',
            'bindType'            => 'nullable|string|in:account,app,ad_unit',
            'enabled'             => 'nullable|integer|in:0,1',
            'remark'              => 'nullable|string|max:255',
        ];
    }
}
