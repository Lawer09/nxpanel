<?php

namespace App\Http\Requests\Passport;

use Illuminate\Foundation\Http\FormRequest;

class AuthLoginByAidV3 extends FormRequest
{
    /**
     * Get validation rules for V3 AID login.
     */
    public function rules(): array
    {
        return [
            'aid' => 'required|string|min:1|max:255',
            'metadata' => 'required|array',
            'metadata.app_id' => 'required|string|max:255',
            'metadata.package_name' => 'nullable|string|max:191',
            'metadata.packageName' => 'nullable|string|max:191',
            'metadata.app_version' => 'nullable|string|max:50',
            'metadata.platform' => 'nullable|string|max:100',
            'metadata.brand' => 'nullable|string|max:100',
            'metadata.country' => 'nullable|string|max:100',
            'metadata.city' => 'nullable|string|max:100',
            'metadata.device_id' => 'nullable|string|max:255',
            'metadata.ip' => 'nullable|ip',
            'channel' => 'nullable|array',
            'channel.channel_type' => 'nullable|string|in:paid,organic,unknown',
            'channel.channelType' => 'nullable|string|in:paid,organic,unknown',
            'channel.utm_source' => 'nullable|string|max:255',
            'channel.utm_medium' => 'nullable|string|max:255',
            'channel.utm_campaign' => 'nullable|string|max:255',
            'channel.raw_referrer' => 'nullable|string|max:2048',
            'channel.click_ts' => 'nullable|integer|min:0',
            'channel.install_begin_ts' => 'nullable|integer|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'aid.required' => 'aid参数不能为空',
        ];
    }
}
