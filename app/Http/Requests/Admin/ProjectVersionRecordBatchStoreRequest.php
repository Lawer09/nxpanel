<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectVersionRecordBatchStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1',
            'items.*.projectId' => 'required|integer|min:1|exists:project_projects,id',
            'items.*.version' => 'required|string|max:100',
            'items.*.versionName' => 'nullable|string|max:191',
            'items.*.content' => 'required|string',
            'items.*.releaseTime' => 'required|date',
            'items.*.remark' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => '版本记录不能为空',
            'items.array' => '版本记录必须为数组',
            'items.min' => '版本记录不能为空',
            'items.*.projectId.required' => '项目不能为空',
            'items.*.projectId.exists' => '项目不存在',
            'items.*.version.required' => '版本不能为空',
            'items.*.version.max' => '版本不能超过100个字符',
            'items.*.versionName.max' => '版本名称不能超过191个字符',
            'items.*.content.required' => '版本内容不能为空',
            'items.*.releaseTime.required' => '上线时间不能为空',
            'items.*.releaseTime.date' => '上线时间格式不正确',
            'items.*.remark.max' => '备注不能超过255个字符',
        ];
    }
}
