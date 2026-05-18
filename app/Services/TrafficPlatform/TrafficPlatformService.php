<?php

namespace App\Services\TrafficPlatform;

use App\Exceptions\BusinessException;
use App\Models\TrafficPlatform;

class TrafficPlatformService
{
    /**
     * 平台列表查询。
     */
    public function index(array $params): array
    {
        $query = TrafficPlatform::query();

        if (array_key_exists('enabled', $params) && $params['enabled'] !== null) {
            $query->where('enabled', $params['enabled']);
        }
        if (!empty($params['keyword'])) {
            $keyword = (string) $params['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('code', 'like', "%{$keyword}%")
                    ->orWhere('name', 'like', "%{$keyword}%");
            });
        }

        return [
            'data' => $query->orderByDesc('id')->get(),
        ];
    }

    /**
     * 创建平台配置。
     */
    public function store(array $params): TrafficPlatform
    {
        if (TrafficPlatform::where('code', $params['code'])->exists()) {
            throw new BusinessException([422, '平台编码已存在']);
        }

        return TrafficPlatform::create([
            'code' => $params['code'],
            'name' => $params['name'],
            'base_url' => $params['baseUrl'] ?? '',
            'enabled' => $params['enabled'] ?? 1,
        ]);
    }

    /**
     * 更新平台配置。
     */
    public function update(array $params): TrafficPlatform
    {
        $platform = TrafficPlatform::find((int) $params['id']);
        if (!$platform) {
            throw new BusinessException([404, '平台不存在']);
        }

        $updateData = [];
        if (array_key_exists('name', $params)) {
            $updateData['name'] = $params['name'];
        }
        if (array_key_exists('baseUrl', $params)) {
            $updateData['base_url'] = $params['baseUrl'];
        }
        if (array_key_exists('enabled', $params)) {
            $updateData['enabled'] = $params['enabled'];
        }

        if (!empty($updateData)) {
            $platform->update($updateData);
        }

        return $platform->fresh();
    }

    /**
     * 更新平台状态。
     */
    public function updateStatus(int $id, int $enabled): void
    {
        $platform = TrafficPlatform::find($id);
        if (!$platform) {
            throw new BusinessException([404, '平台不存在']);
        }

        $platform->update(['enabled' => $enabled]);
    }
}
