<?php

namespace App\Console\Commands;

use App\Services\InactiveZeroUsageUserBanService;
use Illuminate\Console\Command;

class BanInactiveZeroUsageUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:ban-inactive-zero-usage
        {--days=7 : Recent report window days; users registered days+1 ago are checked}
        {--chunk=100 : Number of users processed per batch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ban users older than the configured window with zero usage and no recent report activity';

    public function __construct(
        private readonly InactiveZeroUsageUserBanService $banService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $chunkSize = max(1, (int) $this->option('chunk'));
        $stats = $this->banService->banInactiveUsers($days, $chunkSize);

        $this->info(sprintf(
            'Inactive zero-usage users checked days=%d matched=%d banned=%d failed=%d report_start_date=%s duration=%ss',
            $stats['window_days'],
            $stats['matched'],
            $stats['banned'],
            $stats['failed'],
            $stats['report_start_date'],
            $stats['duration']
        ));

        foreach ($stats['failures'] as $failure) {
            $this->error(sprintf(
                'user_id=%d error=%s',
                $failure['user_id'],
                $failure['error']
            ));
        }

        return self::SUCCESS;
    }
}
