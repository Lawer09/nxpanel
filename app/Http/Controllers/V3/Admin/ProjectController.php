<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CamelizeResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProjectController extends Controller
{
    /**
     * 项目列表
     * GET /projects
     */
    public function fetch(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'keyword'  => 'nullable|string|max:100',
                'ownerId'  => 'nullable|integer',
                'status'   => 'nullable|string|in:active,inactive,archived',
                'page'     => 'nullable|integer|min:1',
                'pageSize' => 'nullable|integer|min:1|max:200',
            ]);

            $query = Project::query();

            if ($request->filled('keyword')) {
                $keyword = $request->input('keyword');
                $query->where(function ($q) use ($keyword) {
                    $q->where('project_code', 'like', "%{$keyword}%")
                      ->orWhere('project_name', 'like', "%{$keyword}%");
                });
            }
            if ($request->filled('ownerId')) {
                $query->where('owner_id', $request->input('ownerId'));
            }
            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            $page     = (int) $request->input('page', 1);
            $pageSize = (int) $request->input('pageSize', 20);

            $total = $query->count();
            $items = $query->orderByDesc('id')
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get();

            return $this->ok([
                'page'     => $page,
                'pageSize' => $pageSize,
                'total'    => $total,
                'data'     => CamelizeResource::collection($items),
            ]);
        } catch (\Exception $e) {
            Log::error('Project fetch error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 项目详情
     * GET /projects/{id}
     */
    public function detail(int $id): JsonResponse
    {
        try {
            $project = Project::find($id);
            if (!$project) {
                return $this->error([404, '项目不存在']);
            }
            return $this->ok(CamelizeResource::make($project));
        } catch (\Exception $e) {
            Log::error('Project detail error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 新增项目
     * POST /projects
     */
    public function save(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'projectCode' => 'required|string|max:100',
                'projectName' => 'required|string|max:100',
                'ownerId'     => 'nullable|integer',
                'ownerName'   => 'nullable|string|max:100',
                'department'  => 'nullable|string|max:100',
                'status'      => 'nullable|string|in:active,inactive,archived',
                'remark'      => 'nullable|string|max:255',
            ]);

            if (Project::where('project_code', $request->input('projectCode'))->exists()) {
                return $this->error([422, '项目代号已存在']);
            }

            $project = Project::create([
                'project_code' => $request->input('projectCode'),
                'project_name' => $request->input('projectName'),
                'owner_id'     => $request->input('ownerId'),
                'owner_name'   => $request->input('ownerName'),
                'department'   => $request->input('department'),
                'status'       => $request->input('status', 'active'),
                'remark'       => $request->input('remark'),
            ]);

            return $this->ok(['id' => $project->id]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('Project save error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 修改项目
     * PUT /projects/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $project = Project::find($id);
            if (!$project) {
                return $this->error([404, '项目不存在']);
            }

            $request->validate([
                'projectName' => 'nullable|string|max:100',
                'ownerId'     => 'nullable|integer',
                'ownerName'   => 'nullable|string|max:100',
                'department'  => 'nullable|string|max:100',
                'status'      => 'nullable|string|in:active,inactive,archived',
                'remark'      => 'nullable|string|max:255',
            ]);

            $updateData = [];
            if ($request->has('projectName')) $updateData['project_name'] = $request->input('projectName');
            if ($request->has('ownerId'))     $updateData['owner_id']     = $request->input('ownerId');
            if ($request->has('ownerName'))   $updateData['owner_name']   = $request->input('ownerName');
            if ($request->has('department'))  $updateData['department']   = $request->input('department');
            if ($request->has('status'))      $updateData['status']       = $request->input('status');
            if ($request->has('remark'))      $updateData['remark']       = $request->input('remark');

            $project->update($updateData);

            return $this->ok(true);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('Project update error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 修改项目状态
     * PATCH /projects/{id}/status
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'status' => 'required|string|in:active,inactive,archived',
            ]);

            $project = Project::find($id);
            if (!$project) {
                return $this->error([404, '项目不存在']);
            }

            $project->update(['status' => $request->input('status')]);

            return $this->ok(true);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('Project updateStatus error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }
}
