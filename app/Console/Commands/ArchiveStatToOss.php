<?php

namespace App\Console\Commands;

use App\Services\OssArchiveService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 每小时将 1 小时前的节点/用户流量数据归档到 OSS
 *
 * 用法：
 *   php artisan stat:archive-to-oss              # 归档上一整小时
 *   php artisan stat:archive-to-oss --hour=2026-04-02T10:00:00  # 归档指定小时
 *   php artisan stat:archive-to-oss --type=server
 *   php artisan stat:archive-to-oss --type=user
 */
class ArchiveStatToOss extends Command
{
    protected $signature = 'stat:archive-to-oss
                            {--hour=   : 指定归档小时，ISO 8601 格式，如 2026-04-02T10:00:00，默认上一整小时}
                            {--type=   : 归档类型：server / user / all（默认 all）}';

    protected $description = '将流量统计数据归档到 OSS（每小时执行，归档 1 小时前的数据）';

    public function handle(OssArchiveService $service): int
    {
        if (!OssArchiveService::enabled()) {
            $this->warn('OSS 归档未启用（OSS_ENABLED=false），跳过。');
            return self::SUCCESS;
        }

        $type = $this->option('type') ?: 'all';

        // 确定归档目标小时（默认：上一整小时）
        $hour = $this->option('hour')
            ? Carbon::parse($this->option('hour'))->startOfHour()
            : Carbon::now()->subHour()->startOfHour();

        $this->info(sprintf('开始归档 [%s] %s 的数据到 OSS…', $type, $hour->toDateTimeString()));

        $results = [];

        if (in_array($type, ['server', 'all'])) {
            $r = $service->archiveServerHour($hour);
            $results['server'] = $r;
            $this->line(sprintf(
                '  节点流量：%s  路径=%s  行数=%d',
                $r['uploaded'] ? '<fg=green>✓ 上传成功</>' : '<fg=yellow>⚠ 跳过（无数据）</>',
                $r['path'],
                $r['rows']
            ));
        }

        if (in_array($type, ['user', 'all'])) {
            $r = $service->archiveUserHour($hour);
            $results['user'] = $r;
            $this->line(sprintf(
                '  用户流量：%s  路径=%s  行数=%d',
                $r['uploaded'] ? '<fg=green>✓ 上传成功</>' : '<fg=yellow>⚠ 跳过（无数据）</>',
                $r['path'],
                $r['rows']
            ));
        }

        Log::info('stat:archive-to-oss completed', [
            'hour'    => $hour->toDateTimeString(),
            'type'    => $type,
            'results' => $results,
        ]);

        $this->info('归档完成。');
        return self::SUCCESS;
    }
}
