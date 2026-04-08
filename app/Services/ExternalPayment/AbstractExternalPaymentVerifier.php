<?php

namespace App\Services\ExternalPayment;

use Illuminate\Support\Facades\Log;

/**
 * 抽象验证器基类
 */
abstract class AbstractExternalPaymentVerifier implements ExternalPaymentVerifierInterface
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * 记录验证日志
     */
    protected function logVerification(string $transactionId, bool $success, ?string $error = null): void
    {
        Log::info('External payment verification', [
            'verifier' => $this->getName(),
            'transaction_id' => $transactionId,
            'success' => $success,
            'error' => $error
        ]);
    }

    /**
     * 验证必需的配置项
     */
    protected function validateConfig(array $requiredKeys): void
    {
        foreach ($requiredKeys as $key) {
            if (!isset($this->config[$key])) {
                throw new \InvalidArgumentException("Missing required config: {$key}");
            }
        }
    }
}
