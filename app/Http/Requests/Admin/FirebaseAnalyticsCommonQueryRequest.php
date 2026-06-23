<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class FirebaseAnalyticsCommonQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Fill Firebase analytics queries with today's full-day time range when no range is provided.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('start_time') || $this->filled('end_time')) {
            return;
        }

        $today = Carbon::today();

        $this->merge([
            'start_time' => $today->copy()->startOfDay()->format('Y-m-d H:i:s'),
            'end_time' => $today->copy()->endOfDay()->format('Y-m-d H:i:s'),
        ]);
    }

    public function rules(): array
    {
        return [
            'start_time'   => 'nullable|date_format:Y-m-d H:i:s',
            'end_time'     => 'nullable|date_format:Y-m-d H:i:s|after_or_equal:start_time',
            'time_field'   => 'nullable|string|in:event_time,received_at',
            'app_id'       => 'nullable|string|max:128',
            'platform'     => 'nullable|string|in:android,ios',
            'app_version'  => 'nullable|string|max:64',
            'user_country' => 'nullable|string|max:16',
            'user_region'  => 'nullable|string|max:64',
            'network_type' => 'nullable|string|in:wifi,cellular',
            'isp'          => 'nullable|string|max:128',
            'asn'          => 'nullable|string|max:32',
            'event_name'   => 'nullable|string|max:64',
            'interval'     => 'nullable|string|in:5m,15m,1h,1d',
        ];
    }
}
