<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CamelizeResource;
use App\Models\Project;
use App\Models\ProjectUserAppMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProjectUserAppMapController extends Controller
{
    /**
     * 查询项目用户App绑定
     * GET /projects/{id}/user-apps
     */
    public function fetch(Request $request, int $id): JsonResponse
    {
        try {
            $project = Project::find($id);
            if (!$project) {
                return $this->error([404, '项目不存在']);
            }

            $request->validate([
                'enabled' => 'nullable|integer|in:0,1',
                'keyword' => 'nullable|string|max:255',
            ]);

            $query = ProjectUserAppMap::where('project_code', $project->project_code);

            if ($request->filled('enabled')) {
                $query->where('enabled', (int) $request->input('enabled'));
            }
            if ($request->filled('keyword')) {
                $keyword = trim((string) $request->input('keyword'));
                $query->where('app_id', 'like', "%{$keyword}%");
            }

            $items = $query->orderByDesc('id')->get();

            return $this->ok([
                'data' => CamelizeResource::collection($items),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectUserAppMap fetch error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 新增项目用户App绑定
     * POST /projects/{id}/user-apps
     */
    public function save(Request $request, int $id): JsonResponse
    {
        try {
            $project = Project::find($id);
            if (!$project) {
                return $this->error([404, '项目不存在']);
            }

            $request->validate([
                'appId'   => 'required|string|max:255',
                'enabled' => 'nullable|integer|in:0,1',
                'remark'  => 'nullable|string|max:255',
            ]);

            $appId = trim((string) $request->input('appId'));
            if ($appId === '') {
                return $this->error([422, 'appId不能为空']);
            }

            $exists = ProjectUserAppMap::where('project_code', $project->project_code)
                ->where('app_id', $appId)
                ->exists();
            if ($exists) {
                return $this->error([422, '该App绑定已存在']);
            }

            $relation = ProjectUserAppMap::create([
                'project_code' => $project->project_code,
                'app_id'       => $appId,
                'enabled'      => (int) $request->input('enabled', 1),
                'remark'       => $request->input('remark'),
            ]);

            return $this->ok(['id' => $relation->id]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectUserAppMap save error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 修改项目用户App绑定
     * PUT /projects/{id}/user-apps/{relationId}
     */
    public function update(Request $request, int $id, int $relationId): JsonResponse
    {
        try {
            $project = Project::find($id);
            if (!$project) {
                return $this->error([404, '项目不存在']);
            }

            $relation = ProjectUserAppMap::where('project_code', $project->project_code)
                ->where('id', $relationId)
                ->first();
            if (!$relation) {
                return $this->error([404, '关联记录不存在']);
            }

            $request->validate([
                'appId'   => 'nullable|string|max:255',
                'enabled' => 'nullable|integer|in:0,1',
                'remark'  => 'nullable|string|max:255',
            ]);

            $updateData = [];

            if ($request->has('appId')) {
                $appId = trim((string) $request->input('appId'));
                if ($appId === '') {
                    return $this->error([422, 'appId不能为空']);
                }

                $exists = ProjectUserAppMap::where('project_code', $project->project_code)
                    ->where('app_id', $appId)
                    ->where('id', '!=', $relationId)
                    ->exists();
                if ($exists) {
                    return $this->error([422, '该App绑定已存在']);
                }

                $updateData['app_id'] = $appId;
            }

            if ($request->has('enabled')) {
                $updateData['enabled'] = (int) $request->input('enabled');
            }
            if ($request->has('remark')) {
                $updateData['remark'] = $request->input('remark');
            }

            if (!empty($updateData)) {
                $relation->update($updateData);
            }

            return $this->ok(true);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectUserAppMap update error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 删除项目用户App绑定
     * DELETE /projects/{id}/user-apps/{relationId}
     */
    public function drop(int $id, int $relationId): JsonResponse
    {
        try {
            $project = Project::find($id);
            if (!$project) {
                return $this->error([404, '项目不存在']);
            }

            $relation = ProjectUserAppMap::where('project_code', $project->project_code)
                ->where('id', $relationId)
                ->first();
            if (!$relation) {
                return $this->error([404, '关联记录不存在']);
            }

            $relation->delete();

            return $this->ok(true);
        } catch (\Exception $e) {
            Log::error('ProjectUserAppMap drop error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }
}
