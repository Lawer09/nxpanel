<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SyncServerFetch extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status'    => 'nullable|string|in:online,offline,maintenance',
            'page'      => 'nullable|integer|min:1',
            'page_size' => 'nullable|integer|min:1|max:200',
        ];
    }

    public function messages(): array
    {
        return [
            'status.in'        => '状态格式有误，可选值：online, offline, maintenance',
            'page_size.min'    => '每页条数最小为1',
            'page_size.max'    => '每页条数最大为200',
        ];
    }
}
