<?php

namespace App\Services\ExternalPayment\Verifiers;

use App\Services\ExternalPayment\AbstractExternalPaymentVerifier;

/**
 * 信任验证器（不验证，直接通过）
 * 
 * 用于开发/测试环境，或者信任客户端的场景
 */
class TrustVerifier extends AbstractExternalPaymentVerifier
{
    public function getName(): string
    {
        return 'trust';
    }

    public function verify(string $transactionId, ?string $receipt = null, array $metadata = []): array
    {
        $this->logVerification($transactionId, true, 'Trust mode - no verification');

        return [
            'valid' => true,
            'transaction_id' => $transactionId,
            'amount' => $metadata['amount'] ?? 0,
            'currency' => $metadata['currency'] ?? 'USD',
            'purchase_date' => time(),
            'product_id' => $metadata['product_id'] ?? '',
            'metadata' => $metadata
        ];
    }
}
