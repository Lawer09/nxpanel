<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ExpiredPlanDowngradeService
{
    public const RESET_REASON = 'expired_plan_downgrade';

    public function __construct(
        private readonly TrafficResetService $trafficResetService
    ) {
    }

    /**
     * Downgrade all expired users to the default free plan.
     */
    public function downgradeExpiredUsers(int $chunkSize = 100): array
    {
        $startedAt = microtime(true);
        $stats = [
            'free_plan_found' => false,
            'free_plan_id' => null,
            'matched' => 0,
            'downgraded' => 0,
            'failed' => 0,
            'failures' => [],
            'duration' => 0.0,
        ];

        $freePlan = $this->resolveFreePlan();
        if (!$freePlan) {
            $stats['duration'] = round(microtime(true) - $startedAt, 2);
            Log::warning('Expired plan downgrade skipped because no free plan was found');
            return $stats;
        }

        $stats['free_plan_found'] = true;
        $stats['free_plan_id'] = $freePlan->id;

        User::query()
            ->whereNotNull('plan_id')
            ->whereNotNull('expired_at')
            ->where('expired_at', '<=', time())
            ->orderBy('id')
            ->chunkById($chunkSize, function ($users) use (&$stats, $freePlan) {
                $stats['matched'] += $users->count();

                foreach ($users as $user) {
                    try {
                        $this->downgradeUserToFreePlan((int) $user->id, $freePlan);
                        $stats['downgraded']++;
                    } catch (\Throwable $e) {
                        $stats['failed']++;
                        $stats['failures'][] = [
                            'user_id' => $user->id,
                            'error' => $e->getMessage(),
                        ];

                        Log::error('Expired plan downgrade failed', [
                            'user_id' => $user->id,
                            'free_plan_id' => $freePlan->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $stats['duration'] = round(microtime(true) - $startedAt, 2);

        Log::info('Expired plan downgrade finished', [
            'free_plan_id' => $freePlan->id,
            'matched' => $stats['matched'],
            'downgraded' => $stats['downgraded'],
            'failed' => $stats['failed'],
            'duration' => $stats['duration'],
        ]);

        return $stats;
    }

    /**
     * Resolve the default free plan by configured priority.
     */
    public function resolveFreePlan(): ?Plan
    {
        $plan = Plan::query()->find(1);
        if ($plan) {
            return $plan;
        }

        foreach (['Free', '免费'] as $name) {
            $plan = Plan::query()
                ->where('name', $name)
                ->orderBy('id')
                ->first();

            if ($plan) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * Downgrade a single user to the free plan and reset traffic.
     */
    public function downgradeUserToFreePlan(int $userId, Plan $freePlan): void
    {
        DB::transaction(function () use ($userId, $freePlan) {
            $user = User::query()->lockForUpdate()->find($userId);
            if (!$user || $user->plan_id === null || $user->expired_at === null || (int) $user->expired_at > time()) {
                return;
            }

            $fromPlanId = (int) $user->plan_id;

            $user->plan_id = $freePlan->id;
            $user->group_id = $freePlan->group_id;
            $user->transfer_enable = $freePlan->transfer_enable * 1073741824;
            $user->speed_limit = $freePlan->speed_limit;
            $user->device_limit = $freePlan->device_limit;
            $user->expired_at = null;

            if (!$user->save()) {
                throw new RuntimeException('Failed to save downgraded user subscription');
            }

            $resetOk = $this->trafficResetService->performReset(
                $user,
                TrafficResetLog::SOURCE_CRON,
                [
                    'reason' => self::RESET_REASON,
                    'from_plan_id' => $fromPlanId,
                    'to_plan_id' => $freePlan->id,
                ]
            );

            if (!$resetOk) {
                throw new RuntimeException('Failed to reset user traffic after downgrade');
            }
        });
    }
}
