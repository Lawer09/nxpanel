<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class TgBotSayRequest extends FormRequest
{
    /**
     * 允许应用侧请求访问。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 校验发言请求参数。
     */
    public function rules(): array
    {
        return [
            'receiveAt' => ['required', 'integer', 'min:0'],
            'content' => ['required', 'string', 'max:5000'],
        ];
    }
}
