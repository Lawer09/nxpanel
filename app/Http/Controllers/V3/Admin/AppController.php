<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppController extends Controller
{
    /**
     * 应用列表
     *
     * GET /app-client/fetch
     */
    public function fetch(Request $request): JsonResponse
    {
        $request->validate([
            'page_size' => 'nullable|integer|min:1|max:200',
        ]);

        $query = AppClient::query()->orderByDesc('created_at');

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
     * 创建应用（token / secret 自动生成）
     *
     * POST /app-client/save
     */
    public function save(Request $request): JsonResponse
    {
        $request->validate([
            'name'        => 'required|string|max:100|unique:v3_app_clients,name',
            'app_id'      => 'required|string|max:64|unique:v3_app_clients,app_id',
            'description' => 'nullable|string|max:500',
        ]);

        $client = AppClient::create([
            'name'        => $request->input('name'),
            'app_id'      => $request->input('app_id'),
            'app_token'   => AppClient::generateToken(),
            'app_secret'  => AppClient::generateSecret(),
            'description' => $request->input('description'),
            'is_enabled'  => true,
        ]);

        // 同步到 Redis
        $client->syncToRedis();

        // 创建时返回完整信息（含 secret），仅此一次
        return $this->ok([
            'id'          => $client->id,
            'name'        => $client->name,
            'app_id'      => $client->app_id,
            'app_token'   => $client->app_token,
            'app_secret'  => $client->app_secret,
            'description' => $client->description,
            'is_enabled'  => $client->is_enabled,
            'created_at'  => $client->created_at,
        ]);
    }

    /**
     * 更新应用信息（不可修改 token/secret）
     *
     * POST /app-client/update
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'id'          => 'required|integer|exists:v3_app_clients,id',
            'name'        => 'nullable|string|max:100|unique:v3_app_clients,name,' . $request->input('id'),
            'app_id'      => 'nullable|string|max:64|unique:v3_app_clients,app_id,' . $request->input('id'),
            'description' => 'nullable|string|max:500',
            'is_enabled'  => 'nullable|boolean',
        ]);

        $client = AppClient::findOrFail($request->input('id'));
        $oldAppId = $client->app_id;

        $client->update(array_filter($request->only(['name', 'app_id', 'description', 'is_enabled']), fn($v) => $v !== null));

        // 如果 app_id 变更，删除旧的 Redis key
        if ($request->has('app_id') && $oldAppId !== $client->app_id) {
            $oldKey = AppClient::redisKeyPrefix() . $oldAppId;
            \Illuminate\Support\Facades\Redis::del($oldKey);
        }

        // 同步到 Redis
        $client->syncToRedis();

        return $this->ok($client);
    }

    /**
     * 删除应用
     *
     * POST /app-client/drop
     */
    public function drop(Request $request): JsonResponse
    {
        $request->validate([
            'id' => 'required|integer|exists:v3_app_clients,id',
        ]);

        $client = AppClient::findOrFail($request->input('id'));

        // 从 Redis 中移除
        $client->removeFromRedis();

        $client->delete();

        return $this->ok();
    }

    /**
     * 重置 Token 和 Secret
     *
     * POST /app-client/resetSecret
     */
    public function resetSecret(Request $request): JsonResponse
    {
        $request->validate([
            'id' => 'required|integer|exists:v3_app_clients,id',
        ]);

        $client = AppClient::findOrFail($request->input('id'));
        $client->update([
            'app_token'  => AppClient::generateToken(),
            'app_secret' => AppClient::generateSecret(),
        ]);

        // 同步新凭证到 Redis
        $client->syncToRedis();

        // 重置后返回完整信息（含新 secret）
        return $this->ok([
            'id'          => $client->id,
            'name'        => $client->name,
            'app_id'      => $client->app_id,
            'app_token'   => $client->app_token,
            'app_secret'  => $client->app_secret,
            'description' => $client->description,
            'is_enabled'  => $client->is_enabled,
        ]);
    }

    /**
     * 应用详情
     *
     * GET /app-client/detail
     */
    public function detail(Request $request): JsonResponse
    {
        $request->validate([
            'id' => 'required|integer|exists:v3_app_clients,id',
        ]);

        $client = AppClient::findOrFail($request->input('id'));

        return $this->ok($client);
    }
}
