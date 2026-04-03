<?php

namespace App\Services\CloudProvider\Drivers;

use App\Services\CloudProvider\AbstractCloudDriver;

/**
 * 阿里云驱动
 *
 * 文档参考：https://help.aliyun.com/document_detail/25506.html
 */
class AliyunDriver extends AbstractCloudDriver
{
    protected string $driverName = 'aliyun';

    // ----------------------------------------------------------------
    // 实例
    // ----------------------------------------------------------------

    /**
     * 获取实例列表
     *
     * @param  array $filters  支持：RegionId, InstanceIds, Status, PageSize, PageNumber
     */
    public function listInstances(array $filters = []): array
    {
        return $this->call(__FUNCTION__, function () use ($filters) {
            // TODO: 调用阿里云 ECS DescribeInstances API
            // GET https://ecs.aliyuncs.com/?Action=DescribeInstances&RegionId=...
            // 使用 AccessKeyId + AccessKeySecret 签名
            // 参考：https://help.aliyun.com/document_detail/25506.html
            return [];
        });
    }

    // ----------------------------------------------------------------
    // 弹性 IP（EIP）
    // ----------------------------------------------------------------

    /**
     * 查看指定实例绑定的弹性 IP
     *
     * 阿里云通过 DescribeEipAddresses 并过滤 InstanceId 实现
     */
    public function getInstanceElasticIps(string $instanceId): array
    {
        return $this->call(__FUNCTION__, function () use ($instanceId) {
            // TODO: 调用阿里云 VPC DescribeEipAddresses API
            // 参数：AssociatedInstanceId=$instanceId, AssociatedInstanceType=EcsInstance
            // 参考：https://help.aliyun.com/document_detail/36018.html
            return [];
        });
    }

    /**
     * 查看账号下所有弹性 IP 列表
     */
    public function listElasticIps(array $filters = []): array
    {
        return $this->call(__FUNCTION__, function () use ($filters) {
            // TODO: 调用阿里云 VPC DescribeEipAddresses API
            // GET https://vpc.aliyuncs.com/?Action=DescribeEipAddresses&RegionId=...
            // 参考：https://help.aliyun.com/document_detail/36018.html
            return [];
        });
    }

    /**
     * 将弹性 IP 绑定到实例
     *
     * @param  string $instanceId   ECS 实例 ID（如 i-bp1xxxxx）
     * @param  string $elasticIpId  EIP 分配 ID（AllocationId，如 eip-bp1xxxxx）
     */
    public function bindElasticIp(string $instanceId, string $elasticIpId): array
    {
        return $this->call(__FUNCTION__, function () use ($instanceId, $elasticIpId) {
            // TODO: 调用阿里云 VPC AssociateEipAddress API
            // GET https://vpc.aliyuncs.com/?Action=AssociateEipAddress
            //   &AllocationId=$elasticIpId&InstanceId=$instanceId&InstanceType=EcsInstance
            // 参考：https://help.aliyun.com/document_detail/36017.html
            return ['success' => true];
        });
    }

    /**
     * 将弹性 IP 从实例解绑
     *
     * @param  string $instanceId   ECS 实例 ID
     * @param  string $elasticIpId  EIP 分配 ID（AllocationId）
     */
    public function unbindElasticIp(string $instanceId, string $elasticIpId): array
    {
        return $this->call(__FUNCTION__, function () use ($instanceId, $elasticIpId) {
            // TODO: 调用阿里云 VPC UnassociateEipAddress API
            // GET https://vpc.aliyuncs.com/?Action=UnassociateEipAddress
            //   &AllocationId=$elasticIpId&InstanceId=$instanceId&InstanceType=EcsInstance
            // 参考：https://help.aliyun.com/document_detail/36021.html
            return ['success' => true];
        });
    }
}
