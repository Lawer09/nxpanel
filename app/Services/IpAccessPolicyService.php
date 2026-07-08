<?php

namespace App\Services;

use App\Models\IpAllowlistRule;

class IpAccessPolicyService
{
    public function __construct(
        private readonly BlockedUserIpService $blockedUserIpService,
        private readonly AllowedUserIpService $allowedUserIpService,
        private readonly IpAllowlistRuleService $ipAllowlistRuleService
    ) {
    }

    public function isDangerouslyBlocked(?string $ip): bool
    {
        return $this->blockedUserIpService->isDangerous($ip);
    }

    public function isAllowed(?string $ip): bool
    {
        return $this->allowedUserIpService->isAllowed($ip);
    }

    public function isNormallyBlocked(?string $ip): bool
    {
        $ip = $this->blockedUserIpService->normalizeIp($ip);
        if ($ip === null) {
            return false;
        }

        return $this->blockedUserIpService->isBlocked($ip)
            && !$this->blockedUserIpService->isDangerous($ip);
    }

    public function autoAllowIfRuleMatched(?string $ip, array $metadata): ?IpAllowlistRule
    {
        return $this->ipAllowlistRuleService->autoAllowIfRuleMatched($ip, $metadata);
    }
}
