<?php

namespace App\Services\ExternalPayment;

use App\Services\ExternalPayment\Verifiers\AppleIapVerifier;
use App\Services\ExternalPayment\Verifiers\GooglePlayVerifier;
use App\Services\ExternalPayment\Verifiers\TrustVerifier;

/**
 * 外部支付验证器工厂
 */
class ExternalPaymentVerifierFactory
{
    /**
     * 已注册的验证器映射
     */
    private static array $verifiers = [
        'apple_iap' => AppleIapVerifier::class,
        'google_play' => GooglePlayVerifier::class,
        'trust' => TrustVerifier::class,
    ];

    /**
     * 创建验证器实例
     * 
     * @param string $source 支付来源标识
     * @param array $config 验证器配置
     * @return ExternalPaymentVerifierInterface
     * @throws \InvalidArgumentException
     */
    public static function make(string $source, array $config = []): ExternalPaymentVerifierInterface
    {
        if (!isset(self::$verifiers[$source])) {
            throw new \InvalidArgumentException("Unknown payment source: {$source}");
        }

        $verifierClass = self::$verifiers[$source];
        return new $verifierClass($config);
    }

    /**
     * 注册自定义验证器
     * 
     * @param string $source 支付来源标识
     * @param string $verifierClass 验证器类名
     */
    public static function register(string $source, string $verifierClass): void
    {
        if (!is_subclass_of($verifierClass, ExternalPaymentVerifierInterface::class)) {
            throw new \InvalidArgumentException(
                "Verifier class must implement ExternalPaymentVerifierInterface"
            );
        }

        self::$verifiers[$source] = $verifierClass;
    }

    /**
     * 获取所有已注册的验证器
     */
    public static function getRegisteredVerifiers(): array
    {
        return array_keys(self::$verifiers);
    }
}
