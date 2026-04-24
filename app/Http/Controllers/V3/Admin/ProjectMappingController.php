<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProjectMappingFetch;
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
    public function fetch(ProjectMappingFetch $request)
    {
        try {
            $params = $request->validated();

            $query = ProjectPlatformAppMap::with('account:id,account_name,source_platform');

            if (!empty($params['projectId'])) {
                $query->where('project_id', $params['projectId']);
            }
            if (!empty($params['sourcePlatform'])) {
                $query->where('source_platform', $params['sourcePlatform']);
            }
            if (!empty($params['accountId'])) {
                $query->where('account_id', $params['accountId']);
            }
            if (!empty($params['status'])) {
                $query->where('status', $params['status']);
            }

            $pageSize = $params['pageSize'] ?? 20;
            $data = $query->orderByDesc('id')->paginate($pageSize);

            return $this->ok([
                'data'     => $data->items(),
                'total'    => $data->total(),
                'page'     => $data->currentPage(),
                'pageSize' => $data->perPage(),
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
            $exists = ProjectPlatformAppMap::where('project_id', $params['projectId'])
                ->where('source_platform', $params['sourcePlatform'])
                ->where('account_id', $params['accountId'])
                ->where('provider_app_id', $params['providerAppId'])
                ->exists();
            if ($exists) {
                return $this->error([422, '该项目映射已存在']);
            }

            $dbData = [
                'project_id'      => $params['projectId'],
                'source_platform' => $params['sourcePlatform'],
                'account_id'      => $params['accountId'],
                'provider_app_id' => $params['providerAppId'],
                'status'          => $params['status'],
            ];
            $mapping = ProjectPlatformAppMap::create($dbData);

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
            $exists = ProjectPlatformAppMap::where('project_id', $params['projectId'])
                ->where('source_platform', $params['sourcePlatform'])
                ->where('account_id', $params['accountId'])
                ->where('provider_app_id', $params['providerAppId'])
                ->where('id', '!=', $id)
                ->exists();
            if ($exists) {
                return $this->error([422, '该项目映射已存在']);
            }

            $dbData = [
                'project_id'      => $params['projectId'],
                'source_platform' => $params['sourcePlatform'],
                'account_id'      => $params['accountId'],
                'provider_app_id' => $params['providerAppId'],
                'status'          => $params['status'],
            ];
            $mapping->update($dbData);

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
