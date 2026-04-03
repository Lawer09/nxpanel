<?php

namespace App\Services\CloudProvider;

use App\Models\Provider;
use App\Services\CloudProvider\Drivers\AliyunDriver;
use App\Services\CloudProvider\Drivers\ZenlayerDriver;

/**
 * 云驱动管理器
 *
 * 根据 Provider 模型的 driver 字段实例化对应驱动，
 * 并将解密后的 api_credentials 注入驱动。
 *
 * 用法：
 *   $driver = CloudProviderManager::make($provider);
 *   $instances = $driver->listInstances();
 *
 * 或通过 Provider ID：
 *   $driver = CloudProviderManager::makeById(3);
 */
class CloudProviderManager
{
    /** 已注册的驱动映射 driver标识 => 驱动类 */
    private static array $drivers = [
        'zenlayer' => ZenlayerDriver::class,
        'aliyun'   => AliyunDriver::class,
    ];

    /**
     * 根据 Provider 模型实例化驱动
     *
     * @param  Provider $provider
     * @return CloudDriverInterface
     * @throws \InvalidArgumentException  驱动未注册
     */
    public static function make(Provider $provider): CloudDriverInterface
    {
        $driverKey   = $provider->driver ?? '';
        $driverClass = self::$drivers[$driverKey] ?? null;

        if (!$driverClass) {
            throw new \InvalidArgumentException(
                "未注册的云驱动: [{$driverKey}]，已支持: " . implode(', ', array_keys(self::$drivers))
            );
        }

        // api_credentials 在模型中已自动解密（见 Provider::getApiCredentialsAttribute）
        $credentials = $provider->api_credentials ?? [];

        return new $driverClass($credentials);
    }

    /**
     * 根据 Provider ID 实例化驱动
     *
     * @param  int $providerId
     * @return CloudDriverInterface
     * @throws \InvalidArgumentException  Provider 不存在或驱动未注册
     */
    public static function makeById(int $providerId): CloudDriverInterface
    {
        $provider = Provider::find($providerId);
        if (!$provider) {
            throw new \InvalidArgumentException("Provider ID={$providerId} 不存在");
        }
        return self::make($provider);
    }

    /**
     * 注册自定义驱动（可在 ServiceProvider 中扩展）
     *
     * @param  string $key    驱动标识
     * @param  string $class  驱动类全限定名（需实现 CloudDriverInterface）
     */
    public static function extend(string $key, string $class): void
    {
        self::$drivers[$key] = $class;
    }

    /**
     * 获取所有已注册的驱动标识列表
     */
    public static function supportedDrivers(): array
    {
        return array_keys(self::$drivers);
    }
}
