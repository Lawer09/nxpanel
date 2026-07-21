<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectVersionRecordStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'projectId' => 'required|integer|min:1|exists:project_projects,id',
            'version' => 'required|string|max:100',
            'versionName' => 'nullable|string|max:191',
            'content' => 'required|string',
            'releaseTime' => 'required|date',
            'remark' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'projectId.required' => '项目不能为空',
            'projectId.exists' => '项目不存在',
            'version.required' => '版本不能为空',
            'content.required' => '版本内容不能为空',
            'releaseTime.required' => '上线时间不能为空',
        ];
    }
}
