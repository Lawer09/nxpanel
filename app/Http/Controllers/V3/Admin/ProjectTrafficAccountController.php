<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CamelizeResource;
use App\Models\Project;
use App\Models\ProjectTrafficPlatformAccount;
use App\Models\TrafficPlatformAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProjectTrafficAccountController extends Controller
{
    /**
     * 查询项目已关联流量账号
     * GET /projects/{id}/traffic-accounts
     */
    public function fetch(Request $request, int $id): JsonResponse
    {
        try {
            $project = Project::find($id);
            if (!$project) {
                return $this->error([404, '项目不存在']);
            }

            $query = ProjectTrafficPlatformAccount::where('project_id', $id);

            if ($request->filled('platformCode')) {
                $query->where('platform_code', $request->input('platformCode'));
            }
            if ($request->filled('enabled')) {
                $query->where('enabled', $request->input('enabled'));
            }

            $items = $query->orderByDesc('id')->get();

            // 补充 account_name
            $accountIds = $items->pluck('traffic_platform_account_id')->unique()->filter()->values();
            $accountMap = TrafficPlatformAccount::whereIn('id', $accountIds)->pluck('account_name', 'id');

            $list = $items->map(function ($item) use ($accountMap) {
                $arr = $item->toArray();
                $arr['account_name'] = $accountMap[$item->traffic_platform_account_id] ?? '';
                return $arr;
            });

            return $this->ok([
                'data' => CamelizeResource::collection($list),
            ]);
        } catch (\Exception $e) {
            Log::error('ProjectTrafficAccount fetch error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 新增项目流量账号关联
     * POST /projects/{id}/traffic-accounts
     */
    public function save(Request $request, int $id): JsonResponse
    {
        try {
            $project = Project::find($id);
            if (!$project) {
                return $this->error([404, '项目不存在']);
            }

            $request->validate([
                'trafficPlatformAccountId' => 'required|integer',
                'platformCode'             => 'required|string|max:50',
                'externalUid'              => 'nullable|string|max:100',
                'externalUsername'          => 'nullable|string|max:100',
                'bindType'                 => 'nullable|string|in:account,sub_account',
                'enabled'                  => 'nullable|integer|in:0,1',
                'remark'                   => 'nullable|string|max:255',
            ]);

            $externalUid = $request->input('externalUid', '') ?: '';

            // 唯一性校验
            $exists = ProjectTrafficPlatformAccount::where('project_id', $id)
                ->where('traffic_platform_account_id', $request->input('trafficPlatformAccountId'))
                ->where('external_uid', $externalUid)
                ->exists();
            if ($exists) {
                return $this->error([422, '该关联已存在']);
            }

            $relation = ProjectTrafficPlatformAccount::create([
                'project_id'                   => $id,
                'project_code'                 => $project->project_code,
                'traffic_platform_account_id'  => $request->input('trafficPlatformAccountId'),
                'platform_code'                => $request->input('platformCode'),
                'external_uid'                 => $externalUid,
                'external_username'            => $request->input('externalUsername', ''),
                'bind_type'                    => $request->input('bindType', 'account'),
                'enabled'                      => $request->input('enabled', 1),
                'remark'                       => $request->input('remark'),
            ]);

            return $this->ok(['id' => $relation->id]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectTrafficAccount save error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 修改项目流量账号关联
     * PUT /projects/{id}/traffic-accounts/{relationId}
     */
    public function update(Request $request, int $id, int $relationId): JsonResponse
    {
        try {
            $relation = ProjectTrafficPlatformAccount::where('project_id', $id)->where('id', $relationId)->first();
            if (!$relation) {
                return $this->error([404, '关联记录不存在']);
            }

            $request->validate([
                'externalUid'      => 'nullable|string|max:100',
                'externalUsername'  => 'nullable|string|max:100',
                'bindType'         => 'nullable|string|in:account,sub_account',
                'enabled'          => 'nullable|integer|in:0,1',
                'remark'           => 'nullable|string|max:255',
            ]);

            $updateData = [];
            if ($request->has('externalUid'))      $updateData['external_uid']      = $request->input('externalUid') ?: '';
            if ($request->has('externalUsername'))  $updateData['external_username'] = $request->input('externalUsername') ?: '';
            if ($request->has('bindType'))         $updateData['bind_type']         = $request->input('bindType');
            if ($request->has('enabled'))          $updateData['enabled']           = $request->input('enabled');
            if ($request->has('remark'))           $updateData['remark']            = $request->input('remark');

            $relation->update($updateData);

            return $this->ok(true);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectTrafficAccount update error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 删除项目流量账号关联
     * DELETE /projects/{id}/traffic-accounts/{relationId}
     */
    public function drop(int $id, int $relationId): JsonResponse
    {
        try {
            $relation = ProjectTrafficPlatformAccount::where('project_id', $id)->where('id', $relationId)->first();
            if (!$relation) {
                return $this->error([404, '关联记录不存在']);
            }

            $relation->delete();

            return $this->ok(true);
        } catch (\Exception $e) {
            Log::error('ProjectTrafficAccount drop error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }
}
