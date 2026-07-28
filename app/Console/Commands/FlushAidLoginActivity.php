<?php

namespace App\Console\Commands;

use App\Services\AidLoginActivityService;
use Illuminate\Console\Command;

class FlushAidLoginActivity extends Command
{
    protected $signature = 'aid-login-activity:flush {--limit=5000 : Maximum aggregated login records to flush}';

    protected $description = 'Flush aggregated AID login timestamps from Redis to v2_user.last_login_at';

    /**
     * Execute the console command.
     */
    public function handle(AidLoginActivityService $activityService): int
    {
        $stats = $activityService->flush((int) $this->option('limit'));

        $this->info(sprintf(
            'AID login activity flush scanned=%d updated=%d missing=%d failed=%d',
            $stats['scanned'],
            $stats['updated'],
            $stats['missing'],
            $stats['failed']
        ));

        foreach ($stats['failures'] as $failure) {
            $this->warn(sprintf(
                'Failed user_id=%d last_login_at=%d error=%s',
                $failure['user_id'],
                $failure['last_login_at'],
                $failure['error']
            ));
        }

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
