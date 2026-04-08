<?php

namespace App\Services\ExternalPayment\Verifiers;

use App\Services\ExternalPayment\AbstractExternalPaymentVerifier;
use Illuminate\Support\Facades\Http;

/**
 * Google Play 凭证验证器
 * 
 * 文档: https://developers.google.com/android-publisher/api-ref/rest/v3/purchases.products/get
 */
class GooglePlayVerifier extends AbstractExternalPaymentVerifier
{
    private const API_BASE = 'https://androidpublisher.googleapis.com/androidpublisher/v3';

    public function getName(): string
    {
        return 'google_play';
    }

    public function verify(string $transactionId, ?string $receipt = null, array $metadata = []): array
    {
        $this->validateConfig(['package_name', 'access_token']);

        $productId = $metadata['product_id'] ?? null;
        if (!$productId) {
            throw new \InvalidArgumentException('product_id is required in metadata for Google Play verification');
        }

        $url = sprintf(
            '%s/applications/%s/purchases/products/%s/tokens/%s',
            self::API_BASE,
            $this->config['package_name'],
            $productId,
            $transactionId
        );

        $response = Http::timeout(30)
            ->withToken($this->config['access_token'])
            ->get($url);

        if (!$response->successful()) {
            $this->logVerification($transactionId, false, 'API request failed: ' . $response->body());
            throw new \RuntimeException('Google Play verification failed: ' . $response->body());
        }

        $data = $response->json();

        // 检查购买状态 (0 = purchased, 1 = canceled, 2 = pending)
        if (($data['purchaseState'] ?? null) !== 0) {
            $this->logVerification($transactionId, false, 'Purchase not completed');
            throw new \RuntimeException('Purchase not completed');
        }

        // 检查消费状态 (0 = not consumed, 1 = consumed)
        if (($data['consumptionState'] ?? null) === 1) {
            $this->logVerification($transactionId, false, 'Purchase already consumed');
            throw new \RuntimeException('Purchase already consumed');
        }

        $this->logVerification($transactionId, true);

        return [
            'valid' => true,
            'transaction_id' => $transactionId,
            'amount' => 0,  // Google Play 不直接返回金额，需要从产品配置获取
            'currency' => 'USD',
            'purchase_date' => isset($data['purchaseTimeMillis']) ? (int)($data['purchaseTimeMillis'] / 1000) : time(),
            'product_id' => $productId,
            'metadata' => [
                'order_id' => $data['orderId'] ?? null,
                'purchase_state' => $data['purchaseState'] ?? null,
                'developer_payload' => $data['developerPayload'] ?? null
            ]
        ];
    }
}
