<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectTrafficAccountStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'projectId'                => 'required|integer',
            'trafficPlatformAccountId' => 'required|integer',
            'platformCode'             => 'required|string|max:50',
            'externalUid'              => 'nullable|string|max:100',
            'externalUsername'         => 'nullable|string|max:100',
            'bindType'                 => 'nullable|string|in:account,sub_account',
            'enabled'                  => 'nullable|integer|in:0,1',
            'remark'                   => 'nullable|string|max:255',
        ];
    }
}
