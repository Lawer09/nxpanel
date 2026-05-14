<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectTrafficAccountUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id'               => 'required|integer|min:1',
            'projectId'        => 'required|integer|min:1',
            'externalUid'      => 'nullable|string|max:100',
            'externalUsername'  => 'nullable|string|max:100',
            'bindType'         => 'nullable|string|in:account,sub_account',
            'enabled'          => 'nullable|integer|in:0,1',
            'remark'           => 'nullable|string|max:255',
        ];
    }
}
