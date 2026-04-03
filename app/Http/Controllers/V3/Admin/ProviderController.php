<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\V2\Admin\ProviderController as V2ProviderController;
use App\Models\Provider;
use App\Services\CloudProvider\CloudProviderManager;
use App\Services\CloudProvider\OperationNotSupportedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProviderController extends V2ProviderController
{
    /**
     * 获取服务商下的云实例列表（v3）
     *
     * GET /admin/provider/instances?provider_id={id}
     *
     * 通过 CloudProviderManager 调用对应驱动的 listInstances()，
     * 支持透传驱动过滤参数。
     *
     * Query params:
     *   provider_id     integer  required  Provider ID
     *   instanceIds     array    optional  按实例 ID 过滤（最多 100 个）
     *   zoneId          string   optional  可用区 ID
     *   status          string   optional  实例状态
     *   name            string   optional  实例名称（模糊）
     *   ipv4Address     string   optional  按 IPv4 过滤
     *   ipv6Address     string   optional  按 IPv6 过滤
     *   tagKeys         array    optional  按标签键过滤（最多 20 个）
     *   tags            array    optional  按标签过滤，每项含 key/value（最多 20 个）
     *   pageSize        integer  optional  每页数量，默认 20
     *   pageNum         integer  optional  页码，默认 1
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getInstances(Request $request): JsonResponse
    {
        $request->validate([
            'provider_id'    => 'required|integer|exists:v2_providers,id',
            'instanceIds'    => 'nullable|array|max:100',
            'instanceIds.*'  => 'string',
            'zoneId'         => 'nullable|string',
            'status'         => 'nullable|string',
            'name'           => 'nullable|string',
            'ipv4Address'    => 'nullable|string',
            'ipv6Address'    => 'nullable|string',
            'tagKeys'        => 'nullable|array|max:20',
            'tagKeys.*'      => 'string',
            'tags'           => 'nullable|array|max:20',
            'tags.*.key'     => 'required_with:tags|string',
            'tags.*.value'   => 'nullable|string',
            'pageSize'       => 'nullable|integer|min:1|max:100',
            'pageNum'        => 'nullable|integer|min:1',
        ]);

        $provider = Provider::find($request->integer('provider_id'));

        if (empty($provider->driver)) {
            return $this->fail([422, '该服务商未配置云驱动（driver），请先完善服务商信息']);
        }

        try {
            $driver = CloudProviderManager::make($provider);
        } catch (\InvalidArgumentException $e) {
            return $this->fail([422, $e->getMessage()]);
        }

        $filters = array_filter([
            'instanceIds' => $request->input('instanceIds'),
            'zoneId'      => $request->input('zoneId'),
            'status'      => $request->input('status'),
            'name'        => $request->input('name'),
            'ipv4Address' => $request->input('ipv4Address'),
            'ipv6Address' => $request->input('ipv6Address'),
            'tagKeys'     => $request->input('tagKeys'),
            'tags'        => $request->input('tags'),
            'pageSize'    => $request->input('pageSize', 20),
            'pageNum'     => $request->input('pageNum', 1),
        ], fn($v) => $v !== null);

        try {
            $result = $driver->listInstances($filters);
        } catch (OperationNotSupportedException $e) {
            return $this->fail([501, $e->getMessage()]);
        } catch (\RuntimeException $e) {
            Log::error('getInstances failed', [
                'provider_id' => $provider->id,
                'driver'      => $provider->driver,
                'error'       => $e->getMessage(),
            ]);
            return $this->fail([500, '获取实例列表失败: ' . $e->getMessage()]);
        }

        return $this->ok(array_merge($result, [
            'provider_id'   => $provider->id,
            'provider_name' => $provider->name,
            'driver'        => $provider->driver,
        ]));
    }
}
