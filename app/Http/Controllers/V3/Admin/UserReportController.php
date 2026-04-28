<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class UserReportController extends Controller
{
    /**
     * 实时上报数据（缓存）
     *
     * GET /admin/userReport/realtime
     */
    public function getRealtime(Request $request): JsonResponse
    {
        $request->validate([
            'page' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
            'appId' => 'nullable|string|max:255',
        ]);

        $page = (int) $request->input('page', 1);
        $pageSize = (int) $request->input('pageSize', 50);

        $cacheKey = 'realtime:user_report:latest';
        $list = Cache::get($cacheKey, []);
        if (!is_array($list)) {
            $list = [];
        }

        if ($request->filled('appId')) {
            $appId = (string) $request->input('appId');
            $list = array_values(array_filter($list, function ($item) use ($appId) {
                $meta = $item['metadata'] ?? [];
                return isset($meta['app_id']) && (string) $meta['app_id'] === $appId;
            }));
        }

        $total = count($list);
        $offset = ($page - 1) * $pageSize;
        $items = array_slice($list, $offset, $pageSize);

        return $this->ok([
            'data' => $items,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ]);
    }
}
