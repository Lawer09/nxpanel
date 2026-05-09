<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UserReportHourlyQueryRequest extends FormRequest
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
            'groupBy.*' => 'required|string|in:date,hour,user_id,app_id,app_version,country',
            'filters' => 'nullable|array',
            'filters.userIds' => 'nullable|array',
            'filters.userIds.*' => 'integer',
            'filters.appIds' => 'nullable|array',
            'filters.appIds.*' => 'string|max:255',
            'filters.appVersions' => 'nullable|array',
            'filters.appVersions.*' => 'string|max:50',
            'filters.countries' => 'nullable|array',
            'filters.countries.*' => 'string|max:16',
            'page' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
            'orderBy' => 'nullable|string|in:date,hour,user_id,app_id,app_version,country,traffic_usage,traffic_use_time,traffic_upload,traffic_download,report_count_user,report_count_node,id,created_at,updated_at',
            'orderDirection' => 'nullable|string|in:asc,desc',
        ];
    }
}
