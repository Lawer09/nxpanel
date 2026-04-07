<?php

namespace App\Services\CloudProvider;

use Illuminate\Support\Facades\Log;

/**
 * 云驱动基类
 *
 * 提供公共逻辑（日志、异常包装）。
 * 所有方法默认抛出 OperationNotSupportedException，
 * 子类只需覆盖自己支持的方法。
 */
abstract class AbstractCloudDriver implements CloudDriverInterface
{
    /** 驱动标识，子类必须定义 */
    protected string $driverName = 'abstract';

    /** API 凭证，由 CloudProviderManager 注入 */
    protected array $credentials = [];

    public function __construct(array $credentials = [])
    {
        $this->credentials = $credentials;
    }

    // ----------------------------------------------------------------
    // 默认实现：全部抛出 OperationNotSupportedException
    // ----------------------------------------------------------------

    public function listInstances(array $filters = []): array
    {
        throw new OperationNotSupportedException('listInstances', $this->driverName);
    }

    public function getInstanceElasticIps(string $instanceId): array
    {
        throw new OperationNotSupportedException('getInstanceElasticIps', $this->driverName);
    }

    public function listElasticIps(array $filters = []): array
    {
        throw new OperationNotSupportedException('listElasticIps', $this->driverName);
    }

    public function bindElasticIp(string $instanceId, string $elasticIpId): array
    {
        throw new OperationNotSupportedException('bindElasticIp', $this->driverName);
    }

    public function unbindElasticIp(string $instanceId, string $elasticIpId): array
    {
        throw new OperationNotSupportedException('unbindElasticIp', $this->driverName);
    }

    // ----------------------------------------------------------------
    // 公共工具方法
    // ----------------------------------------------------------------

    /**
     * 记录驱动操作日志
     */
    protected function log(string $method, array $context = []): void
    {
        Log::debug("[CloudDriver:{$this->driverName}] {$method}", $context);
    }

    /**
     * 包装 API 调用，统一捕获异常并记录日志
     *
     * @param  string   $method   操作名称（用于日志）
     * @param  callable $callback 实际 API 调用
     * @return mixed
     * @throws \RuntimeException
     */
    protected function call(string $method, callable $callback): mixed
    {
        try {
            $this->log($method);
            return $callback();
        } catch (OperationNotSupportedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error("[CloudDriver:{$this->driverName}] {$method} failed", [
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException(
                "[{$this->driverName}] {$method} 调用失败: " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * 获取凭证字段，不存在时抛出异常
     */
    protected function credential(string $key): string
    {
        if (empty($this->credentials[0])) {
            throw new \InvalidArgumentException(
                "驱动 [{$this->driverName}] 缺少凭证字段: {$key}"
            );
        }
        return $this->credentials[0];
    }
}
