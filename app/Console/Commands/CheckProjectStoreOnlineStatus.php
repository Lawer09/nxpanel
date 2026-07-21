<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckProjectStoreOnlineStatus extends Command
{
    private const REQUEST_TIMEOUT_SECONDS = 10;

    protected $signature = 'project:check-store-online-status
        {--limit=200 : Maximum projects to check per run}';

    protected $description = 'Check not-launched project store URLs and mark online projects as white-package online';

    /**
     * Execute the project store online status check.
     */
    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $checked = 0;
        $updated = 0;
        $failed = 0;

        $projects = Project::query()
            ->where('ad_status', Project::AD_STATUS_NOT_LAUNCHED)
            ->whereNotNull('store_page_url')
            ->whereRaw("TRIM(store_page_url) <> ''")
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'project_code', 'store_page_url']);

        foreach ($projects as $project) {
            $checked++;
            $url = trim((string) $project->store_page_url);

            try {
                $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)->get($url);
            } catch (Throwable $e) {
                $failed++;
                Log::warning('project:check-store-online-status request failed', [
                    'project_id' => (int) $project->id,
                    'project_code' => $project->project_code,
                    'store_page_url' => $url,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if ($response->status() !== 200) {
                continue;
            }

            $updated += Project::query()
                ->whereKey($project->id)
                ->where('ad_status', Project::AD_STATUS_NOT_LAUNCHED)
                ->update(['ad_status' => Project::AD_STATUS_WHITE_PACKAGE_ONLINE]);
        }

        Log::info('project:check-store-online-status completed', [
            'limit' => $limit,
            'checked' => $checked,
            'updated' => $updated,
            'failed' => $failed,
        ]);

        $this->info(sprintf(
            'Project store online status checked=%d updated=%d failed=%d',
            $checked,
            $updated,
            $failed
        ));

        return self::SUCCESS;
    }
}
