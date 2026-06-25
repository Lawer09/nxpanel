<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id'          => 'required|integer|min:1',
            'projectName' => 'nullable|string|max:100',
            'ownerName'   => 'nullable|string|max:100',
            'status'      => 'nullable|string|in:active,inactive,archived',
            'adStatus'    => 'nullable|string|max:50',
            'appPlatform' => 'nullable|string|max:50',
            'adspowerEnv' => 'nullable|string|max:100',
            'developerGmail' => 'nullable|string|max:191',
            'appName' => 'nullable|string|max:191',
            'packageName' => 'nullable|string|max:191',
            'domainInfoStatus' => 'nullable|string|max:50',
            'admobPubId' => 'nullable|string|max:100',
            'domainUrl' => 'nullable|string|max:255',
            'privacyPolicyUrl' => 'nullable|string|max:255',
            'termsUrl' => 'nullable|string|max:255',
            'facebookInfoStatus' => 'nullable|string|max:50',
            'facebookAppId' => 'nullable|string|max:100',
            'facebookAppToken' => 'nullable|string|max:255',
            'facebookKeyHash' => 'nullable|string|max:255',
            'facebookClassName' => 'nullable|string|max:191',
            'admobAccountStatus' => 'nullable|string|max:50',
            'admobAppId' => 'nullable|string|max:100',
            'admobAdIds' => 'nullable|string',
            'admobAppAdsTxt' => 'nullable|string',
            'firebaseConfigNote' => 'nullable|string',
            'yandexAccount' => 'nullable|string|max:191',
            'yandexAdIds' => 'nullable|string',
            'yandexAppAdsTxt' => 'nullable|string',
            'storePageUrl' => 'nullable|string|max:255',
            'remark'      => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'id.required'     => '项目ID不能为空',
            'projectName.max' => '项目名称不能超过100个字符',
            'status.in'       => '状态格式有误，可选值：active, inactive, archived',
            'adStatus.max'     => '投放状态不能超过50个字符',
            'appPlatform.max'  => '应用平台不能超过50个字符',
            'packageName.max'  => '项目包名不能超过191个字符',
            'developerGmail.max' => '开发者Gmail不能超过191个字符',
            'remark.max'      => '备注不能超过255个字符',
        ];
    }
}
