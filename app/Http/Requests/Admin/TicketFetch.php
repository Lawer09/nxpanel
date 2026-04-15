<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TicketFetch extends FormRequest
{
    public function rules(): array
    {
        return [
            'id' => 'nullable|integer|exists:tickets,id',
            'status' => 'nullable|integer',
            'reply_status' => 'nullable|array',
            'reply_status.*' => 'integer',
            'email' => 'nullable|email',
            'pageSize' => 'nullable|integer|min:1|max:100',
            'current' => 'nullable|integer|min:1',
            'filter' => 'nullable|array',
            'filter.*.id' => 'required_with:filter|string',
            'filter.*.value' => 'required_with:filter',
            'sort' => 'nullable|array',
            'sort.*.id' => 'required_with:sort|string',
            'sort.*.desc' => 'required_with:sort|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'id.integer' => '工单ID必须为数字',
            'id.exists' => '工单不存在',
            'email.email' => '邮箱格式不正确',
            'pageSize.integer' => 'pageSize必须为数字',
            'pageSize.min' => 'pageSize最小为1',
            'pageSize.max' => 'pageSize最大为100',
            'current.integer' => 'current必须为数字',
            'current.min' => 'current最小为1',
        ];
    }
}
