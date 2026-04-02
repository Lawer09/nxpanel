<?php

namespace App\Services;

use App\Models\StatServerDetail;
use App\Models\StatUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * OSS 流量数据归档服务
 *
 * 将指定小时的节点/用户流量数据序列化为 NDJSON（每行一条 JSON）
 * 并上传到 OSS（S3 兼容存储）。
 *
 * 文件路径规则：
 *   stat/server/{YYYY}/{MM}/{DD}/{HH}.ndjson
 *   stat/user/{YYYY}/{MM}/{DD}/{HH}.ndjson
 */
class OssArchiveService
{
    /** OSS 是否已启用（env OSS_ENABLED=true） */
    public static function enabled(): bool
    {
        return (bool) env('OSS_ENABLED', false);
    }

    /**
     * 归档指定小时的节点流量明细到 OSS
     *
     * @param Carbon $hour  整点时刻（minute=0, second=0）
     * @return array{uploaded: bool, path: string, rows: int}
     */
    public function archiveServerHour(Carbon $hour): array
    {
        $start = $hour->copy()->startOfHour()->timestamp;
        $end   = $hour->copy()->endOfHour()->timestamp;

        $rows = StatServerDetail::where('record_at', '>=', $start)
            ->where('record_at', '<=', $end)
            ->orderBy('record_at')
            ->get();

        return $this->upload(
            $rows,
            sprintf('stat/server/%s/%s/%s/%s.ndjson',
                $hour->format('Y'),
                $hour->format('m'),
                $hour->format('d'),
                $hour->format('H')
            )
        );
    }

    /**
     * 归档指定小时的用户流量数据到 OSS
     *
     * @param Carbon $hour  整点时刻
     * @return array{uploaded: bool, path: string, rows: int}
     */
    public function archiveUserHour(Carbon $hour): array
    {
        $start = $hour->copy()->startOfHour()->timestamp;
        $end   = $hour->copy()->endOfHour()->timestamp;

        $rows = StatUser::where('record_at', '>=', $start)
            ->where('record_at', '<=', $end)
            ->orderBy('record_at')
            ->get();

        return $this->upload(
            $rows,
            sprintf('stat/user/%s/%s/%s/%s.ndjson',
                $hour->format('Y'),
                $hour->format('m'),
                $hour->format('d'),
                $hour->format('H')
            )
        );
    }

    /**
     * 将集合序列化为 NDJSON 并上传到 OSS disk
     *
     * @param \Illuminate\Support\Collection $rows
     * @param string                         $path  OSS 上的相对路径
     * @return array{uploaded: bool, path: string, rows: int}
     */
    private function upload(\Illuminate\Support\Collection $rows, string $path): array
    {
        $count = $rows->count();

        if ($count === 0) {
            return ['uploaded' => false, 'path' => $path, 'rows' => 0];
        }

        // 序列化为 NDJSON（每行一条 JSON，便于流式读取）
        $ndjson = $rows->map(fn($r) => json_encode($r->toArray(), JSON_UNESCAPED_UNICODE))->implode("\n");

        try {
            $ok = Storage::disk('oss')->put($path, $ndjson);
            if (!$ok) {
                Log::warning("OssArchiveService: upload failed", ['path' => $path]);
            }
            return ['uploaded' => $ok, 'path' => $path, 'rows' => $count];
        } catch (\Throwable $e) {
            Log::error("OssArchiveService: upload exception", [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
            return ['uploaded' => false, 'path' => $path, 'rows' => $count];
        }
    }
}
