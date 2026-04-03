<?php

namespace App\Services\CloudProvider;

/**
 * 云服务商驱动统一接口
 *
 * 每个驱动只需实现自己支持的方法。
 * 不支持的方法应抛出 OperationNotSupportedException。
 */
interface CloudDriverInterface
{
    // ----------------------------------------------------------------
    // 实例
    // ----------------------------------------------------------------

    /**
     * 获取实例列表
     *
     * @param  array $filters  可选过滤条件（如 region、status 等）
     * @return array           实例列表，每项结构由驱动自行规范化
     */
    public function listInstances(array $filters = []): array;

    // ----------------------------------------------------------------
    // 弹性 IP
    // ----------------------------------------------------------------

    /**
     * 查看指定实例绑定的弹性 IP
     *
     * @param  string $instanceId  服务商侧实例 ID
     * @return array               弹性 IP 信息列表
     */
    public function getInstanceElasticIps(string $instanceId): array;

    /**
     * 查看账号下所有弹性 IP 列表
     *
     * @param  array $filters  可选过滤条件
     * @return array
     */
    public function listElasticIps(array $filters = []): array;

    /**
     * 将弹性 IP 绑定到实例
     *
     * @param  string $instanceId   服务商侧实例 ID
     * @param  string $elasticIpId  弹性 IP 的 ID 或地址（由驱动决定）
     * @return array                操作结果
     */
    public function bindElasticIp(string $instanceId, string $elasticIpId): array;

    /**
     * 将弹性 IP 从实例解绑
     *
     * @param  string $instanceId   服务商侧实例 ID
     * @param  string $elasticIpId  弹性 IP 的 ID 或地址
     * @return array                操作结果
     */
    public function unbindElasticIp(string $instanceId, string $elasticIpId): array;
}
