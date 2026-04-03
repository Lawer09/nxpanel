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

    private const API_ENDPOINT = 'https://console.zenlayer.com/api/v2/bmc';

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
                'pageNum'         => isset($filters['pageNum'])   ? (int) $filters['pageNum']   : 1,
                'resourceGroupId' => $filters['resourceGroupId'] ?? null,
                'tagKeys'         => $filters['tagKeys']         ?? null,
                'tags'            => $filters['tags']            ?? null,
            ], fn($v) => $v !== null);

            $resp = $this->request('DescribeInstances', $body);

            return [
                'total'   => $resp['totalCount'] ?? 0,
                'pageNum' => $body['pageNum'],
                'pageSize'=> $body['pageSize'],
                'data'    => $this->normalizeInstances($resp['dataSet'] ?? []),
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
                'public_ips'        => array_values(array_unique($publicIps)),
                'private_ips'       => $item['privateIpAddresses'] ?? [],
                'image_id'          => $item['imageId']      ?? null,
                'image_name'        => $item['imageName']    ?? null,
                'create_time'       => $item['createTime']   ?? null,
                'expired_time'      => $item['expiredTime']  ?? null,
                'resource_group_id' => $item['resourceGroupId'] ?? null,
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
     *   eipIds       array   按 EIP ID 过滤
     *   status       string  EIP 状态
     *   ipAddress    string  按 IP 地址过滤
     *   pageSize     int     默认 20
     *   pageNum      int     默认 1
     */
    public function listElasticIps(array $filters = []): array
    {
        return $this->call(__FUNCTION__, function () use ($filters) {
            $body = array_filter([
                'eipIds'   => $filters['eipIds']   ?? null,
                'status'   => $filters['status']   ?? null,
                'ipAddress'=> $filters['ipAddress']?? null,
                'pageSize' => isset($filters['pageSize']) ? (int) $filters['pageSize'] : 20,
                'pageNum'  => isset($filters['pageNum'])  ? (int) $filters['pageNum']  : 1,
            ], fn($v) => $v !== null);

            $resp = $this->request('DescribeEipAddresses', $body);

            return [
                'total'   => $resp['totalCount'] ?? 0,
                'pageNum' => $body['pageNum']  ?? 1,
                'pageSize'=> $body['pageSize'] ?? 20,
                'data'    => $this->normalizeEips($resp['dataSet'] ?? []),
            ];
        });
    }

    /**
     * 将弹性 IP 绑定到实例
     *
     * @param  string $instanceId   Zenlayer 实例 ID
     * @param  string $elasticIpId  Zenlayer EIP ID（eipId）
     */
    public function bindElasticIp(string $instanceId, string $elasticIpId): array
    {
        return $this->call(__FUNCTION__, function () use ($instanceId, $elasticIpId) {
            $this->request('AssociateEipAddress', [
                'instanceId' => $instanceId,
                'eipId'      => $elasticIpId,
            ]);
            return ['success' => true, 'instance_id' => $instanceId, 'eip_id' => $elasticIpId];
        });
    }

    /**
     * 将弹性 IP 从实例解绑
     *
     * @param  string $instanceId   Zenlayer 实例 ID
     * @param  string $elasticIpId  Zenlayer EIP ID（eipId）
     */
    public function unbindElasticIp(string $instanceId, string $elasticIpId): array
    {
        return $this->call(__FUNCTION__, function () use ($instanceId, $elasticIpId) {
            $this->request('DisassociateEipAddress', [
                'instanceId' => $instanceId,
                'eipId'      => $elasticIpId,
            ]);
            return ['success' => true, 'instance_id' => $instanceId, 'eip_id' => $elasticIpId];
        });
    }

    // ----------------------------------------------------------------
    // 内部工具
    // ----------------------------------------------------------------

    private function normalizeEips(array $dataSet): array
    {
        return array_map(fn(array $item) => [
            'eip_id'      => $item['eipId']      ?? null,
            'ip_address'  => $item['ipAddress']  ?? null,
            'status'      => $item['status']      ?? null,
            'instance_id' => $item['instanceId'] ?? null,
            'zone_id'     => $item['zoneId']     ?? null,
            'create_time' => $item['createTime'] ?? null,
            '_raw'        => $item,
        ], $dataSet);
    }
}
