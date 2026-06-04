<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SendWebhookTaskIndexRequest extends FormRequest
{
    /**
     * 仅允许已通过后台鉴权的请求进入参数校验。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 校验 send_webhook 诊断接口查询参数。
     */
    public function rules(): array
    {
        return [
            'sampleLimit' => 'nullable|integer|min:1|max:50',
            'failedPage' => 'nullable|integer|min:1|max:100000',
            'failedPageSize' => 'nullable|integer|min:1|max:100',
        ];
    }
}
