<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CamelizeResource;
use App\Models\TrafficPlatform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TrafficPlatformController extends Controller
{
    /**
     * 平台列表
     * GET /traffic-platform/platforms
     */
    public function fetch(Request $request): JsonResponse
    {
        try {
            $query = TrafficPlatform::query();

            if ($request->filled('enabled')) {
                $query->where('enabled', $request->input('enabled'));
            }
            if ($request->filled('keyword')) {
                $keyword = $request->input('keyword');
                $query->where(function ($q) use ($keyword) {
                    $q->where('code', 'like', "%{$keyword}%")
                      ->orWhere('name', 'like', "%{$keyword}%");
                });
            }

            $data = $query->orderByDesc('id')->get();

            return $this->ok([
                'data' => CamelizeResource::collection($data),
            ]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatform fetch error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 新增平台
     * POST /traffic-platform/platforms
     */
    public function save(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'code'           => 'required|string|max:50',
                'name'           => 'required|string|max:100',
                'baseUrl'        => 'nullable|string|max:255',
                'enabled'        => 'nullable|integer|in:0,1',
            ]);

            if (TrafficPlatform::where('code', $request->input('code'))->exists()) {
                return $this->error([422, '平台编码已存在']);
            }

            $platform = TrafficPlatform::create([
                'code'            => $request->input('code'),
                'name'            => $request->input('name'),
                'base_url'        => $request->input('baseUrl', ''),
                'enabled'         => $request->input('enabled', 1),
            ]);

            return $this->ok(CamelizeResource::make($platform));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatform save error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 修改平台
     * PUT /traffic-platform/platforms/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $platform = TrafficPlatform::find($id);
            if (!$platform) {
                return $this->error([404, '平台不存在']);
            }

            $request->validate([
                'name'           => 'nullable|string|max:100',
                'baseUrl'        => 'nullable|string|max:255',
                'enabled'        => 'nullable|integer|in:0,1',
            ]);

            $updateData = [];
            if ($request->has('name'))           $updateData['name']            = $request->input('name');
            if ($request->has('baseUrl'))         $updateData['base_url']        = $request->input('baseUrl');
            if ($request->has('enabled'))         $updateData['enabled']         = $request->input('enabled');

            $platform->update($updateData);

            return $this->ok(CamelizeResource::make($platform->fresh()));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatform update error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 启用/禁用平台
     * PATCH /traffic-platform/platforms/{id}/status
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'enabled' => 'required|integer|in:0,1',
            ]);

            $platform = TrafficPlatform::find($id);
            if (!$platform) {
                return $this->error([404, '平台不存在']);
            }

            $platform->update(['enabled' => $request->input('enabled')]);

            return $this->ok(true);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatform updateStatus error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }
}
