<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TicketClose extends FormRequest
{
    public function rules(): array
    {
        return [
            'id' => 'required|integer|exists:v2_ticket,id',
        ];
    }

    public function messages(): array
    {
        return [
            'id.required' => '工单ID不能为空',
            'id.integer' => '工单ID必须为数字',
            'id.exists' => '工单不存在',
        ];
    }
}
