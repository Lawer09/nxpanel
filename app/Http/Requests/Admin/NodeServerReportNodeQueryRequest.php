<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class NodeServerReportNodeQueryRequest extends FormRequest
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
            'groupBy.*' => 'required|string|in:date,hour,node_id,node_type,node_host,node_public_ip',
            'filters' => 'nullable|array',
            'filters.nodeIds' => 'nullable|array',
            'filters.nodeIds.*' => 'integer',
            'filters.nodeTypes' => 'nullable|array',
            'filters.nodeTypes.*' => 'string|max:32',
            'filters.nodeHosts' => 'nullable|array',
            'filters.nodeHosts.*' => 'string|max:255',
            'filters.nodePublicIps' => 'nullable|array',
            'filters.nodePublicIps.*' => 'string|max:64',
            'page' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
            'orderBy' => 'nullable|string|in:date,hour,node_id,node_type,node_host,node_public_ip,traffic_upload,traffic_download,avg_cpu_usage,avg_mem_usage,max_cpu_usage,max_mem_usage,avg_disk_usage,avg_inbound_speed,avg_outbound_speed,max_inbound_speed,max_outbound_speed,avg_tcp_connections,max_tcp_connections,avg_alive_users,max_alive_users,compute_count,id,created_at,updated_at',
            'orderDirection' => 'nullable|string|in:asc,desc',
        ];
    }
}
