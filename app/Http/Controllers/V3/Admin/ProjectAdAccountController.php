<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CamelizeResource;
use App\Models\AdPlatformAccount;
use App\Models\Project;
use App\Models\ProjectAdPlatformAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProjectAdAccountController extends Controller
{
    /**
     * 查询项目已关联广告账号
     * GET /projects/{id}/ad-accounts
     */
    public function fetch(Request $request, int $id): JsonResponse
    {
        try {
            $project = Project::find($id);
            if (!$project) {
                return $this->error([404, '项目不存在']);
            }

            $query = ProjectAdPlatformAccount::where('project_id', $id);

            if ($request->filled('platformCode')) {
                $query->where('platform_code', $request->input('platformCode'));
            }
            if ($request->filled('enabled')) {
                $query->where('enabled', $request->input('enabled'));
            }

            $items = $query->orderByDesc('id')->get();

            // 补充 account_name
            $accountIds = $items->pluck('ad_platform_account_id')->unique()->filter()->values();
            $accountMap = AdPlatformAccount::whereIn('id', $accountIds)->pluck('account_name', 'id');

            $list = $items->map(function ($item) use ($accountMap) {
                $arr = $item->toArray();
                $arr['account_name'] = $accountMap[$item->ad_platform_account_id] ?? '';
                return $arr;
            });

            return $this->ok([
                'data' => CamelizeResource::collection($list),
            ]);
        } catch (\Exception $e) {
            Log::error('ProjectAdAccount fetch error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 新增项目广告账号关联
     * POST /projects/{id}/ad-accounts
     */
    public function save(Request $request, int $id): JsonResponse
    {
        try {
            $project = Project::find($id);
            if (!$project) {
                return $this->error([404, '项目不存在']);
            }

            $request->validate([
                'adPlatformAccountId' => 'required|integer',
                'platformCode'        => 'required|string|max:50',
                'externalAppId'       => 'nullable|string|max:100',
                'externalAdUnitId'    => 'nullable|string|max:100',
                'bindType'            => 'nullable|string|in:account,app,ad_unit',
                'enabled'             => 'nullable|integer|in:0,1',
                'remark'              => 'nullable|string|max:255',
            ]);

            $externalAppId    = $request->input('externalAppId', '') ?: '';
            $externalAdUnitId = $request->input('externalAdUnitId', '') ?: '';

            // 唯一性校验
            $exists = ProjectAdPlatformAccount::where('project_id', $id)
                ->where('ad_platform_account_id', $request->input('adPlatformAccountId'))
                ->where('external_app_id', $externalAppId)
                ->where('external_ad_unit_id', $externalAdUnitId)
                ->exists();
            if ($exists) {
                return $this->error([422, '该关联已存在']);
            }

            $relation = ProjectAdPlatformAccount::create([
                'project_id'             => $id,
                'project_code'           => $project->project_code,
                'ad_platform_account_id' => $request->input('adPlatformAccountId'),
                'platform_code'          => $request->input('platformCode'),
                'external_app_id'        => $externalAppId,
                'external_ad_unit_id'    => $externalAdUnitId,
                'bind_type'              => $request->input('bindType', 'account'),
                'enabled'                => $request->input('enabled', 1),
                'remark'                 => $request->input('remark'),
            ]);

            return $this->ok(['id' => $relation->id]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectAdAccount save error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 修改项目广告账号关联
     * PUT /projects/{id}/ad-accounts/{relationId}
     */
    public function update(Request $request, int $id, int $relationId): JsonResponse
    {
        try {
            $relation = ProjectAdPlatformAccount::where('project_id', $id)->where('id', $relationId)->first();
            if (!$relation) {
                return $this->error([404, '关联记录不存在']);
            }

            $request->validate([
                'externalAppId'    => 'nullable|string|max:100',
                'externalAdUnitId' => 'nullable|string|max:100',
                'bindType'         => 'nullable|string|in:account,app,ad_unit',
                'enabled'          => 'nullable|integer|in:0,1',
                'remark'           => 'nullable|string|max:255',
            ]);

            $updateData = [];
            if ($request->has('externalAppId'))    $updateData['external_app_id']     = $request->input('externalAppId') ?: '';
            if ($request->has('externalAdUnitId')) $updateData['external_ad_unit_id'] = $request->input('externalAdUnitId') ?: '';
            if ($request->has('bindType'))         $updateData['bind_type']           = $request->input('bindType');
            if ($request->has('enabled'))          $updateData['enabled']             = $request->input('enabled');
            if ($request->has('remark'))           $updateData['remark']              = $request->input('remark');

            $relation->update($updateData);

            return $this->ok(true);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectAdAccount update error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 删除项目广告账号关联
     * DELETE /projects/{id}/ad-accounts/{relationId}
     */
    public function drop(int $id, int $relationId): JsonResponse
    {
        try {
            $relation = ProjectAdPlatformAccount::where('project_id', $id)->where('id', $relationId)->first();
            if (!$relation) {
                return $this->error([404, '关联记录不存在']);
            }

            $relation->delete();

            return $this->ok(true);
        } catch (\Exception $e) {
            Log::error('ProjectAdAccount drop error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }
}
