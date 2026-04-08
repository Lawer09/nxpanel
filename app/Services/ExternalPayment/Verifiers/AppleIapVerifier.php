<?php

namespace App\Services\ExternalPayment\Verifiers;

use App\Services\ExternalPayment\AbstractExternalPaymentVerifier;
use Illuminate\Support\Facades\Http;

/**
 * Apple In-App Purchase 凭证验证器
 * 
 * 文档: https://developer.apple.com/documentation/appstorereceipts/verifyreceipt
 */
class AppleIapVerifier extends AbstractExternalPaymentVerifier
{
    private const PRODUCTION_URL = 'https://buy.itunes.apple.com/verifyReceipt';
    private const SANDBOX_URL = 'https://sandbox.itunes.apple.com/verifyReceipt';

    public function getName(): string
    {
        return 'apple_iap';
    }

    public function verify(string $transactionId, ?string $receipt = null, array $metadata = []): array
    {
        if (!$receipt) {
            throw new \InvalidArgumentException('Receipt is required for Apple IAP verification');
        }

        $this->validateConfig(['shared_secret']);

        // 先尝试生产环境
        $result = $this->verifyWithApple(self::PRODUCTION_URL, $receipt);

        // 如果返回 21007 (sandbox receipt)，切换到沙盒环境
        if (isset($result['status']) && $result['status'] === 21007) {
            $result = $this->verifyWithApple(self::SANDBOX_URL, $receipt);
        }

        if (!isset($result['status']) || $result['status'] !== 0) {
            $errorMsg = $this->getErrorMessage($result['status'] ?? -1);
            $this->logVerification($transactionId, false, $errorMsg);
            throw new \RuntimeException("Apple IAP verification failed: {$errorMsg}");
        }

        // 查找匹配的交易
        $transaction = $this->findTransaction($result, $transactionId);

        if (!$transaction) {
            $this->logVerification($transactionId, false, 'Transaction not found in receipt');
            throw new \RuntimeException('Transaction not found in receipt');
        }

        $this->logVerification($transactionId, true);

        return [
            'valid' => true,
            'transaction_id' => $transaction['transaction_id'],
            'amount' => $this->parseAmount($transaction),
            'currency' => 'USD',  // Apple 不返回货币，需要从产品配置获取
            'purchase_date' => strtotime($transaction['purchase_date']),
            'product_id' => $transaction['product_id'],
            'metadata' => [
                'original_transaction_id' => $transaction['original_transaction_id'] ?? null,
                'environment' => $result['environment'] ?? 'Production',
                'bundle_id' => $result['receipt']['bundle_id'] ?? null
            ]
        ];
    }

    private function verifyWithApple(string $url, string $receipt): array
    {
        $response = Http::timeout(30)->post($url, [
            'receipt-data' => $receipt,
            'password' => $this->config['shared_secret'],
            'exclude-old-transactions' => true
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Failed to connect to Apple verification server');
        }

        return $response->json();
    }

    private function findTransaction(array $result, string $transactionId): ?array
    {
        $inApp = $result['receipt']['in_app'] ?? [];
        
        foreach ($inApp as $transaction) {
            if ($transaction['transaction_id'] === $transactionId) {
                return $transaction;
            }
        }

        return null;
    }

    private function parseAmount(array $transaction): int
    {
        // Apple 不直接返回金额，需要从产品配置或元数据获取
        // 这里返回 0，实际使用时应该从套餐价格获取
        return 0;
    }

    private function getErrorMessage(int $status): string
    {
        return match ($status) {
            21000 => 'The App Store could not read the JSON object you provided',
            21002 => 'The data in the receipt-data property was malformed',
            21003 => 'The receipt could not be authenticated',
            21004 => 'The shared secret you provided does not match',
            21005 => 'The receipt server is not currently available',
            21006 => 'This receipt is valid but expired',
            21007 => 'This receipt is from the test environment',
            21008 => 'This receipt is from the production environment',
            21010 => 'This receipt could not be authorized',
            default => "Unknown error (status: {$status})"
        };
    }
}
