<?php

namespace App\Console\Commands;

use App\Services\ExpiredPlanDowngradeService;
use Illuminate\Console\Command;

class DowngradeExpiredUsersToFreePlan extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscription:downgrade-expired-to-free {--chunk=100 : Number of users processed per batch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Downgrade expired users to the default free plan';

    public function __construct(
        private readonly ExpiredPlanDowngradeService $expiredPlanDowngradeService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));
        $stats = $this->expiredPlanDowngradeService->downgradeExpiredUsers($chunkSize);

        if (!$stats['free_plan_found']) {
            $this->warn('No free plan found. Skip downgrade and keep current behavior.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Free plan #%d matched users=%d downgraded=%d failed=%d duration=%ss',
            $stats['free_plan_id'],
            $stats['matched'],
            $stats['downgraded'],
            $stats['failed'],
            $stats['duration']
        ));

        if (!empty($stats['failures'])) {
            foreach ($stats['failures'] as $failure) {
                $this->error(sprintf(
                    'user_id=%d error=%s',
                    $failure['user_id'],
                    $failure['error']
                ));
            }
        }

        return self::SUCCESS;
    }
}
