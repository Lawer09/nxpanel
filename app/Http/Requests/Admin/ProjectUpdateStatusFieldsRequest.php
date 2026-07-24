<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProjectUpdateStatusFieldsRequest extends FormRequest
{
    private const STATUS_FIELDS = [
        'status',
        'adStatus',
        'domainInfoStatus',
        'facebookInfoStatus',
        'admobAccountStatus',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate project status field updates from application clients.
     */
    public function rules(): array
    {
        return [
            'id' => 'nullable|required_without:projectCode|integer|min:1',
            'projectCode' => 'nullable|required_without:id|string|max:100',
            'status' => 'sometimes|required|string|in:active,inactive,archived',
            'adStatus' => 'sometimes|nullable|string|max:50',
            'domainInfoStatus' => 'sometimes|nullable|string|max:50',
            'facebookInfoStatus' => 'sometimes|nullable|string|max:50',
            'admobAccountStatus' => 'sometimes|nullable|string|max:50',
        ];
    }

    /**
     * Ensure at least one status-related field is present.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $payload = $this->all();
            foreach (self::STATUS_FIELDS as $field) {
                if (array_key_exists($field, $payload)) {
                    return;
                }
            }

            $validator->errors()->add('statusFields', '至少需要传入一个状态相关字段');
        });
    }

    public function messages(): array
    {
        return [
            'id.required_without' => '项目ID和项目代号至少需要传入一个',
            'id.integer' => '项目ID必须为整数',
            'id.min' => '项目ID必须大于0',
            'projectCode.required_without' => '项目ID和项目代号至少需要传入一个',
            'projectCode.max' => '项目代号不能超过100个字符',
            'status.required' => '状态不能为空',
            'status.in' => '状态格式有误，可选值：active, inactive, archived',
            'adStatus.max' => '投放状态不能超过50个字符',
            'domainInfoStatus.max' => '域名信息状态不能超过50个字符',
            'facebookInfoStatus.max' => 'FB信息状态不能超过50个字符',
            'admobAccountStatus.max' => 'Admob账号状态不能超过50个字符',
        ];
    }
}
