<?php

namespace App\Console\Commands;

use App\Models\StatServerDetail;
use App\Models\StatUser;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 每日清理两周前的流量明细数据
 *
 * 清理范围：
 *   - v2_stat_server_detail（分钟级节点明细）
 *   - v2_stat_user（用户流量记录）
 *
 * 用法：
 *   php artisan stat:prune-detail              # 删除 14 天前的数据
 *   php artisan stat:prune-detail --days=30    # 自定义保留天数
 *   php artisan stat:prune-detail --dry-run    # 仅统计，不删除
 */
class PruneStatDetail extends Command
{
    protected $signature = 'stat:prune-detail
                            {--days=14   : 保留最近 N 天的数据，默认 14}
                            {--dry-run   : 仅统计待删除行数，不执行删除}
                            {--chunk=500 : 每批删除行数，避免锁表}';

    protected $description = '清理两周前的节点/用户流量明细数据（每日执行）';

    public function handle(): int
    {
        $days    = max(1, (int) $this->option('days'));
        $dryRun  = (bool) $this->option('dry-run');
        $chunk   = max(100, (int) $this->option('chunk'));
        $before  = Carbon::now()->subDays($days)->startOfDay()->timestamp;

        $this->info(sprintf(
            '%s 清理 %d 天前（< %s）的流量明细数据…',
            $dryRun ? '[DRY-RUN]' : '',
            $days,
            Carbon::createFromTimestamp($before)->toDateTimeString()
        ));

        $serverCount = $this->pruneTable(
            StatServerDetail::class,
            'v2_stat_server_detail',
            $before,
            $chunk,
            $dryRun
        );

        // $userCount = $this->pruneTable(
        //     StatUser::class,
        //     'v2_stat_user',
        //     $before,
        //     $chunk,
        //     $dryRun
        // );

        $total = $serverCount;

        $this->info(sprintf(
            '%s 完成：节点明细 %d 行，合计 %d 行。',
            $dryRun ? '[DRY-RUN]' : '清理',
            $serverCount,
            $total
        ));

        Log::info('stat:prune-detail completed', [
            'days'         => $days,
            'before'       => $before,
            'dry_run'      => $dryRun,
            'server_rows'  => $serverCount,
        ]);

        return self::SUCCESS;
    }

    /**
     * 分批删除指定表中 record_at < $before 的记录
     *
     * @param class-string $model
     * @param string       $label
     * @param int          $before
     * @param int          $chunk
     * @param bool         $dryRun
     * @return int  实际（或预计）删除行数
     */
    private function pruneTable(string $model, string $label, int $before, int $chunk, bool $dryRun): int
    {
        $count = $model::where('record_at', '<', $before)->count();

        $this->line(sprintf('  %-30s 待删除 %d 行', $label, $count));

        if ($dryRun || $count === 0) {
            return $count;
        }

        $deleted = 0;
        do {
            $batch = $model::where('record_at', '<', $before)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id');

            if ($batch->isEmpty()) {
                break;
            }

            $deleted += $model::whereIn('id', $batch)->delete();
        } while ($batch->count() === $chunk);

        return $deleted;
    }
}
