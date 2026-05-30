<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class FirebaseReportUserSummaryQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dateFrom' => 'nullable|date',
            'dateTo' => 'nullable|date',
            'hourFrom' => 'nullable|integer|min:0|max:23',
            'hourTo' => 'nullable|integer|min:0|max:23',
            'groupBy' => 'nullable|array',
            'groupBy.*' => 'required|string|in:date,hour,app_id,app_version,platform,country,network_type',
            'filters' => 'nullable|array',
            'filters.appIds' => 'nullable|array',
            'filters.appIds.*' => 'string|max:128',
            'filters.appVersions' => 'nullable|array',
            'filters.appVersions.*' => 'string|max:64',
            'filters.platforms' => 'nullable|array',
            'filters.platforms.*' => 'string|max:32',
            'filters.countries' => 'nullable|array',
            'filters.countries.*' => 'string|max:16',
            'filters.networkTypes' => 'nullable|array',
            'filters.networkTypes.*' => 'string|max:32',
            'page' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
            'orderBy' => 'nullable|string|in:date,hour,app_id,app_version,platform,country,network_type,new_user_count,active_device_count,dau_device_count,event_count,id,created_at,updated_at',
            'orderDirection' => 'nullable|string|in:asc,desc',
        ];
    }
}
