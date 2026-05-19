<?php

namespace App\Http\Controllers\V3\Admin\TrafficPlatform;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IdRequest;
use App\Http\Requests\Admin\TrafficPlatformAutomationRuleIndexRequest;
use App\Http\Requests\Admin\TrafficPlatformAutomationRuleStoreRequest;
use App\Http\Requests\Admin\TrafficPlatformAutomationRuleUpdateRequest;
use App\Http\Requests\Admin\TrafficPlatformAutomationRuleUpdateStatusRequest;
use App\Http\Requests\Admin\TrafficPlatformAutomationRunRequest;
use App\Http\Resources\CamelizeResource;
use App\Services\Automation\AutomationRuleService;
use App\Services\Automation\TrafficPlatformAutomationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TrafficPlatformAutomationRuleController extends Controller
{
    public function __construct(
        protected AutomationRuleService $ruleService,
        protected TrafficPlatformAutomationService $automationService
    ) {}

    /**
     * 自动化规则列表。
     * GET /traffic-platform/automation-rules
     */
    public function index(TrafficPlatformAutomationRuleIndexRequest $request): JsonResponse
    {
        try {
            $result = $this->ruleService->index($request->validated());

            return $this->ok([
                'page' => $result['page'],
                'pageSize' => $result['pageSize'],
                'total' => $result['total'],
                'data' => CamelizeResource::collection($result['data']),
            ]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatformAutomationRule index error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 自动化规则详情。
     * GET /traffic-platform/automation-rules/detail?id=
     */
    public function detail(IdRequest $request): JsonResponse
    {
        try {
            $id = (int) $request->validated()['id'];
            $rule = $this->ruleService->detail($id);

            return $this->ok(CamelizeResource::make($rule));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatformAutomationRule detail error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 创建自动化规则。
     * POST /traffic-platform/automation-rules/create
     */
    public function store(TrafficPlatformAutomationRuleStoreRequest $request): JsonResponse
    {
        try {
            $rule = $this->ruleService->store($request->validated());
            return $this->ok(CamelizeResource::make($rule));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatformAutomationRule store error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 更新自动化规则。
     * POST /traffic-platform/automation-rules/update
     */
    public function update(TrafficPlatformAutomationRuleUpdateRequest $request): JsonResponse
    {
        try {
            $rule = $this->ruleService->update($request->validated());
            return $this->ok(CamelizeResource::make($rule));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatformAutomationRule update error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 更新自动化规则状态。
     * POST /traffic-platform/automation-rules/update-status
     */
    public function updateStatus(TrafficPlatformAutomationRuleUpdateStatusRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $this->ruleService->updateStatus((int) $params['id'], (int) $params['enabled']);
            return $this->ok(true);
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatformAutomationRule updateStatus error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 手动执行自动化规则。
     * POST /traffic-platform/automation-rules/run
     */
    public function run(TrafficPlatformAutomationRunRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $summary = $this->automationService->run([
                'ruleId' => isset($params['ruleId']) ? (int) $params['ruleId'] : null,
                'accountIds' => $params['accountIds'] ?? [],
                'dryRun' => (int) ($params['dryRun'] ?? 0) === 1,
            ]);

            return $this->ok(CamelizeResource::make($summary));
        } catch (\Exception $e) {
            Log::error('TrafficPlatformAutomationRule run error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }
}
