<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class FirebaseReportAppConnectionQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('dateFrom') || $this->filled('dateTo')) {
            return;
        }

        $today = Carbon::today()->toDateString();

        $this->merge([
            'dateFrom' => Carbon::today()->subDays(14)->toDateString(),
            'dateTo' => $today,
        ]);
    }

    public function rules(): array
    {
        return [
            'dateFrom' => 'nullable|date',
            'dateTo' => 'nullable|date',
            'filters' => 'nullable|array',
            'filters.appIds' => 'nullable|array',
            'filters.appIds.*' => 'string|max:128',
            'filters.platforms' => 'nullable|array',
            'filters.platforms.*' => 'string|max:32',
            'filters.appVersions' => 'nullable|array',
            'filters.appVersions.*' => 'string|max:64',
            'groupBy' => 'nullable|array',
            'groupBy.*' => 'string|in:date,appId,app_id,platform,appVersion,app_version',
            'page' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
            'orderBy' => 'nullable|string|in:appId,app_id,date,platform,appVersion,app_version,avgPingMs,avg_ping_ms,clientConnectCount,client_connect_count,successCount,success_count,successRate,success_rate,failCount,fail_count,failRate,fail_rate,cancelRate,cancel_rate,activeUserCount,active_user_count',
            'orderDirection' => 'nullable|string|in:asc,desc',
        ];
    }
}
