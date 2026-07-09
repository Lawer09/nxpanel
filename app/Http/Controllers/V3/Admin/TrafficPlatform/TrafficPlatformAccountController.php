<?php

namespace App\Http\Controllers\V3\Admin\TrafficPlatform;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IdRequest;
use App\Http\Requests\Admin\TrafficPlatformAccountIndexRequest;
use App\Http\Requests\Admin\TrafficPlatformAccountStoreRequest;
use App\Http\Requests\Admin\TrafficPlatformAccountUpdateTagsRequest;
use App\Http\Requests\Admin\TrafficPlatformAccountUpdateRequest;
use App\Http\Requests\Admin\TrafficPlatformAccountUpdateStatusRequest;
use App\Http\Resources\CamelizeResource;
use App\Services\TrafficPlatform\TrafficPlatformAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TrafficPlatformAccountController extends Controller
{
    public function __construct(
        protected TrafficPlatformAccountService $service
    ) {}

    /**
     * 账号列表
     * GET /traffic-platform/accounts
     */
    public function index(TrafficPlatformAccountIndexRequest $request): JsonResponse
    {
        try {
            $result = $this->service->index($request->validated());

            return $this->ok([
                'page' => $result['page'],
                'pageSize' => $result['pageSize'],
                'total' => $result['total'],
                'data' => CamelizeResource::collection($result['data']),
            ]);
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatformAccount index error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 账号详情
     * GET /traffic-platform/accounts/detail?id=
     */
    public function detail(IdRequest $request): JsonResponse
    {
        try {
            $id = (int) $request->validated()['id'];
            $arr = $this->service->detail($id);

            return $this->ok(CamelizeResource::make($arr));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatformAccount detail error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 新增账号
     * POST /traffic-platform/accounts/create
     */
    public function store(TrafficPlatformAccountStoreRequest $request): JsonResponse
    {
        try {
            $account = $this->service->store($request->validated());

            return $this->ok(CamelizeResource::make($account));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatformAccount store error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 修改账号
     * POST /traffic-platform/accounts/update
     */
    public function update(TrafficPlatformAccountUpdateRequest $request): JsonResponse
    {
        try {
            $account = $this->service->update($request->validated());
            return $this->ok(CamelizeResource::make($account));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatformAccount update error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * Update account tags.
     * POST /traffic-platform/accounts/update-tags
     */
    public function updateTags(TrafficPlatformAccountUpdateTagsRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $account = $this->service->updateTags((int) $params['id'], $params['tags']);

            return $this->ok(CamelizeResource::make($account));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatformAccount updateTags error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 启用/禁用账号
     * POST /traffic-platform/accounts/update-status
     */
    public function updateStatus(TrafficPlatformAccountUpdateStatusRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $this->service->updateStatus((int) $params['id'], (int) $params['enabled']);

            return $this->ok(true);
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatformAccount updateStatus error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 测试账号连接
     * POST /traffic-platform/accounts/test
     *
     * 转发给 Go 内部接口
     */
    public function test(IdRequest $request): JsonResponse
    {
        try {
            $id = (int) $request->validated()['id'];
            $this->service->findForTest($id);

            $baseUrl = rtrim((string) config('services.traffic_platform_service.base_url', ''), '/');
            $apiKey = (string) config('services.traffic_platform_service.api_key', '');
            $timeout = (int) config('services.traffic_platform_service.timeout_seconds', 15);

            if ($baseUrl === '') {
                return $this->error([500, 'traffic platform service base_url is not configured']);
            }

            if ($apiKey === '') {
                return $this->error([500, 'traffic platform service api_key is not configured']);
            }

            $goUrl = $baseUrl . '/api/traffic-platform/accounts/' . $id . '/test';

            $response = Http::timeout(max(1, $timeout))
                ->withHeaders([
                    'X-API-Key' => $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($goUrl);

            if ($response->successful()) {
                $body = $response->json();
                return $this->ok($body['data'] ?? $body);
            }

            return $this->error([502, '测试连接失败: ' . $response->body()]);
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatformAccount test error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }
}
