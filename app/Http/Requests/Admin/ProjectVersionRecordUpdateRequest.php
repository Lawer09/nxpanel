<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectVersionRecordUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'required|integer|min:1|exists:project_version_records,id',
            'projectId' => 'sometimes|required|integer|min:1|exists:project_projects,id',
            'version' => 'sometimes|required|string|max:100',
            'content' => 'sometimes|required|string',
            'releaseTime' => 'sometimes|required|date',
            'remark' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'id.required' => '版本记录ID不能为空',
            'id.exists' => '版本记录不存在',
            'projectId.exists' => '项目不存在',
        ];
    }
}
