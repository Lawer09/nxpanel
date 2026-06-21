<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class InactiveZeroUsageUserBanService
{
    /**
     * Disable users that stayed inactive and unused for the configured window.
     */
    public function banInactiveUsers(int $windowDays = 7, int $chunkSize = 100): array
    {
        $startedAt = microtime(true);
        $windowDays = max(1, $windowDays);
        $chunkSize = max(1, $chunkSize);
        $now = Carbon::now('Asia/Shanghai');
        $registrationDate = $now->copy()->subDays($windowDays + 1);
        $createdFrom = $registrationDate->copy()->startOfDay()->timestamp;
        $createdTo = $registrationDate->copy()->endOfDay()->timestamp;
        $reportStartDate = $now->copy()->subDays($windowDays)->toDateString();

        $stats = [
            'matched' => 0,
            'banned' => 0,
            'failed' => 0,
            'failures' => [],
            'window_days' => $windowDays,
            'created_from' => $createdFrom,
            'created_to' => $createdTo,
            'report_start_date' => $reportStartDate,
            'duration' => 0.0,
        ];

        $query = $this->candidateQuery($createdFrom, $createdTo, $reportStartDate);
        $stats['matched'] = (clone $query)->count();

        $query->orderBy('id')->chunkById($chunkSize, function ($users) use (&$stats) {
            foreach ($users as $user) {
                try {
                    $user->banned = true;
                    $user->save();
                    $stats['banned']++;
                } catch (\Throwable $e) {
                    $stats['failed']++;
                    $stats['failures'][] = [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ];

                    Log::error('Inactive zero-usage user ban failed', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        $stats['duration'] = round(microtime(true) - $startedAt, 2);

        Log::info('Inactive zero-usage user ban finished', [
            'matched' => $stats['matched'],
            'banned' => $stats['banned'],
            'failed' => $stats['failed'],
            'window_days' => $stats['window_days'],
            'report_start_date' => $stats['report_start_date'],
            'duration' => $stats['duration'],
        ]);

        return $stats;
    }

    /**
     * Build the user filter for zero local usage and no recent report activity.
     */
    private function candidateQuery(int $createdFrom, int $createdTo, string $reportStartDate): Builder
    {
        return User::query()
            ->where('banned', false)
            ->where(function ($query) {
                $query->where('plan_id', 1)
                    ->orWhereHas('plan', function ($query) {
                        $query->whereIn('name', ['Free', 'free', '免费']);
                    });
            })
            ->whereBetween('created_at', [$createdFrom, $createdTo])
            ->whereRaw('(COALESCE(u, 0) + COALESCE(d, 0)) = 0')
            ->whereNotExists(function ($query) use ($reportStartDate) {
                $query->selectRaw('1')
                    ->from('v3_report_user_hourly')
                    ->whereColumn('v3_report_user_hourly.user_id', 'v2_user.id')
                    ->where('date', '>=', $reportStartDate)
                    ->where(function ($query) {
                        $query->where('traffic_usage', '>', 0)
                            ->orWhere('traffic_upload', '>', 0)
                            ->orWhere('traffic_download', '>', 0)
                            ->orWhere('report_count_user', '>', 0)
                            ->orWhere('report_count_node', '>', 0);
                    });
            });
    }
}
