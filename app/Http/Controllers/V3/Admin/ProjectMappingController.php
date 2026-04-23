<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProjectMappingUpsert;
use App\Models\ProjectPlatformAppMap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProjectMappingController extends Controller
{
    /**
     * 查询项目映射列表
     * GET /admin/project-app-mappings
     */
    public function fetch(Request $request)
    {
        try {
            $page      = (int) $request->query('page', 1);
            $size      = (int) $request->query('size', 20);
            $projectId = $request->query('project_id');
            $platform  = $request->query('source_platform');
            $accountId = $request->query('account_id');
            $status    = $request->query('status');

            $query = ProjectPlatformAppMap::with('account:id,account_name,source_platform');

            if ($projectId) {
                $query->where('project_id', $projectId);
            }
            if ($platform) {
                $query->where('source_platform', $platform);
            }
            if ($accountId) {
                $query->where('account_id', $accountId);
            }
            if ($status) {
                $query->where('status', $status);
            }

            $total = $query->count();
            $items = $query->orderByDesc('id')
                ->offset(($page - 1) * $size)
                ->limit($size)
                ->get();

            return $this->ok([
                'page'  => $page,
                'size'  => $size,
                'total' => $total,
                'items' => $items,
            ]);
        } catch (\Exception $e) {
            Log::error('ProjectMapping fetch error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 新建项目映射
     * POST /admin/project-app-mappings
     */
    public function save(ProjectMappingUpsert $request)
    {
        try {
            $params = $request->validated();

            // 唯一键防重复
            $exists = ProjectPlatformAppMap::where('project_id', $params['project_id'])
                ->where('source_platform', $params['source_platform'])
                ->where('account_id', $params['account_id'])
                ->where('provider_app_id', $params['provider_app_id'])
                ->exists();
            if ($exists) {
                return $this->error([422, '该项目映射已存在']);
            }

            $mapping = ProjectPlatformAppMap::create($params);

            return $this->ok($mapping, [201, '创建成功']);
        } catch (\Exception $e) {
            Log::error('ProjectMapping save error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 更新项目映射
     * PUT /admin/project-app-mappings/{id}
     */
    public function update(ProjectMappingUpsert $request, int $id)
    {
        try {
            $mapping = ProjectPlatformAppMap::find($id);
            if (!$mapping) {
                return $this->error([404, '映射不存在']);
            }

            $params = $request->validated();

            // 唯一键防重复（排除自身）
            $exists = ProjectPlatformAppMap::where('project_id', $params['project_id'])
                ->where('source_platform', $params['source_platform'])
                ->where('account_id', $params['account_id'])
                ->where('provider_app_id', $params['provider_app_id'])
                ->where('id', '!=', $id)
                ->exists();
            if ($exists) {
                return $this->error([422, '该项目映射已存在']);
            }

            $mapping->update($params);

            return $this->ok($mapping->fresh());
        } catch (\Exception $e) {
            Log::error('ProjectMapping update error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 启用/停用映射
     * PATCH /admin/project-app-mappings/{id}/status
     */
    public function updateStatus(Request $request, int $id)
    {
        try {
            $request->validate([
                'status' => 'required|string|in:enabled,disabled',
            ]);

            $mapping = ProjectPlatformAppMap::find($id);
            if (!$mapping) {
                return $this->error([404, '映射不存在']);
            }

            $mapping->update(['status' => $request->input('status')]);

            return $this->ok(true);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, '状态格式有误']);
        } catch (\Exception $e) {
            Log::error('ProjectMapping updateStatus error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }
}
