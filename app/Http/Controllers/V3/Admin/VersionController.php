<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Models\VersionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VersionController extends Controller
{
    /**
     * 获取版本列表（管理端，含未发布）
     *
     * GET /version/fetch
     */
    public function fetch(Request $request): JsonResponse
    {
        $request->validate([
            'page_size' => 'nullable|integer|min:1|max:200',
        ]);

        $query = VersionLog::query()
            ->orderByDesc('sort_order')
            ->orderByDesc('release_date');

        $pageSize = $request->input('page_size', 50);
        $data = $query->paginate($pageSize);

        return $this->ok([
            'data'     => $data->items(),
            'total'    => $data->total(),
            'page'     => $data->currentPage(),
            'pageSize' => $data->perPage(),
        ]);
    }

    /**
     * 创建版本记录
     *
     * POST /version/save
     */
    public function save(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'version'      => 'required|string|max:50|unique:v3_version_logs,version',
            'title'        => 'required|string|max:255',
            'description'  => 'nullable|string',
            'features'     => 'nullable|array',
            'features.*'   => 'string|max:500',
            'improvements' => 'nullable|array',
            'improvements.*' => 'string|max:500',
            'bugfixes'     => 'nullable|array',
            'bugfixes.*'   => 'string|max:500',
            'release_date' => 'required|date',
            'is_published' => 'nullable|boolean',
            'sort_order'   => 'nullable|integer|min:0',
        ]);

        $log = VersionLog::create($validated);

        return $this->ok($log);
    }

    /**
     * 更新版本记录
     *
     * POST /version/update
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id'           => 'required|integer|exists:v3_version_logs,id',
            'version'      => 'nullable|string|max:50|unique:v3_version_logs,version,' . $request->input('id'),
            'title'        => 'nullable|string|max:255',
            'description'  => 'nullable|string',
            'features'     => 'nullable|array',
            'features.*'   => 'string|max:500',
            'improvements' => 'nullable|array',
            'improvements.*' => 'string|max:500',
            'bugfixes'     => 'nullable|array',
            'bugfixes.*'   => 'string|max:500',
            'release_date' => 'nullable|date',
            'is_published' => 'nullable|boolean',
            'sort_order'   => 'nullable|integer|min:0',
        ]);

        $log = VersionLog::findOrFail($validated['id']);
        $log->update(array_filter($validated, fn($v) => $v !== null));

        return $this->ok($log);
    }

    /**
     * 删除版本记录
     *
     * POST /version/drop
     */
    public function drop(Request $request): JsonResponse
    {
        $request->validate([
            'id' => 'required|integer|exists:v3_version_logs,id',
        ]);

        VersionLog::findOrFail($request->input('id'))->delete();

        return $this->ok();
    }

    /**
     * 版本详情
     *
     * GET /version/detail
     */
    public function detail(Request $request): JsonResponse
    {
        $request->validate([
            'id' => 'required|integer|exists:v3_version_logs,id',
        ]);

        $log = VersionLog::findOrFail($request->input('id'));

        return $this->ok($log);
    }

    /**
     * 发布 / 取消发布
     *
     * POST /version/publish
     */
    public function publish(Request $request): JsonResponse
    {
        $request->validate([
            'id'           => 'required|integer|exists:v3_version_logs,id',
            'is_published' => 'required|boolean',
        ]);

        $log = VersionLog::findOrFail($request->input('id'));
        $log->update(['is_published' => $request->input('is_published')]);

        return $this->ok($log);
    }
}
