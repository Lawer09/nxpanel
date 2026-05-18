<?php

namespace App\Http\Controllers\V3\Admin\TrafficPlatform;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IdRequest;
use App\Http\Requests\Admin\TrafficPlatformSyncJobIndexRequest;
use App\Http\Requests\Admin\TrafficPlatformSyncTriggerRequest;
use App\Http\Resources\CamelizeResource;
use App\Services\TrafficPlatform\TrafficPlatformSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TrafficPlatformSyncController extends Controller
{
    public function __construct(
        protected TrafficPlatformSyncService $service
    ) {}

    /**
     * 手动触发同步
     * POST /traffic-platform/sync
     *
     * 转发给 Go 内部接口
     */
    public function trigger(TrafficPlatformSyncTriggerRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $account = $this->service->findAccountOrFail((int) $params['accountId']);

            // 简化对接：platformCode 可不传，默认按账号绑定的平台编码
            $platformCode = (string) ($account->platform_code ?? '');
            if (!empty($params['platformCode'])) {
                $inputPlatformCode = (string) $params['platformCode'];
                if ($inputPlatformCode !== $platformCode) {
                    return $this->error([422, 'platformCode与账号不匹配']);
                }
            }

            $goUrl = 'http://47.254.131.223:8080/api/traffic-platform/sync';

            $response = Http::timeout(15)->post($goUrl, [
                'account_id'    => $params['accountId'],
                'platform_code' => $platformCode,
                'start_date'    => $params['startDate'],
                'end_date'      => $params['endDate'],
            ]);

            if ($response->successful()) {
                $body = $response->json();
                return $this->ok($body['data'] ?? $body);
            }

            return $this->error([502, '触发同步失败: ' . $response->body()]);
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatformSync trigger error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 同步任务列表
     * GET /traffic-platform/sync-jobs
     */
    public function index(TrafficPlatformSyncJobIndexRequest $request): JsonResponse
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
            Log::error('TrafficPlatformSync index error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 同步任务详情
     * GET /traffic-platform/sync-jobs/detail?id=
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
            Log::error('TrafficPlatformSync detail error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }
}
