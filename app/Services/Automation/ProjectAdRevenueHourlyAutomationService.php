<?php

namespace App\Services\Automation;

use App\Models\AutomationRule;
use App\Models\AutomationRuleState;
use App\Services\Automation\Contracts\AutomationModuleHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProjectAdRevenueHourlyAutomationService implements AutomationModuleHandler
{
    public const MODULE_KEY = 'project_ad_revenue_hourly';
    public const TARGET_TYPE = 'project_ad_revenue_hourly';

    private const SOURCE_PLATFORM = 'admob';
    private const REPORT_TYPE = 'network';

    private const EXEC_STATUS_TRIGGERED = 'triggered';
    private const EXEC_STATUS_RECOVERED = 'recovered';
    private const EXEC_STATUS_SKIPPED = 'skipped';
    private const EXEC_STATUS_FAILED = 'failed';

    public function __construct(
        protected AutomationExecutionLogService $executionLogService,
        protected AutomationActionDispatcher $actionDispatcher
    ) {}

    /**
     * 返回模块标识。
     */
    public function moduleKey(): string
    {
        return self::MODULE_KEY;
    }

    /**
     * 返回默认目标类型。
     */
    public function defaultTargetType(): string
    {
        return self::TARGET_TYPE;
    }

    /**
     * 返回模块支持的前端 model 列表。
     */
    public function supportedModels(): array
    {
        return [
            [
                'model' => self::TARGET_TYPE,
                'name' => 'Project Ad Revenue Hourly',
                'module' => self::MODULE_KEY,
                'default' => true,
            ],
        ];
    }

    /**
     * 运行项目小时广告自动化规则。
     */
    public function run(array $params = []): array
    {
        $ruleId = isset($params['ruleId']) ? (int) $params['ruleId'] : null;
        $projectCodes = array_values(array_filter(array_map('strval', (array) ($params['targetIds'] ?? []))));
        $dryRun = (bool) ($params['dryRun'] ?? false);
        $logUnmatched = $dryRun || $ruleId !== null || !empty($projectCodes);

        $query = AutomationRule::query()
            ->where('module', self::MODULE_KEY)
            ->where('enabled', 1);

        if ($ruleId) {
            $query->where('id', $ruleId);
        }

        $rules = $query->orderBy('id')->get();

        $summary = [
            'ruleCount' => $rules->count(),
            'ruleIds' => $rules->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'targetCount' => 0,
            'targetIds' => [],
            'triggeredCount' => 0,
            'recoveredCount' => 0,
            'skippedCount' => 0,
            'failedCount' => 0,
            'dryRun' => $dryRun,
        ];

        foreach ($rules as $rule) {
            $ruleSummary = $this->runRule($rule, $projectCodes, $dryRun, $logUnmatched);
            $summary['targetCount'] += $ruleSummary['targetCount'];
            $summary['targetIds'] = array_values(array_unique(array_merge($summary['targetIds'], $ruleSummary['targetIds'])));
            $summary['triggeredCount'] += $ruleSummary['triggeredCount'];
            $summary['recoveredCount'] += $ruleSummary['recoveredCount'];
            $summary['skippedCount'] += $ruleSummary['skippedCount'];
            $summary['failedCount'] += $ruleSummary['failedCount'];
        }

        return $summary;
    }

    /**
     * 执行单条规则。
     */
    private function runRule(AutomationRule $rule, array $projectCodeFilter, bool $dryRun, bool $logUnmatched): array
    {
        $targets = $this->resolveTargets($rule, $projectCodeFilter);
        $scope = is_array($rule->target_scope_json) ? $rule->target_scope_json : [];
        $includeDisabled = (int) ($scope['includeDisabled'] ?? 0) === 1;
        $metricsMap = $this->loadHourlyMetrics(array_column($targets, 'project_code'), $includeDisabled);
        $now = now();

        $summary = [
            'targetCount' => count($targets),
            'targetIds' => array_values(array_map(static fn ($item) => (string) ($item['project_code'] ?? ''), $targets)),
            'triggeredCount' => 0,
            'recoveredCount' => 0,
            'skippedCount' => 0,
            'failedCount' => 0,
        ];

        foreach ($targets as $target) {
            $metrics = $this->buildMetrics($target, $metricsMap[(string) $target['project_code']] ?? null);
            $evaluation = $this->evaluate($rule, $metrics);
            $state = $this->getOrCreateState($rule, (string) $target['project_code']);

            $state->last_evaluation_at = $now;
            $state->save();

            if ($evaluation['matched']) {
                if ($state->suppress_until && $state->suppress_until->gt($now)) {
                    $summary['skippedCount']++;
                    $this->logExecution($rule, $target, self::EXEC_STATUS_SKIPPED, $metrics, $evaluation['details'], [
                        'reason' => 'cooldown',
                        'suppress_until' => $state->suppress_until->toDateTimeString(),
                    ], null);
                    continue;
                }

                if ($dryRun) {
                    $summary['skippedCount']++;
                    $this->logExecution($rule, $target, self::EXEC_STATUS_SKIPPED, $metrics, $evaluation['details'], [
                        'reason' => 'dry_run',
                    ], null);
                    continue;
                }

                try {
                    $actionResults = $this->dispatchActions($rule, $target, $metrics, false);
                    $state->status = AutomationRuleState::STATUS_ALERTING;
                    $state->last_triggered_at = $now;
                    $state->last_fingerprint = $evaluation['fingerprint'];
                    $state->suppress_until = $now->copy()->addSeconds(max(0, (int) $rule->cooldown_seconds));
                    $state->save();

                    $summary['triggeredCount']++;
                    $this->logExecution($rule, $target, self::EXEC_STATUS_TRIGGERED, $metrics, $evaluation['details'], $rule->actions_json, $actionResults);
                } catch (\Throwable $e) {
                    $summary['failedCount']++;
                    Log::error('ProjectAdRevenueHourly automation action failed', [
                        'rule_id' => $rule->id,
                        'target_id' => $target['project_code'] ?? '',
                        'error' => $e->getMessage(),
                    ]);

                    $this->logExecution($rule, $target, self::EXEC_STATUS_FAILED, $metrics, $evaluation['details'], $rule->actions_json, null, $e->getMessage());
                }

                continue;
            }

            if ($state->status === AutomationRuleState::STATUS_ALERTING && (int) $rule->recovery_enabled === 1) {
                if ($dryRun) {
                    $summary['skippedCount']++;
                    $this->logExecution($rule, $target, self::EXEC_STATUS_SKIPPED, $metrics, $evaluation['details'], [
                        'reason' => 'dry_run_recovery',
                    ], null);
                    continue;
                }

                try {
                    $actionResults = $this->dispatchActions($rule, $target, $metrics, true);
                    $state->status = AutomationRuleState::STATUS_NORMAL;
                    $state->last_recovered_at = $now;
                    $state->suppress_until = null;
                    $state->last_fingerprint = null;
                    $state->save();

                    $summary['recoveredCount']++;
                    $this->logExecution($rule, $target, self::EXEC_STATUS_RECOVERED, $metrics, $evaluation['details'], $rule->actions_json, $actionResults);
                } catch (\Throwable $e) {
                    $summary['failedCount']++;
                    Log::error('ProjectAdRevenueHourly automation recovery failed', [
                        'rule_id' => $rule->id,
                        'target_id' => $target['project_code'] ?? '',
                        'error' => $e->getMessage(),
                    ]);

                    $this->logExecution($rule, $target, self::EXEC_STATUS_FAILED, $metrics, $evaluation['details'], $rule->actions_json, null, $e->getMessage());
                }

                continue;
            }

            if ($logUnmatched) {
                $summary['skippedCount']++;
                $this->logExecution($rule, $target, self::EXEC_STATUS_SKIPPED, $metrics, $evaluation['details'], [
                    'reason' => 'condition_not_matched',
                ], null);
            }
        }

        return $summary;
    }

    /**
     * 解析规则目标项目集合。
     */
    private function resolveTargets(AutomationRule $rule, array $projectCodeFilter): array
    {
        $scope = is_array($rule->target_scope_json) ? $rule->target_scope_json : [];
        $scopeProjectCodes = array_values(array_filter(array_map('strval', (array) ($scope['projectCodes'] ?? []))));
        $includeDisabled = (int) ($scope['includeDisabled'] ?? 0) === 1;

        $query = DB::table('project_ad_platform_accounts as papa')
            ->join('project_projects as p', 'p.project_code', '=', 'papa.project_code')
            ->selectRaw('p.project_code as project_code')
            ->selectRaw('MAX(p.project_name) as project_name')
            ->where('papa.platform_code', self::SOURCE_PLATFORM)
            ->whereNotNull('papa.project_code')
            ->where('papa.project_code', '!=', '');

        if (!$includeDisabled) {
            $query->where('papa.enabled', '=', 1);
        }

        if (!empty($scopeProjectCodes)) {
            $query->whereIn('papa.project_code', $scopeProjectCodes);
        }

        if (!empty($projectCodeFilter)) {
            $query->whereIn('papa.project_code', $projectCodeFilter);
        }

        return $query->groupBy('p.project_code')
            ->orderBy('p.project_code')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->values()
            ->all();
    }

    /**
     * 加载上一完整小时的项目聚合指标。
     */
    private function loadHourlyMetrics(array $projectCodes, bool $includeDisabled): array
    {
        $projectCodes = array_values(array_filter(array_unique(array_map('strval', $projectCodes))));
        if (empty($projectCodes)) {
            return [];
        }

        $reportHour = now()->startOfHour()->subHour()->toDateTimeString();

        $mappingQuery = DB::table('project_ad_platform_accounts as papa')
            ->selectRaw('DISTINCT papa.project_code, papa.ad_platform_account_id')
            ->where('papa.platform_code', self::SOURCE_PLATFORM)
            ->whereNotNull('papa.project_code')
            ->where('papa.project_code', '!=', '')
            ->whereIn('papa.project_code', $projectCodes);

        if (!$includeDisabled) {
            $mappingQuery->where('papa.enabled', '=', 1);
        }

        $hourlyQuery = DB::table('ad_revenue_hourly as arh')
            ->selectRaw('arh.account_id')
            ->selectRaw('COUNT(*) as row_count')
            ->selectRaw('SUM(arh.ad_requests) as ad_requests')
            ->selectRaw('SUM(arh.matched_requests) as matched_requests')
            ->selectRaw('SUM(arh.impressions) as impressions')
            ->selectRaw('SUM(arh.clicks) as clicks')
            ->selectRaw('SUM(arh.estimated_earnings_micros) as estimated_earnings_micros')
            ->selectRaw('SUM(arh.estimated_earnings) as estimated_earnings')
            ->selectRaw('SUM(CASE WHEN arh.is_anomaly = 1 THEN 1 ELSE 0 END) as anomaly_count')
            ->where('arh.source_platform', self::SOURCE_PLATFORM)
            ->where('arh.report_type', self::REPORT_TYPE)
            ->where('arh.report_hour', '=', $reportHour)
            ->groupBy('arh.account_id');

        $rows = DB::query()
            ->fromSub($mappingQuery, 'map')
            ->leftJoinSub($hourlyQuery, 'hourly', function ($join) {
                $join->on('hourly.account_id', '=', 'map.ad_platform_account_id');
            })
            ->selectRaw('map.project_code as project_code')
            ->selectRaw('COALESCE(SUM(hourly.row_count), 0) as row_count')
            ->selectRaw('COALESCE(SUM(hourly.ad_requests), 0) as ad_requests')
            ->selectRaw('COALESCE(SUM(hourly.matched_requests), 0) as matched_requests')
            ->selectRaw('COALESCE(SUM(hourly.impressions), 0) as impressions')
            ->selectRaw('COALESCE(SUM(hourly.clicks), 0) as clicks')
            ->selectRaw('COALESCE(SUM(hourly.estimated_earnings_micros), 0) as estimated_earnings_micros')
            ->selectRaw('COALESCE(SUM(hourly.estimated_earnings), 0) as estimated_earnings')
            ->selectRaw('COALESCE(SUM(hourly.anomaly_count), 0) as anomaly_count')
            ->groupBy('map.project_code')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $rowCount = (int) ($row->row_count ?? 0);
            $adRequests = (int) ($row->ad_requests ?? 0);
            $matchedRequests = (int) ($row->matched_requests ?? 0);
            $impressions = (int) ($row->impressions ?? 0);
            $clicks = (int) ($row->clicks ?? 0);
            $estimatedEarningsMicros = (int) ($row->estimated_earnings_micros ?? 0);
            $estimatedEarnings = (float) ($row->estimated_earnings ?? 0);

            $result[(string) $row->project_code] = [
                'report_hour' => $reportHour,
                'has_data' => $rowCount > 0 ? 1 : 0,
                'row_count' => $rowCount,
                'ad_requests' => $adRequests,
                'matched_requests' => $matchedRequests,
                'match_rate' => $adRequests > 0 ? round($matchedRequests / $adRequests, 6) : 0.0,
                'impressions' => $impressions,
                'show_rate' => $matchedRequests > 0 ? round($impressions / $matchedRequests, 6) : 0.0,
                'clicks' => $clicks,
                'ctr' => $impressions > 0 ? round($clicks / $impressions, 6) : 0.0,
                'estimated_earnings_micros' => $estimatedEarningsMicros,
                'estimated_earnings' => round($estimatedEarnings, 6),
                'ecpm_micros' => $impressions > 0 ? (int) round(($estimatedEarningsMicros * 1000) / $impressions) : 0,
                'ecpm' => $impressions > 0 ? round(($estimatedEarnings * 1000) / $impressions, 6) : 0.0,
                'anomaly_count' => (int) ($row->anomaly_count ?? 0),
            ];
        }

        return $result;
    }

    /**
     * 构建项目指标快照。
     */
    private function buildMetrics(array $target, ?array $usage): array
    {
        $defaults = [
            'report_hour' => now()->startOfHour()->subHour()->toDateTimeString(),
            'has_data' => 0,
            'row_count' => 0,
            'ad_requests' => 0,
            'matched_requests' => 0,
            'match_rate' => 0.0,
            'impressions' => 0,
            'show_rate' => 0.0,
            'clicks' => 0,
            'ctr' => 0.0,
            'estimated_earnings_micros' => 0,
            'estimated_earnings' => 0.0,
            'ecpm_micros' => 0,
            'ecpm' => 0.0,
            'anomaly_count' => 0,
        ];

        $usage = array_merge($defaults, $usage ?? []);

        return [
            'project_code' => (string) ($target['project_code'] ?? ''),
            'project_name' => (string) ($target['project_name'] ?? $target['project_code'] ?? ''),
            'report_hour' => (string) $usage['report_hour'],
            'has_data' => (int) $usage['has_data'],
            'row_count' => (int) $usage['row_count'],
            'ad_requests' => (int) $usage['ad_requests'],
            'matched_requests' => (int) $usage['matched_requests'],
            'match_rate' => (float) $usage['match_rate'],
            'impressions' => (int) $usage['impressions'],
            'show_rate' => (float) $usage['show_rate'],
            'clicks' => (int) $usage['clicks'],
            'ctr' => (float) $usage['ctr'],
            'estimated_earnings_micros' => (int) $usage['estimated_earnings_micros'],
            'estimated_earnings' => (float) $usage['estimated_earnings'],
            'ecpm_micros' => (int) $usage['ecpm_micros'],
            'ecpm' => (float) $usage['ecpm'],
            'anomaly_count' => (int) $usage['anomaly_count'],
        ];
    }

    /**
     * 评估规则条件。
     */
    private function evaluate(AutomationRule $rule, array $metrics): array
    {
        $logic = (string) $rule->condition_logic;
        $conditions = is_array($rule->conditions_json) ? $rule->conditions_json : [];
        $details = [];
        $matchedCount = 0;

        foreach ($conditions as $condition) {
            $metric = (string) ($condition['metric'] ?? '');
            $operator = (string) ($condition['operator'] ?? 'eq');
            $expected = $condition['value'] ?? null;
            $actual = $metrics[$metric] ?? null;
            $result = $this->compare($actual, $operator, $expected);

            if ($result) {
                $matchedCount++;
            }

            $details[] = [
                'metric' => $metric,
                'operator' => $operator,
                'expected' => $expected,
                'actual' => $actual,
                'result' => $result,
            ];
        }

        $matched = false;
        if (!empty($conditions)) {
            $matched = $logic === AutomationRule::LOGIC_ANY
                ? $matchedCount > 0
                : $matchedCount === count($conditions);
        }

        return [
            'matched' => $matched,
            'details' => $details,
            'fingerprint' => sha1(json_encode($details)),
        ];
    }

    /**
     * 条件比较器。
     */
    private function compare($actual, string $operator, $expected): bool
    {
        return match ($operator) {
            'eq' => $actual == $expected,
            'neq' => $actual != $expected,
            'gt' => $actual > $expected,
            'gte' => $actual >= $expected,
            'lt' => $actual < $expected,
            'lte' => $actual <= $expected,
            'in' => is_array($expected) && in_array($actual, $expected, true),
            'not_in' => is_array($expected) && !in_array($actual, $expected, true),
            'between' => is_array($expected)
                && count($expected) === 2
                && $actual >= $expected[0]
                && $actual <= $expected[1],
            default => false,
        };
    }

    /**
     * 获取或创建规则状态。
     */
    private function getOrCreateState(AutomationRule $rule, string $projectCode): AutomationRuleState
    {
        return AutomationRuleState::query()->firstOrCreate(
            [
                'rule_id' => $rule->id,
                'target_type' => self::TARGET_TYPE,
                'target_id' => $projectCode,
            ],
            [
                'status' => AutomationRuleState::STATUS_NORMAL,
            ]
        );
    }

    /**
     * 批量分发动作。
     */
    private function dispatchActions(AutomationRule $rule, array $target, array $metrics, bool $recovery): array
    {
        $actions = is_array($rule->actions_json) ? $rule->actions_json : [];
        $results = [];

        foreach ($actions as $action) {
            $results[] = $this->dispatchOneAction((array) $action, $rule, $target, $metrics, $recovery);
        }

        return $results;
    }

    /**
     * 执行单个动作。
     */
    private function dispatchOneAction(array $action, AutomationRule $rule, array $target, array $metrics, bool $recovery): array
    {
        $type = (string) ($action['type'] ?? '');
        $context = array_merge($metrics, [
            'rule_name' => $rule->name,
            'rule_id' => $rule->id,
            'status' => $recovery ? 'recovered' : 'alert',
            'target_name' => (string) ($target['project_name'] ?? $target['project_code'] ?? ''),
        ]);

        if ($this->actionDispatcher->supports($type)) {
            $meta = [
                'event' => $recovery ? 'recovered' : 'triggered',
                'module' => self::MODULE_KEY,
                'moduleLabel' => 'Project Ad Revenue Hourly',
                'ruleId' => (int) $rule->id,
                'ruleName' => (string) $rule->name,
                'targetType' => self::TARGET_TYPE,
                'targetId' => (string) ($target['project_code'] ?? ''),
                'targetName' => (string) ($target['project_name'] ?? ''),
                'defaultAlertTemplate' => '[Project Hourly Ad Alert] {rule_name} | {project_name}({project_code}) | hour={report_hour} | has_data={has_data} | revenue={estimated_earnings} | imp={impressions} | ctr={ctr} | ecpm={ecpm}',
                'defaultRecoverTemplate' => '[Project Hourly Ad Recovery] {rule_name} | {project_name}({project_code}) | hour={report_hour} | revenue={estimated_earnings}',
            ];

            return $this->actionDispatcher->dispatch($action, $context, $meta);
        }

        return [
            'type' => $type,
            'ok' => false,
            'message' => 'unsupported action type',
        ];
    }

    /**
     * 写入执行日志。
     */
    private function logExecution(
        AutomationRule $rule,
        array $target,
        string $status,
        array $metrics,
        array $details,
        $actions,
        ?array $actionResults,
        ?string $errorMessage = null
    ): void {
        $targetCode = (string) ($target['project_code'] ?? '');
        $targetName = (string) ($target['project_name'] ?? $targetCode);

        $record = [
            'rule_id' => $rule->id,
            'rule_name' => $rule->name,
            'module' => $rule->module,
            'target_type' => self::TARGET_TYPE,
            'target_id' => $targetCode,
            'target_name' => $targetName,
            'status' => $status,
            'metrics_snapshot' => $metrics,
            'matched_conditions' => $details,
            'actions_snapshot' => $actions,
            'action_results' => $actionResults,
            'error_message' => $errorMessage ? mb_substr($errorMessage, 0, 2000) : null,
            'executed_at' => now()->toDateTimeString(),
        ];

        $this->executionLogService->appendExecution(self::MODULE_KEY, $record);
    }
}
