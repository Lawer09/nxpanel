<?php

namespace App\Services\CloudProvider\Drivers;

use App\Services\CloudProvider\AbstractCloudDriver;
use Illuminate\Support\Facades\Http;

/**
 * Zenlayer 云驱动
 *
 * 认证方式：Bearer Token（用户访问令牌）
 * 端点：POST https://console.zenlayer.com/api/v2/bmc
 * 操作通过请求头 X-ZC-Action 区分，Content-Type: application/json
 *
 * 凭证字段（api_credentials）：
 *   - access_token : 用户访问令牌（User Access Token）
 *
 * 文档参考：https://docs.zenlayer.com/
 */
class ZenlayerDriver extends AbstractCloudDriver
{
    protected string $driverName = 'zenlayer';

    private const API_ENDPOINT = 'https://console.zenlayer.com/api/v2/zec'; # 弹性实例

    // ----------------------------------------------------------------
    // 核心 HTTP 客户端
    // ----------------------------------------------------------------

    /**
     * 向 Zenlayer BMC API 发送请求
     *
     * 认证：Authorization: Bearer {access_token}
     * 操作：X-ZC-Action 请求头
     *
     * @param  string $action  X-ZC-Action 值，如 DescribeInstances
     * @param  array  $body    请求体（JSON）
     * @return array           response 字段内容
     */
    private function request(string $action, array $body = []): array
    {
        $token = $this->credential('access_token');

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-ZC-Action'  => $action,
            'Authorization'=> 'Bearer ' . $token,
        ])->post(self::API_ENDPOINT, empty($body) ? new \stdClass() : $body);

        $json = $response->json();

        if (!$response->successful()) {
            $msg = $json['response']['error']['message']
                ?? $json['message']
                ?? "HTTP {$response->status()}";
            throw new \RuntimeException("Zenlayer API [{$action}] 失败: {$msg}", $response->status());
        }

        // 检查业务层错误
        if (isset($json['response']['error'])) {
            $err = $json['response']['error'];
            throw new \RuntimeException(
                "Zenlayer API [{$action}] 错误: " . ($err['message'] ?? json_encode($err)),
                (int) ($err['code'] ?? 0)
            );
        }

        return $json['response'] ?? $json;
    }

    // ----------------------------------------------------------------
    // 实例
    // ----------------------------------------------------------------

    /**
     * 获取实例列表
     *
     * 支持的 $filters 键：
     *   instanceIds    array    按实例 ID 过滤（最多 100 个）
     *   zoneId         string   可用区 ID
     *   imageId        string   镜像 ID
     *   ipv4Address    string   按 IPv4 过滤
     *   ipv6Address    string   按 IPv6 过滤
     *   status         string   实例状态（RUNNING / STOPPED 等）
     *   name           string   实例名称（支持模糊搜索）
     *   pageSize       int      每页数量，默认 20
     *   pageNum        int      页码，默认 1
     *   resourceGroupId string  资源组 ID
     *   tagKeys        array    标签键列表
     *   tags           array    标签列表
     */
    public function listInstances(array $filters = []): array
    {
        return $this->call(__FUNCTION__, function () use ($filters) {
            $body = array_filter([
                'instanceIds'     => $filters['instanceIds']     ?? null,
                'zoneId'          => $filters['zoneId']          ?? null,
                'imageId'         => $filters['imageId']         ?? null,
                'ipv4Address'     => $filters['ipv4Address']     ?? null,
                'ipv6Address'     => $filters['ipv6Address']     ?? null,
                'status'          => $filters['status']          ?? null,
                'name'            => $filters['name']            ?? null,
                'pageSize'        => isset($filters['pageSize'])  ? (int) $filters['pageSize']  : 20,
                'pageNum'         => isset($filters['page'])   ? (int) $filters['page']   : 1,
                'resourceGroupId' => $filters['resourceGroupId'] ?? null,
                'tagKeys'         => $filters['tagKeys']         ?? null,
                'tags'            => $filters['tags']            ?? null,
            ], fn($v) => $v !== null);

            $resp = $this->request('DescribeInstances', $body);

            return [
                'total'   => $resp['totalCount'] ?? 0,
                'page' => $body['pageNum'],
                'pageSize'=> $body['pageSize'],
                'data'    => $this->normalizeInstances($resp['dataSet'] ?? []),
            ];
        });
    }

    /**
     * 创建实例（CreateZecInstances）
     *
     * @param  array $params 创建参数（透传 Zenlayer CreateZecInstances 参数）
     * @return array
     */
    public function createInstance(array $params): array
    {
        return $this->call(__FUNCTION__, function () use ($params) {
            $resp = $this->request('CreateZecInstances', $params);

            return [
                'orderSn'       => $resp['orderNumber'] ?? null,
                'instanceIds' => $resp['instanceIdSet'] ?? [],
                'requestId'     => $resp['requestId'] ?? null,
                '_raw'          => $resp,
            ];
        });
    }

    /**
     * 规范化实例数据，提取关键字段
     */
    private function normalizeInstances(array $dataSet): array
    {
        return array_map(function (array $item) {
            // 提取公网 IP（优先 publicIpAddresses，其次 nics 中的 publicIpList）
            $publicIps = $item['publicIpAddresses'] ?? [];
            if (empty($publicIps)) {
                foreach ($item['nics'] ?? [] as $nic) {
                    $publicIps = array_merge($publicIps, $nic['publicIpList'] ?? []);
                }
            }

            return [
                'instance_id'       => $item['instanceId']   ?? null,
                'name'              => $item['instanceName'] ?? null,
                'status'            => $item['status']       ?? null,
                'zone_id'           => $item['zoneId']       ?? null,
                'instance_type'     => $item['instanceType'] ?? null,
                'cpu'               => $item['cpu']          ?? null,
                'memory'            => $item['memory']       ?? null,
                'disk'              => $item['systemDisk']['diskSize'] ?? null,
                'public_ips'        => array_values(array_unique($publicIps)),
                'private_ips'       => $item['privateIpAddresses'] ?? [],
                'image_id'          => $item['imageId']      ?? null,
                'image_name'        => $item['imageName']    ?? null,
                'create_time'       => $item['createTime']   ?? null,
                'expired_time'      => $item['expiredTime']  ?? null,
                'resource_group_id' => $item['resourceGroupId'] ?? null,
                'nic_id'            => $item['nics'][0]['nicId'] ?? null, // 取第一个网卡的 ID 作为代表
                '_raw'              => $item,
            ];
        }, $dataSet);
    }

    // ----------------------------------------------------------------
    // 弹性 IP
    // ----------------------------------------------------------------

    /**
     * 查看指定实例绑定的弹性 IP
     *
     * 通过 DescribeEipAddresses 并按 instanceId 过滤实现
     */
    public function getInstanceElasticIps(string $instanceId): array
    {
        return $this->call(__FUNCTION__, function () use ($instanceId) {
            $resp = $this->request('DescribeEipAddresses', [
                'instanceId' => $instanceId,
            ]);
            return $this->normalizeEips($resp['dataSet'] ?? []);
        });
    }

    /**
     * 查看账号下所有弹性 IP 列表
     *
     * 支持的 $filters 键：
     *   eipIds             array   按 EIP ID 过滤
     *   regionId           string  区域ID
     *   name               string  EIP 名称
     *   status             string  EIP 状态
     *   isDefault          boolean 是否为默认EIP
     *   pageSize           int     默认 20
     *   pageNum            int     默认 1
     *   privateIpAddress   string  按私网IP过滤
     *   ipAddress          string  按IP地址过滤
     *   ipAddresses        array   按多个IP地址过滤
     *   instanceId         string  按实例ID过滤
     *   associatedId       string  按关联资源ID过滤
     *   cidrIds            array   按CIDR ID过滤
     *   resourceGroupId    string  资源组ID
     *   tagKeys            array   标签键列表
     *   tags               array   标签列表
     *   internetChargeType string  计费类型
     */
    public function listElasticIps(array $filters = []): array
    {
        return $this->call(__FUNCTION__, function () use ($filters) {
            $body = array_filter([
                'eipIds'             => $filters['eipIds']             ?? null,
                'regionId'           => $filters['regionId']           ?? null,
                'name'               => $filters['name']               ?? null,
                'status'             => $filters['status']             ?? null,
                'isDefault'          => $filters['isDefault']          ?? null,
                'privateIpAddress'   => $filters['privateIpAddress']   ?? null,
                'ipAddress'          => $filters['ipAddress']          ?? null,
                'ipAddresses'        => $filters['ipAddresses']        ?? null,
                'instanceId'         => $filters['instanceId']         ?? null,
                'associatedId'       => $filters['associatedId']       ?? null,
                'cidrIds'            => $filters['cidrIds']            ?? null,
                'resourceGroupId'    => $filters['resourceGroupId']    ?? null,
                'tagKeys'            => $filters['tagKeys']            ?? null,
                'tags'               => $filters['tags']               ?? null,
                'internetChargeType' => $filters['internetChargeType'] ?? null,
                'pageSize'           => isset($filters['pageSize']) ? (int) $filters['pageSize'] : 20,
                'pageNum'            => isset($filters['page'])  ? (int) $filters['page']  : 1,
            ], fn($v) => $v !== null);

            $resp = $this->request('DescribeEips', $body);

            return [
                'total'   => $resp['totalCount'] ?? 0,
                'page' => $body['pageNum']  ?? 1,
                'pageSize'=> $body['pageSize'] ?? 20,
                'data'    => $this->normalizeEips($resp['dataSet'] ?? []),
            ];
        });
    }

    /**
     * 将弹性 IP 绑定到实例
     *
     * @param  string $nicId   网卡 ID
     * @param  string $elasticIpId  Zenlayer EIP ID（eipId）
     * @param  string $private_ip_address  实例的内网 IP 地址   
     */
    public function bindElasticIp(string $nicId, string $elasticIpId, string $private_ip_address): array
    {
        return $this->call(__FUNCTION__, function () use ($nicId, $elasticIpId, $private_ip_address) {
            $this->request('AssociateEipAddress', [
                'nicId' => $nicId,
                'eipIds'      => [$elasticIpId],
                'lanIp' => $private_ip_address,
            ]);
            return ['success' => true, 'nic_id' => $nicId, 'eip_id' => $elasticIpId];
        });
    }

    /**
     * 将弹性 IP 从实例解绑
     *
     * @param  string $elasticIpId  Zenlayer EIP ID（eipId）
     */
    public function unbindElasticIp(string $elasticIpId): array
    {
        return $this->call(__FUNCTION__, function () use ($elasticIpId) {
            $this->request('UnassociateEipAddress', [
                'eipIds'      => [$elasticIpId],
            ]);
            return ['success' => true, 'eip_id' => $elasticIpId];
        });
    }

    /**
     * 配置弹性 IP 作为出口 IP（Zenlayer ConfigEipEgressIp）
     *
     * @param  string $eipId  Zenlayer EIP ID
     * @return array          包含 requestId
     */
    public function configEipEgress(string $eipId): array
    {
        return $this->call(__FUNCTION__, function () use ($eipId) {
            $resp = $this->request('ConfigEipEgressIp', [
                'eipId' => $eipId,
            ]);
            return [
                'success' => true,
                'eip_id' => $eipId,
                'requestId' => $resp['requestId'] ?? null,
            ];
        });
    }

    /**
     * 获取密钥对列表
     *
     * @param  string filters 可选过滤条件
     */
    public function listKeyPairs(array $filters = []): array
    {
        return $this->call(__FUNCTION__, function () use ($filters) {
            $body = array_filter([
                'keyIds'   => $filters['keyIds']   ?? null,
                'keyName'  => $filters['keyName']  ?? null,
                'pageSize' => isset($filters['pageSize']) ? (int) $filters['pageSize'] : 20,
                'pageNum'  => isset($filters['page'])  ? (int) $filters['page']  : 1,
            ], fn($v) => $v !== null);

            $resp = $this->request('DescribeKeyPairs', $body);

            return [
                'total'    => $resp['totalCount'] ?? 0,
                'page'  => $body['pageNum'] ?? 1,
                'pageSize' => $body['pageSize'] ?? 20,
                'data'     => $this->normalizeKeyPairs($resp['dataSet'] ?? []),
            ];
        });
    }

    /**
     * 获取可用区列表
     *
     * 支持的 $filters 键：
     *   zoneIds  array  按可用区 ID 过滤
     */
    public function listZones(array $filters = []): array
    {
        return $this->call(__FUNCTION__, function () use ($filters) {
            $body = array_filter([
                'zoneIds' => $filters['zoneIds'] ?? null,
            ], fn($v) => $v !== null);

            $resp = $this->request('DescribeZones', $body);

            return [
                'requestId' => $resp['requestId'] ?? null,
                'data'   => $this->normalizeZones($resp['zoneSet'] ?? []),
            ];
        });
    }

    /**
     * 获取子网列表
     *
     * 支持的 $filters 键：
     *   subnetIds        array   子网 ID 列表
     *   name             string  子网名称（模糊搜索）
     *   cidrBlock        string  CIDR 过滤
     *   regionId         string  节点/区域 ID
     *   pageSize         int     每页数量（1-1000）
     *   pageNum          int     页码（从 1 开始）
     *   vpcIds           array   VPC ID 列表
     *   dhcpOptionsSetId string  DHCP 选项集 ID
     */
    public function listSubnets(array $filters = []): array
    {
        return $this->call(__FUNCTION__, function () use ($filters) {
            $body = array_filter([
                'subnetIds'        => $filters['subnetIds']        ?? null,
                'name'             => $filters['name']             ?? null,
                'cidrBlock'        => $filters['cidrBlock']        ?? null,
                'regionId'         => $filters['regionId']         ?? null,
                'pageSize'         => isset($filters['pageSize']) ? (int) $filters['pageSize'] : 20,
                'pageNum'          => isset($filters['page'])  ? (int) $filters['page']  : 1,
                'vpcIds'           => $filters['vpcIds']           ?? null,
                'dhcpOptionsSetId' => $filters['dhcpOptionsSetId'] ?? null,
            ], fn($v) => $v !== null);

            $resp = $this->request('DescribeSubnets', $body);

            return [
                'requestId'  => $resp['requestId'] ?? null,
                'total' => $resp['totalCount'] ?? 0,
                'page'    => $body['pageNum'] ?? 1,
                'pageSize'   => $body['pageSize'] ?? 20,
                'data'    => $this->normalizeSubnets($resp['dataSet'] ?? []),
            ];
        });
    }

    /**
     * 获取可用区机型规格信息
     *
     * 支持的 $filters 键：
     *   zoneId       string  可用区 ID
     *   instanceType string  实例规格
     */
    public function listInstanceTypes(array $filters = []): array
    {
        return $this->call(__FUNCTION__, function () use ($filters) {
            $body = array_filter([
                'zoneId'       => $filters['zoneId'] ?? null,
                'instanceType' => $filters['instanceType'] ?? null,
            ], fn($v) => $v !== null);

            $resp = $this->request('DescribeZoneInstanceConfigInfos', $body);

            return [
                'requestId'            => $resp['requestId'] ?? null,
                'data' => $this->normalizeInstanceTypes($resp['instanceTypeQuotaSet'] ?? []),
            ];
        });
    }

    // ----------------------------------------------------------------
    // 内部工具
    // ----------------------------------------------------------------

    private function normalizeEips(array $dataSet): array
    {
        return array_map(function (array $item) {
            $metadata = $item;

            unset(
                $metadata['eipId'],
                $metadata['publicIpAddresses'],
                $metadata['status'],
                $metadata['instanceId'],
                $metadata['zoneId'],
                $metadata['createTime']
            );

            return [
                'eipId'      => $item['eipId']      ?? null,
                'ipAddress'  => $item['publicIpAddresses']  ?? null,
                'status'      => $item['status']      ?? null,
                'instanceId' => $item['instanceId'] ?? null,
                'zoneId'     => $item['zoneId']     ?? null,
                'createTime' => $item['createTime'] ?? null,
                'metadata'    => empty($metadata) ? null : $metadata,
                '_raw'        => $item,
            ];
        }, $dataSet);
    }

    /**
     * 规范化密钥对数据
     */
    private function normalizeKeyPairs(array $dataSet): array
    {
        return array_map(function (array $item) {
            return [
                'keyId'          => $item['keyId']          ?? null,
                'keyName'        => $item['keyName']        ?? null,
                'keyDescription' => $item['keyDescription'] ?? null,
                'publicKey'      => $item['publicKey']      ?? null,
                'createTime'     => $item['createTime']     ?? null,
                '_raw'            => $item,
            ];
        }, $dataSet);
    }

    /**
     * 规范化可用区数据
     */
    private function normalizeZones(array $zoneSet): array
    {
        return array_map(function (array $item) {
            return [
                'zoneId'               => $item['zoneId']              ?? null,
                'zoneName'             => $item['zoneName']            ?? null,
                'regionId'             => $item['regionId']            ?? null,
                'supportSecurityGroup'=> $item['supportSecurityGroup']?? null,
                '_raw'                  => $item,
            ];
        }, $zoneSet);
    }

    /**
     * 规范化子网数据
     */
    private function normalizeSubnets(array $dataSet): array
    {
        return array_map(function (array $item) {
            return [
                'subnetId'            => $item['subnetId']          ?? null,
                'regionId'            => $item['regionId']          ?? ($item['regionid'] ?? null),
                'name'                 => $item['name']              ?? null,
                'cidrBlock'           => $item['cidrBlock']         ?? null,
                'gatewayIpAddress'   => $item['gatewayIpAddress']  ?? null,
                'ipv6GatewayIpAddress'      => $item['ipv6GatewayIpAddress'] ?? null,
                'ipv6CidrBlock'      => $item['ipv6CidrBlock']     ?? null,
                'stackType'           => $item['stackType']         ?? null,
                'ipv6Type'            => $item['ipv6Type']          ?? null,
                'vpcId'               => $item['vpcId']             ?? null,
                'vpcName'             => $item['vpcName']           ?? null,
                'usageIpv4Count'     => $item['usageIpv4Count']    ?? null,
                'usageIpv6Count'     => $item['usageIpv6Count']    ?? null,
                'createTime'          => $item['createTime']        ?? null,
                'isDefault'           => $item['isDefault']         ?? null,
                'dhcpOptionsSetId'  => $item['dhcpOptionsSetId']  ?? null,
                '_raw'                 => $item,
            ];
        }, $dataSet);
    }

    /**
     * 规范化机型规格数据
     */
    private function normalizeInstanceTypes(array $dataSet): array
    {
        return array_map(function (array $item) {
            return [
                'zoneId'                         => $item['zoneId'] ?? null,
                'instanceType'                   => $item['instanceType'] ?? null,
                'cpuCount'                       => $item['cpuCount'] ?? null,
                'memory'                          => $item['memory'] ?? null,
                'withStock'                      => $item['withStock'] ?? null,
                'internetMaxBandwidthOutLimit'=> $item['internetMaxBandwidthOutLimit'] ?? null,
                'instanceTypeName'              => $item['instanceTypeName'] ?? null,
                'internetChargeTypes'           => $item['internetChargeTypes'] ?? null,
                '_raw'                            => $item,
            ];
        }, $dataSet);
    }
}
