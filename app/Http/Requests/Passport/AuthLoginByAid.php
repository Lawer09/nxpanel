<?php

namespace App\Http\Requests\Passport;

use Illuminate\Foundation\Http\FormRequest;

class AuthLoginByAid extends FormRequest
{
    /**
     * Get validation rules for V1/V2 AID login.
     */
    public function rules(): array
    {
        return [
            'aid' => 'required|string|min:1|max:255',
            'metadata' => 'nullable|array',
            'metadata.app_id' => 'nullable|string|max:255',
            'metadata.package_name' => 'nullable|string|max:191',
            'metadata.packageName' => 'nullable|string|max:191',
            'metadata.app_version' => 'nullable|string|max:50',
            'metadata.platform' => 'nullable|string|max:100',
            'metadata.brand' => 'nullable|string|max:100',
            'metadata.country' => 'nullable|string|max:100',
            'metadata.city' => 'nullable|string|max:100',
            'metadata.device_id' => 'nullable|string|max:255',
            'metadata.ip' => 'nullable|ip',
            'metadata.channel' => 'nullable|string|max:100',
            'metadata.channelType' => 'nullable|string|in:paid,organic,unknown',
            'metadata.channel_type' => 'nullable|string|in:paid,organic,unknown',
            'metadata.utm_source' => 'nullable|string|max:255',
            'metadata.utm_medium' => 'nullable|string|max:255',
            'metadata.utm_campaign' => 'nullable|string|max:255',
            'metadata.raw_referrer' => 'nullable|string|max:2048',
            'metadata.click_ts' => 'nullable|integer|min:0',
            'metadata.install_begin_ts' => 'nullable|integer|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'aid.required' => 'aid参数不能为空',
        ];
    }
}
