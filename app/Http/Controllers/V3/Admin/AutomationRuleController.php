<?php

namespace App\Http\Controllers\V3\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AutomationExecutionIndexRequest;
use App\Http\Requests\Admin\AutomationRuleDetailRequest;
use App\Http\Requests\Admin\AutomationRuleIndexRequest;
use App\Http\Requests\Admin\AutomationRuleStoreRequest;
use App\Http\Requests\Admin\AutomationRuleUpdateRequest;
use App\Http\Requests\Admin\AutomationRuleUpdateStatusRequest;
use App\Http\Requests\Admin\AutomationRunRequest;
use App\Http\Resources\CamelizeResource;
use App\Services\Automation\AutomationExecutionLogService;
use App\Services\Automation\AutomationModuleRegistry;
use App\Services\Automation\AutomationRuleService;
use App\Services\Automation\AutomationRunnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class AutomationRuleController extends Controller
{
    public function __construct(
        protected AutomationRuleService $ruleService,
        protected AutomationRunnerService $runnerService,
        protected AutomationExecutionLogService $executionLogService,
        protected AutomationModuleRegistry $moduleRegistry
    ) {}

    /**
     * 自动化规则列表。
     * GET /automation-rules
     */
    public function index(AutomationRuleIndexRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $result = $this->ruleService->index((string) $params['module'], $params);

            return $this->ok([
                'page' => $result['page'],
                'pageSize' => $result['pageSize'],
                'total' => $result['total'],
                'data' => CamelizeResource::collection($result['data']),
            ]);
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('AutomationRule index error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 自动化规则详情。
     * GET /automation-rules/detail?id=&module=
     */
    public function detail(AutomationRuleDetailRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $rule = $this->ruleService->detail((string) $params['module'], (int) $params['id']);
            return $this->ok(CamelizeResource::make($rule));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('AutomationRule detail error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 创建自动化规则。
     * POST /automation-rules/create
     */
    public function store(AutomationRuleStoreRequest $request): JsonResponse
    {
        try {
            $rule = $this->ruleService->store($request->validated());
            return $this->ok(CamelizeResource::make($rule));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('AutomationRule store error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 更新自动化规则。
     * POST /automation-rules/update
     */
    public function update(AutomationRuleUpdateRequest $request): JsonResponse
    {
        try {
            $rule = $this->ruleService->update($request->validated());
            return $this->ok(CamelizeResource::make($rule));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('AutomationRule update error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 更新自动化规则状态。
     * POST /automation-rules/update-status
     */
    public function updateStatus(AutomationRuleUpdateStatusRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $this->ruleService->updateStatus((string) $params['module'], (int) $params['id'], (int) $params['enabled']);
            return $this->ok(true);
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('AutomationRule updateStatus error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 手动执行自动化规则。
     * POST /automation-rules/run
     */
    public function run(AutomationRunRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $summary = $this->runnerService->runByModule((string) $params['module'], [
                'ruleId' => isset($params['ruleId']) ? (int) $params['ruleId'] : null,
                'targetIds' => $params['targetIds'] ?? [],
                'dryRun' => (int) ($params['dryRun'] ?? 0) === 1,
            ]);

            return $this->ok(CamelizeResource::make($summary));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('AutomationRule run error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 查询自动化规则执行记录（每模块 Redis 最新 100 条）。
     * GET /automation-rules/executions
     */
    public function executions(AutomationExecutionIndexRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $this->moduleRegistry->getHandlerOrFail((string) $params['module']);
            $result = $this->executionLogService->listExecutions((string) $params['module'], $params);

            return $this->ok([
                'page' => $result['page'],
                'pageSize' => $result['pageSize'],
                'total' => $result['total'],
                'data' => CamelizeResource::collection($result['data']),
            ]);
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('AutomationRule executions error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }
}
