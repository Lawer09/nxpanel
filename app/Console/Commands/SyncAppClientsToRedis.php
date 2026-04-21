<?php

namespace App\Console\Commands;

use App\Models\AppClient;
use Illuminate\Console\Command;

class SyncAppClientsToRedis extends Command
{
    protected $signature = 'app-client:sync-redis';

    protected $description = '全量同步应用客户端凭证到 Redis';

    public function handle(): int
    {
        AppClient::syncAllToRedis();

        $this->info('应用客户端凭证已同步到 Redis');

        return self::SUCCESS;
    }
}
