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
     * POST /admin/provider/instances
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
     *   page         integer  optional  页码，默认 1
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getInstances(Request $request): JsonResponse
    {
        $request->validate([
            'providerId'    => 'required|integer|exists:v2_providers,id',
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
            'page'           => 'nullable|integer|min:1',
        ]);

        $provider = Provider::find($request->integer('providerId'));

        if (empty($provider->driver)) {
            return $this->error([422, '该服务商未配置云驱动（driver），请先完善服务商信息']);
        }

        try {
            $driver = CloudProviderManager::make($provider);
        } catch (\InvalidArgumentException $e) {
            return $this->error([422, $e->getMessage()]);
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
            'page'        => $request->input('page', 1),
        ], fn($v) => $v !== null);

        try {
            $result = $driver->listInstances($filters);
        } catch (OperationNotSupportedException $e) {
            return $this->error([501, $e->getMessage()]);
        } catch (\RuntimeException $e) {
            Log::error('getInstances failed', [
                'provider_id' => $provider->id,
                'driver'      => $provider->driver,
                'error'       => $e->getMessage(),
            ]);
            return $this->error([500, '获取实例列表失败: ' . $e->getMessage()]);
        }

        return $this->ok(array_merge($result, [
            'providerId'   => $provider->id,
            'providerName' => $provider->name,
            'driver'        => $provider->driver,
        ]));
    }

    /**
     * 获取服务商下的云弹性 IP 列表（v3）
     *
     * POST /admin/provider/eips
     *
     * 通过 CloudProviderManager 调用对应驱动的 listElasticIps()，
     * 支持透传驱动过滤参数。
     *
     * Query params:
     *   providerId          integer  required  Provider ID
     *   eipIds              array    optional  按 EIP ID 过滤（最多 100 个）
    *   zoneId              string   optional  可用区 ID（自动换算 regionId）
     *   regionId            string   optional  区域 ID
     *   name                string   optional  EIP 名称
     *   status              string   optional  EIP 状态
     *   isDefault           boolean  optional  是否为默认EIP
     *   privateIpAddress    string   optional  按私网 IP 过滤
     *   ipAddress           string   optional  按 IP 地址过滤
     *   ipAddresses         array    optional  按多个 IP 地址过滤（最多 100 个）
     *   instanceId          string   optional  按实例 ID 过滤
     *   associatedId        string   optional  按关联资源 ID 过滤
     *   cidrIds             array    optional  按 CIDR ID 过滤（最多 100 个）
     *   resourceGroupId     string   optional  资源组 ID
     *   tagKeys             array    optional  按标签键过滤（最多 20 个）
     *   tags                array    optional  按标签过滤，每项含 key/value（最多 20 个）
     *   internetChargeType  string   optional  计费类型
     *   pageSize            integer  optional  每页数量，默认 20
     *   page                integer  optional  页码，默认 1
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getElasticIps(Request $request): JsonResponse
    {
        $request->validate([
            'providerId'         => 'required|integer|exists:v2_providers,id',
            'eipIds'             => 'nullable|array|max:100',
            'eipIds.*'           => 'string',
            'zoneId'             => 'nullable|string',
            'regionId'           => 'nullable|string',
            'name'               => 'nullable|string',
            'status'             => 'nullable|string',
            'isDefault'          => 'nullable|boolean',
            'privateIpAddress'   => 'nullable|string',
            'ipAddress'          => 'nullable|string',
            'ipAddresses'        => 'nullable|array|max:100',
            'ipAddresses.*'      => 'string',
            'instanceId'         => 'nullable|string',
            'associatedId'       => 'nullable|string',
            'cidrIds'            => 'nullable|array|max:100',
            'cidrIds.*'          => 'string',
            'resourceGroupId'    => 'nullable|string',
            'tagKeys'            => 'nullable|array|max:20',
            'tagKeys.*'          => 'string',
            'tags'               => 'nullable|array|max:20',
            'tags.*.key'         => 'required_with:tags|string',
            'tags.*.value'       => 'nullable|string',
            'internetChargeType' => 'nullable|string',
            'pageSize'           => 'nullable|integer|min:1|max:100',
            'page'               => 'nullable|integer|min:1',
        ]);

        $provider = Provider::find($request->integer('providerId'));

        if (empty($provider->driver)) {
            return $this->error([422, '该服务商未配置云驱动（driver），请先完善服务商信息']);
        }

        try {
            $driver = CloudProviderManager::make($provider);
        } catch (\InvalidArgumentException $e) {
            return $this->error([422, $e->getMessage()]);
        }

        $zoneId = $request->input('zoneId');
        $regionId = $request->input('regionId');
        if (!empty($zoneId)) {
            try {
                $zones = $driver->listZones(['zoneIds' => [$zoneId]]);
                $zone = collect($zones['data'] ?? [])->firstWhere('zoneId', $zoneId);
                if (!$zone || empty($zone['regionId'])) {
                    return $this->error([422, 'zoneId 无法匹配 regionId']);
                }
                $regionId = $zone['regionId'];
            } catch (OperationNotSupportedException $e) {
                return $this->error([501, $e->getMessage()]);
            } catch (\RuntimeException $e) {
                Log::error('getZones failed for getElasticIps', [
                    'providerId' => $provider->id,
                    'driver'     => $provider->driver,
                    'zoneId'     => $zoneId,
                    'error'      => $e->getMessage(),
                ]);
                return $this->error([500, '获取可用区失败: ' . $e->getMessage()]);
            }
        }

        $filters = array_filter([
            'eipIds'             => $request->input('eipIds'),
            'regionId'           => $regionId,
            'name'               => $request->input('name'),
            'status'             => $request->input('status'),
            'isDefault'          => $request->input('isDefault'),
            'privateIpAddress'   => $request->input('privateIpAddress'),
            'ipAddress'          => $request->input('ipAddress'),
            'ipAddresses'        => $request->input('ipAddresses'),
            'instanceId'         => $request->input('instanceId'),
            'associatedId'       => $request->input('associatedId'),
            'cidrIds'            => $request->input('cidrIds'),
            'resourceGroupId'    => $request->input('resourceGroupId'),
            'tagKeys'            => $request->input('tagKeys'),
            'tags'               => $request->input('tags'),
            'internetChargeType' => $request->input('internetChargeType'),
            'pageSize'           => $request->input('pageSize', 20),
            'page'            => $request->input('page', 1),
        ], fn($v) => $v !== null);

        try {
            $result = $driver->listElasticIps($filters);
        } catch (OperationNotSupportedException $e) {
            return $this->error([501, $e->getMessage()]);
        } catch (\RuntimeException $e) {
            Log::error('getElasticIps failed', [
                'providerId' => $provider->id,
                'driver'      => $provider->driver,
                'error'       => $e->getMessage(),
            ]);
            return $this->error([500, '获取弹性IP列表失败: ' . $e->getMessage()]);
        }

        return $this->ok(array_merge($result, [
            'providerId'   => $provider->id,
            'providerName' => $provider->name,
            'driver'        => $provider->driver,
        ]));
    }

    public function bindElasticIp(Request $request): JsonResponse
    {
        $request->validate([
            'providerId'        => 'required|integer|exists:v2_providers,id',
            'bindings'          => 'required|array|min:1|max:100',
            'bindings.*.nicId'  => 'required|string',
            'bindings.*.elasticIpId' => 'required|string',
            'bindings.*.privateIpAddress' => 'required|string',
        ]);

        try {
            $provider = Provider::find($request->integer('providerId'));

            if (empty($provider->driver)) {
                return $this->error([422, '该服务商未配置云驱动（driver），请先完善服务商信息']);
            }

            $driver = CloudProviderManager::make($provider);

            $results = [];
            foreach ($request->input('bindings') as $binding) {
                $results[] = $driver->bindElasticIp(
                    $binding['nicId'],
                    $binding['elasticIpId'],
                    $binding['privateIpAddress']
                );
            }
            return $this->ok($results);
        } catch (\InvalidArgumentException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (OperationNotSupportedException $e) {
            return $this->error([501, $e->getMessage()]);
        } catch (\RuntimeException $e) {
            Log::error('bindElasticIp failed', [
                'providerId' => $request->integer('providerId'),
                'driver'      => $provider->driver ?? null,
                'error'       => $e->getMessage(),
            ]);
            return $this->error([500, '绑定弹性IP失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 获取服务商下的密钥对列表（v3）
     *
     * POST /admin/provider/keypairs
     *
     * 通过 CloudProviderManager 调用对应驱动的 listKeyPairs()，
     * 支持透传驱动过滤参数。
     *
     * Query params:
     *   provider_id  integer  required  Provider ID
     *   keyIds       array    optional  按密钥对 ID 过滤
     *   keyName      string   optional  密钥对名称（模糊搜索）
     *   pageSize     integer  optional  每页数量，默认 20，最大 1000
     *   page          integer  optional  页码，默认 1
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getKeyPairs(Request $request): JsonResponse
    {
        $request->validate([
            'providerId' => 'required|integer|exists:v2_providers,id',
            'keyIds'      => 'nullable|array',
            'keyIds.*'    => 'string',
            'keyName'     => 'nullable|string',
            'pageSize'    => 'nullable|integer|min:1|max:1000',
            'page'     => 'nullable|integer|min:1',
        ]);

        $provider = Provider::find($request->integer('providerId'));

        if (empty($provider->driver)) {
            return $this->error([422, '该服务商未配置云驱动（driver），请先完善服务商信息']);
        }

        try {
            $driver = CloudProviderManager::make($provider);
        } catch (\InvalidArgumentException $e) {
            return $this->error([422, $e->getMessage()]);
        }

        $filters = array_filter([
            'keyIds'   => $request->input('keyIds'),
            'keyName'  => $request->input('keyName'),
            'pageSize' => $request->input('pageSize', 20),
            'page'  => $request->input('page', 1),
        ], fn($v) => $v !== null);

        try {
            $result = $driver->listKeyPairs($filters);
        } catch (OperationNotSupportedException $e) {
            return $this->error([501, $e->getMessage()]);
        } catch (\RuntimeException $e) {
            Log::error('getKeyPairs failed', [
                'provider_id' => $provider->id,
                'driver'      => $provider->driver,
                'error'       => $e->getMessage(),
            ]);
            return $this->error([500, '获取密钥对列表失败: ' . $e->getMessage()]);
        }

        return $this->ok(array_merge($result, [
            'provider_id'   => $provider->id,
            'provider_name' => $provider->name,
            'driver'        => $provider->driver,
        ]));
    }

    /**
     * 获取服务商下的可用区列表（v3）
     *
     * POST /admin/provider/zones
     *
     * 通过 CloudProviderManager 调用对应驱动的 describeZones()，
     * 支持透传驱动过滤参数。
     *
     * Query params:
     *   provider_id  integer  required  Provider ID
     *   zoneIds      array    optional  按可用区 ID 过滤
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getZones(Request $request): JsonResponse
    {
        $request->validate([
            'providerId' => 'required|integer|exists:v2_providers,id',
            'zoneIds'     => 'nullable|array',
            'zoneIds.*'   => 'string',
        ]);

        $provider = Provider::find($request->integer('providerId'));

        if (empty($provider->driver)) {
            return $this->error([422, '该服务商未配置云驱动（driver），请先完善服务商信息']);
        }

        try {
            $driver = CloudProviderManager::make($provider);
        } catch (\InvalidArgumentException $e) {
            return $this->error([422, $e->getMessage()]);
        }

        $filters = array_filter([
            'zoneIds' => $request->input('zoneIds'),
        ], fn($v) => $v !== null);

        try {
            $result = $driver->listZones($filters);
        } catch (OperationNotSupportedException $e) {
            return $this->error([501, $e->getMessage()]);
        } catch (\RuntimeException $e) {
            Log::error('getZones failed', [
                'provider_id' => $provider->id,
                'driver'      => $provider->driver,
                'error'       => $e->getMessage(),
            ]);
            return $this->error([500, '获取可用区列表失败: ' . $e->getMessage()]);
        }

        return $this->ok(array_merge($result, [
            'provider_id'   => $provider->id,
            'provider_name' => $provider->name,
            'driver'        => $provider->driver,
        ]));
    }

    /**
     * 获取服务商下的子网列表（v3）
     *
     * POST /admin/provider/subnets
     *
     * 通过 CloudProviderManager 调用对应驱动的 listSubnets()，
     * 支持透传驱动过滤参数。
     *
     * Query params:
     *   provider_id        integer  required  Provider ID
     *   subnetIds          array    optional  子网 ID 列表
     *   name               string   optional  子网名称（模糊搜索）
     *   cidrBlock          string   optional  CIDR 过滤
     *   regionId           string   optional  节点/区域 ID
     *   pageSize           integer  optional  每页数量（1-1000）
     *   page           integer  optional  页码（从 1 开始）
     *   vpcIds             array    optional  VPC ID 列表
     *   dhcpOptionsSetId   string   optional  DHCP 选项集 ID
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getSubnets(Request $request): JsonResponse
    {
        $request->validate([
            'providerId'        => 'required|integer|exists:v2_providers,id',
            'subnetIds'          => 'nullable|array|max:100',
            'subnetIds.*'        => 'string',
            'name'               => 'nullable|string',
            'cidrBlock'          => 'nullable|string',
            'regionId'           => 'nullable|string',
            'pageSize'           => 'nullable|integer|min:1|max:1000',
            'page'            => 'nullable|integer|min:1',
            'vpcIds'             => 'nullable|array|max:100',
            'vpcIds.*'           => 'string',
            'dhcpOptionsSetId'   => 'nullable|string',
        ]);

        $provider = Provider::find($request->integer('providerId'));

        if (empty($provider->driver)) {
            return $this->error([422, '该服务商未配置云驱动（driver），请先完善服务商信息']);
        }

        try {
            $driver = CloudProviderManager::make($provider);
        } catch (\InvalidArgumentException $e) {
            return $this->error([422, $e->getMessage()]);
        }

        $filters = array_filter([
            'subnetIds'        => $request->input('subnetIds'),
            'name'             => $request->input('name'),
            'cidrBlock'        => $request->input('cidrBlock'),
            'regionId'         => $request->input('regionId'),
            'pageSize'         => $request->input('pageSize', 20),
            'page'          => $request->input('page', 1),
            'vpcIds'           => $request->input('vpcIds'),
            'dhcpOptionsSetId' => $request->input('dhcpOptionsSetId'),
        ], fn($v) => $v !== null);

        try {
            $result = $driver->listSubnets($filters);
        } catch (OperationNotSupportedException $e) {
            return $this->error([501, $e->getMessage()]);
        } catch (\RuntimeException $e) {
            Log::error('getSubnets failed', [
                'provider_id' => $provider->id,
                'driver'      => $provider->driver,
                'error'       => $e->getMessage(),
            ]);
            return $this->error([500, '获取子网列表失败: ' . $e->getMessage()]);
        }

        return $this->ok(array_merge($result, [
            'providerId'   => $provider->id,
            'providerName' => $provider->name,
            'driver'        => $provider->driver,
        ]));
    }

    /**
     * 获取服务商下的可用区机型规格（v3）
     *
     * POST /admin/provider/instance-types
     *
     * 通过 CloudProviderManager 调用对应驱动的 listInstanceTypes()，
     * 支持透传驱动过滤参数。
     *
     * Query params:
     *   provider_id   integer  required  Provider ID
     *   zoneId        string   optional  可用区 ID
     *   instanceType  string   optional  实例规格
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getInstanceTypes(Request $request): JsonResponse
    {
        $request->validate([
            'providerId'  => 'required|integer|exists:v2_providers,id',
            'zoneId'       => 'nullable|string',
            'instanceType' => 'nullable|string',
        ]);

        $provider = Provider::find($request->integer('providerId'));

        if (empty($provider->driver)) {
            return $this->error([422, '该服务商未配置云驱动（driver），请先完善服务商信息']);
        }

        try {
            $driver = CloudProviderManager::make($provider);
        } catch (\InvalidArgumentException $e) {
            return $this->error([422, $e->getMessage()]);
        }

        $filters = array_filter([
            'zoneId'       => $request->input('zoneId'),
            'instanceType' => $request->input('instanceType'),
        ], fn($v) => $v !== null);

        try {
            $result = $driver->listInstanceTypes($filters);
        } catch (OperationNotSupportedException $e) {
            return $this->error([501, $e->getMessage()]);
        } catch (\RuntimeException $e) {
            Log::error('getInstanceTypes failed', [
                'providerId' => $provider->id,
                'driver'      => $provider->driver,
                'error'       => $e->getMessage(),
            ]);
            return $this->error([500, '获取机型规格失败: ' . $e->getMessage()]);
        }

        return $this->ok(array_merge($result, [
            'providerId'   => $provider->id,
            'providerName' => $provider->name,
            'driver'        => $provider->driver,
        ]));
    }


    public function createInstance(Request $request): JsonResponse
    {
       $request->validate([
            'providerId'   => 'required|integer|exists:v2_providers,id',
            'zoneId'        => 'required|string',
            'instanceType'  => 'required|string',
            'name'          => 'nullable|string|max:255',
            'instanceCount' => 'nullable|integer|min:1|max:100',
            'subnetId'      => 'required|string',
            'sshKeyId'      => 'required|integer|exists:ssh_keys,id',
        ]);

        try {
            $driver = CloudProviderManager::makeById((int) $request->input('providerId'));

            $instanceCount = (int) $request->input('instanceCount') ?? 1;

            $sshKey = \App\Models\SshKey::findOrFail($request->input('sshKeyId'));

            if (empty($sshKey->provider_key_id)) {
                return $this->error([422, '所选SSH密钥未配置云服务商密钥ID']);
            }

            $params = [
                'zoneId'               => $request->input('zoneId'),
                'imageId'              => 'debian12_20251225',
                'instanceType'         => $request->input('instanceType'),
                'instanceCount'        => $instanceCount,
                'subnetId'             => $request->input('subnetId'),
                'instanceName'         => $request->input('name'),
                'keyId'                => $sshKey->provider_key_id,
                'nicNetworkType'       => 'Auto',
                'systemDisk'           => [
                    'diskCategory'        => 'Basic NVMe SSD',
                    'diskSize'        => 20,
                    'burstingEnabled' => false,
                ],
                'securityGroupId'      => '1604048217236974337',
                'timeZone'             => 'Asia/Shanghai',
                'enableAgent'          => true,
                'enableIpForward'      => true,
                'eipBindType'          => 'FullNat',
                'tags'                 => [
                    'tags' => [
                        ['key' => '深圳产品', 'value' => 'NODE'],
                    ],
                ],
                'marketingOptions'     => [
                    'usePocVoucher' => false,
                ],
                'resourceGroupId'      => 'bebaaa61-ebce-4a7f-8d3b-e11d9afcd459',
            ];

            $overrides = $request->except([
                'providerId', 'zoneId', 'instanceType', 'instanceCount', 'name', 'eips'
            ]);

            if (!empty($overrides)) {
                $params = array_replace_recursive($params, $overrides);
            }

            $result = $driver->createInstance($params);

            Log::debug('createInstance result', [
                'providerId' => $request->input('providerId'),
                'params' => $params,
                'result' => $result,
            ]);
            
            return $this->ok([
                'providerId'   => $request->input('providerId'),
                'instanceIds'        => $result['instanceIds'] ?? [],
                'orderSn'             => $result['orderSn'] ?? null,
            ]);

        } catch (OperationNotSupportedException $e) {
            return $this->error([501, $e->getMessage()]);
        } catch (\RuntimeException $e) {
            Log::error('createSimple failed', [
                'providerId' => $request->input('providerId'),
                'error' => $e->getMessage(),
            ]);
            return $this->error([500, '创建实例失败: ' . $e->getMessage()]);
        }
    }

    public function deleteInstance(Request $request): JsonResponse
    {
        return $this->error([501, '删除实例接口尚未实现']);
    }
}