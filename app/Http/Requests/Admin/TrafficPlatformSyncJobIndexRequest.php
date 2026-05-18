<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TrafficPlatformSyncJobIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'platformCode' => 'nullable|string|max:50',
            'accountId' => 'nullable|integer|min:1',
            'status' => 'nullable|string|in:running,success,failed',
            'startTime' => 'nullable|date',
            'endTime' => 'nullable|date',
            'page' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
        ];
    }
}
