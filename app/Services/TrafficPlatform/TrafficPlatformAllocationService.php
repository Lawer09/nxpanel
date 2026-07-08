<?php

namespace App\Services\TrafficPlatform;

use App\Exceptions\BusinessException;
use App\Models\TrafficPlatformAccount;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TrafficPlatformAllocationService
{
    /**
     * 校验本地代理账户后创建划转订单。
     */
    public function createOrderForAccount(array $params): array
    {
        $accountId = (int) $params['accountId'];
        $account = TrafficPlatformAccount::query()->find($accountId);
        if (!$account) {
            throw new BusinessException([404, '流量平台账号不存在']);
        }

        $targetUserId = trim((string) $params['targetUserId']);
        $targetUsername = trim((string) $params['targetUsername']);
        $amountGb = (float) $params['amountGb'];

        $result = $this->createOrder($accountId, $targetUserId, $targetUsername, $amountGb);

        return [
            'account_id' => $accountId,
            'account_name' => (string) $account->account_name,
            'target_user_id' => $targetUserId,
            'target_username' => $targetUsername,
            'amount_gb' => $amountGb,
            'status_code' => $result['status_code'],
            'response' => $result['response'],
        ];
    }

    /**
     * 创建代理流量划转订单。
     */
    public function createOrder(int $accountId, string $targetUserId, string $targetUsername, float $amountGb): array
    {
        $baseUrl = rtrim((string) config('services.traffic_platform_service.base_url', ''), '/');
        $apiKey = (string) config('services.traffic_platform_service.api_key', '');
        $timeout = (int) config('services.traffic_platform_service.timeout_seconds', 15);

        if ($baseUrl === '') {
            throw new RuntimeException('traffic platform service base_url is not configured');
        }

        if ($apiKey === '') {
            throw new RuntimeException('traffic platform service api_key is not configured');
        }

        $payload = [
            'account_id' => $accountId,
            'target_user_id' => $targetUserId,
            'target_username' => $targetUsername,
            'amount_gb' => $amountGb,
        ];

        $response = Http::timeout(max(1, $timeout))
            ->withHeaders([
                'X-API-Key' => $apiKey,
                'Content-Type' => 'application/json',
            ])
            ->post($baseUrl . '/api/traffic-platform/traffic-allocations/orders', $payload);

        if (!$response->successful()) {
            throw new RuntimeException(
                'traffic allocation request failed with status '
                . $response->status()
                . ': '
                . mb_substr($response->body(), 0, 1000)
            );
        }

        $body = $response->json();

        return [
            'status_code' => $response->status(),
            'response' => is_array($body) ? $body : ['body' => $response->body()],
        ];
    }
}
