<?php

namespace App\Services;

use App\Models\PostbackReceipt;
use Illuminate\Database\QueryException;

class PostbackReceiptService
{
    /**
     * Persist the postback exactly once for each package and click ID pair.
     */
    public function store(string $packageName, array $payload, ?string $requestIp, ?string $userAgent): array
    {
        $packageName = trim($packageName);
        $clickId = trim((string) ($payload['clickid'] ?? ''));
        $deviceId = trim((string) ($payload['deviceid'] ?? ''));

        try {
            PostbackReceipt::create([
                'package_name' => $packageName,
                'clickid' => $clickId,
                'deviceid' => $deviceId,
                'request_ip' => $this->limitNullableString($requestIp, 45),
                'user_agent' => $this->limitNullableString($userAgent, 1024),
            ]);

            return $this->formatResult($packageName, $clickId, $deviceId, true, false);
        } catch (QueryException $exception) {
            $existing = PostbackReceipt::where('package_name', $packageName)
                ->where('clickid', $clickId)
                ->first();

            if ($existing) {
                return $this->formatResult($packageName, $clickId, $deviceId, false, true);
            }

            throw $exception;
        }
    }

    /**
     * Format the public postback API response.
     */
    private function formatResult(
        string $packageName,
        string $clickId,
        string $deviceId,
        bool $stored,
        bool $duplicate
    ): array
    {
        return [
            'stored' => $stored,
            'duplicate' => $duplicate,
            'packageName' => $packageName,
            'clickid' => $clickId,
            'deviceid' => $deviceId,
        ];
    }

    private function limitNullableString(?string $value, int $maxLength): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }
}
