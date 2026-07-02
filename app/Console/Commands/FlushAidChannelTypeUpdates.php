<?php

namespace App\Console\Commands;

use App\Services\AidChannelTypeUpdateQueueService;
use Illuminate\Console\Command;

class FlushAidChannelTypeUpdates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'aid-channel-type:flush {--limit=1000 : Maximum queued updates to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Flush queued AID channel_type updates into user register metadata';

    public function __construct(
        private readonly AidChannelTypeUpdateQueueService $queueService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $stats = $this->queueService->flush($limit);

        $this->info(sprintf(
            'AID channel_type updates scanned=%d updated=%d failed=%d',
            $stats['scanned'],
            $stats['updated'],
            $stats['failed']
        ));

        foreach ($stats['failures'] as $failure) {
            $this->error(sprintf(
                'queue_id=%d user_id=%d error=%s',
                $failure['queue_id'],
                $failure['user_id'],
                $failure['error']
            ));
        }

        return self::SUCCESS;
    }
}
