<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TicketReply extends FormRequest
{
    public function rules(): array
    {
        return [
            'id' => 'required|integer|exists:tickets,id',
            'message' => 'required|string',
        ];
    }

    public function messages(): array
    {
        return [
            'id.required' => '工单ID不能为空',
            'id.integer' => '工单ID必须为数字',
            'id.exists' => '工单不存在',
            'message.required' => '消息不能为空',
        ];
    }
}
