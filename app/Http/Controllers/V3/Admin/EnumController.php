<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class EnumController extends Controller
{
    /**
     * 缓存 TTL（秒）
     */
    private const CACHE_TTL = 300;

    /**
     * 获取枚举数据（通用）
     *
     * GET /admin/enum/options?types[]=servers&types[]=server_groups&types[]=plans&keyword=xxx
     *
     * types 支持：servers, server_groups, server_types, plans
     * keyword 用于模糊搜索名称
     */
    public function getOptions(Request $request): JsonResponse
    {
        $request->validate([
            'types'   => 'required|array|min:1',
            'types.*' => 'required|string|in:servers,server_groups,server_types,plans',
            'keyword' => 'nullable|string|max:100',
        ]);

        $types = $request->input('types');
        $keyword = $request->input('keyword');
        $result = [];

        foreach ($types as $type) {
            $result[$type] = match ($type) {
                'servers'       => $this->getServers($keyword),
                'server_groups' => $this->getServerGroups($keyword),
                'server_types'  => $this->getServerTypes(),
                'plans'         => $this->getPlans($keyword),
                default         => [],
            };
        }

        return $this->ok($result);
    }

    /**
     * 节点列表枚举（id + name + type）
     */
    private function getServers(?string $keyword): array
    {
        $cacheKey = 'enum:servers';

        $all = Cache::remember($cacheKey, self::CACHE_TTL, function () {
            return Server::orderBy('sort', 'ASC')
                ->select(['id', 'name', 'type', 'host'])
                ->get()
                ->map(fn($s) => [
                    'id'   => $s->id,
                    'name' => $s->name,
                    'type' => $s->type,
                    'host' => $s->host,
                ])
                ->toArray();
        });

        if ($keyword) {
            $keyword = mb_strtolower($keyword);
            $all = array_values(array_filter($all, function ($item) use ($keyword) {
                return str_contains(mb_strtolower($item['name']), $keyword)
                    || str_contains(mb_strtolower($item['host'] ?? ''), $keyword)
                    || str_contains((string) $item['id'], $keyword);
            }));
        }

        return $all;
    }

    /**
     * 节点分组枚举（id + name）
     */
    private function getServerGroups(?string $keyword): array
    {
        $cacheKey = 'enum:server_groups';

        $all = Cache::remember($cacheKey, self::CACHE_TTL, function () {
            return ServerGroup::select(['id', 'name'])
                ->get()
                ->map(fn($g) => [
                    'id'   => $g->id,
                    'name' => $g->name,
                ])
                ->toArray();
        });

        if ($keyword) {
            $keyword = mb_strtolower($keyword);
            $all = array_values(array_filter($all, fn($item) =>
                str_contains(mb_strtolower($item['name']), $keyword)
            ));
        }

        return $all;
    }

    /**
     * 节点类型枚举
     */
    private function getServerTypes(): array
    {
        return array_map(fn($type) => [
            'value' => $type,
            'label' => strtoupper($type),
        ], Server::VALID_TYPES);
    }

    /**
     * 套餐枚举（id + name）
     */
    private function getPlans(?string $keyword): array
    {
        $cacheKey = 'enum:plans';

        $all = Cache::remember($cacheKey, self::CACHE_TTL, function () {
            return Plan::select(['id', 'name'])
                ->orderBy('sort', 'ASC')
                ->get()
                ->map(fn($p) => [
                    'id'   => $p->id,
                    'name' => $p->name,
                ])
                ->toArray();
        });

        if ($keyword) {
            $keyword = mb_strtolower($keyword);
            $all = array_values(array_filter($all, fn($item) =>
                str_contains(mb_strtolower($item['name']), $keyword)
            ));
        }

        return $all;
    }
}
