<?php

namespace App\Http\Controllers\V3\Guest;

use App\Http\Controllers\Controller;
use App\Models\VersionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VersionController extends Controller
{
    /**
     * 获取已发布的版本列表（公开接口，前端展示用）
     *
     * GET /guest/version/list
     */
    public function list(Request $request): JsonResponse
    {
        $request->validate([
            'page_size' => 'nullable|integer|min:1|max:50',
        ]);

        $query = VersionLog::query()
            ->where('is_published', true)
            ->orderByDesc('sort_order')
            ->orderByDesc('release_date');

        $pageSize = $request->input('page_size', 20);
        $data = $query->paginate($pageSize);

        return $this->ok([
            'data'     => $data->items(),
            'total'    => $data->total(),
            'page'     => $data->currentPage(),
            'pageSize' => $data->perPage(),
        ]);
    }

    /**
     * 获取最新版本信息
     *
     * GET /guest/version/latest
     */
    public function latest(): JsonResponse
    {
        $latest = VersionLog::query()
            ->where('is_published', true)
            ->orderByDesc('sort_order')
            ->orderByDesc('release_date')
            ->first();

        return $this->ok($latest);
    }

    /**
     * 获取指定版本详情
     *
     * GET /guest/version/detail
     */
    public function detail(Request $request): JsonResponse
    {
        $request->validate([
            'version' => 'required|string|max:50',
        ]);

        $log = VersionLog::query()
            ->where('is_published', true)
            ->where('version', $request->input('version'))
            ->first();

        if (!$log) {
            return $this->error([404, '版本不存在']);
        }

        return $this->ok($log);
    }
}
