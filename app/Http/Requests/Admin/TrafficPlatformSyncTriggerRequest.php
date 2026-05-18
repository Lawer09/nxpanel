<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TrafficPlatformSyncTriggerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'accountId' => 'required|integer|min:1',
            'platformCode' => 'nullable|string|max:50',
            'startDate' => 'required|date',
            'endDate' => 'required|date',
        ];
    }
}
