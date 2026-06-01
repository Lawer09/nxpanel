<?php

namespace App\Services\Automation;

use App\Jobs\SendEmailJob;
use App\Models\AutomationRule;
use App\Models\AutomationRuleState;
use App\Models\Project;
use App\Models\User;
use App\Services\Automation\Contracts\AutomationModuleHandler;
use App\Services\TelegramService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProjectAggregateAutomationService implements AutomationModuleHandler
{
    public const MODULE_KEY = 'project_aggregate';
    public const TARGET_TYPE = 'project_daily_aggregate';

    private const EXEC_STATUS_TRIGGERED = 'triggered';
    private const EXEC_STATUS_RECOVERED = 'recovered';
    private const EXEC_STATUS_SKIPPED = 'skipped';
    private const EXEC_STATUS_FAILED = 'failed';

    public function __construct(
        protected AutomationExecutionLogService $executionLogService
    ) {}

    /**
     * 返回模块标识。
     */
    public function moduleKey(): string
    {
        return self::MODULE_KEY;
    }

    /**
     * 返回模块默认目标类型。
     */
    public function defaultTargetType(): string
    {
        return self::TARGET_TYPE;
    }

    /**
     * 返回模块支持的策略 model 列表。
     */
    public function supportedModels(): array
    {
        return [
            [
                'model' => self::TARGET_TYPE,
                'name' => 'Project Daily Aggregate',
                'module' => self::MODULE_KEY,
                'default' => true,
            ],
        ];
    }

    /**
     * 执行 project_aggregate 模块自动化规则。
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
            $metrics = $this->buildMetrics($target);
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
                    Log::error('ProjectAggregate automation action failed', [
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
                    Log::error('ProjectAggregate automation recovery failed', [
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
     * 查询当天项目聚合目标（按项目维度，不区分国家）。
     */
    private function resolveTargets(AutomationRule $rule, array $projectCodeFilter): array
    {
        $scope = is_array($rule->target_scope_json) ? $rule->target_scope_json : [];
        $scopeProjectCodes = array_values(array_filter(array_map('strval', (array) ($scope['projectCodes'] ?? []))));
        $today = now()->toDateString();

        $query = DB::table('project_daily_aggregates')
            ->selectRaw('project_code')
            ->selectRaw('SUM(new_users) as new_users')
            ->selectRaw('SUM(report_new_users) as report_new_users')
            ->selectRaw('SUM(fb_new_users) as fb_new_users')
            ->selectRaw('SUM(dau_users) as dau_users')
            ->selectRaw('SUM(fb_dau_users) as fb_dau_users')
            ->selectRaw('SUM(ad_revenue) as ad_revenue')
            ->selectRaw('SUM(ad_impressions) as ad_impressions')
            ->selectRaw('SUM(ad_spend_cost) as ad_spend_cost')
            ->selectRaw('SUM(traffic_cost) as traffic_cost')
            ->selectRaw('SUM(profit) as profit')
            ->selectRaw('AVG(roi) as roi')
            ->selectRaw('AVG(ad_spend_cpi) as ad_spend_cpi')
            ->selectRaw('CASE WHEN SUM(ad_impressions)=0 THEN NULL ELSE ROUND(SUM(ad_revenue)/SUM(ad_impressions)*1000,6) END as ad_ecpm')
            ->whereDate('report_date', $today)
            ->groupBy('project_code')
            ->orderBy('project_code');

        if (!empty($scopeProjectCodes)) {
            $query->whereIn('project_code', $scopeProjectCodes);
        }
        if (!empty($projectCodeFilter)) {
            $query->whereIn('project_code', $projectCodeFilter);
        }

        $rows = $query->get()->map(fn ($row) => (array) $row)->values()->all();
        if (empty($rows)) {
            return [];
        }

        $codes = array_values(array_filter(array_unique(array_map(static fn ($row) => (string) ($row['project_code'] ?? ''), $rows))));
        $nameMap = Project::query()->whereIn('project_code', $codes)->pluck('project_name', 'project_code')->all();

        foreach ($rows as &$row) {
            $code = (string) ($row['project_code'] ?? '');
            $row['project_name'] = (string) ($nameMap[$code] ?? $code);
        }

        return $rows;
    }

    /**
     * 构建目标项目指标。
     */
    private function buildMetrics(array $target): array
    {
        return [
            'project_code' => (string) ($target['project_code'] ?? ''),
            'project_name' => (string) ($target['project_name'] ?? ''),
            'new_users' => (int) ($target['new_users'] ?? 0),
            'report_new_users' => (int) ($target['report_new_users'] ?? 0),
            'fb_new_users' => (int) ($target['fb_new_users'] ?? 0),
            'dau_users' => (int) ($target['dau_users'] ?? 0),
            'fb_dau_users' => (int) ($target['fb_dau_users'] ?? 0),
            'ad_revenue' => (float) ($target['ad_revenue'] ?? 0),
            'ad_spend_cost' => (float) ($target['ad_spend_cost'] ?? 0),
            'traffic_cost' => (float) ($target['traffic_cost'] ?? 0),
            'profit' => (float) ($target['profit'] ?? 0),
            'roi' => $target['roi'] !== null ? (float) $target['roi'] : null,
            'ad_spend_cpi' => $target['ad_spend_cpi'] !== null ? (float) $target['ad_spend_cpi'] : null,
            // 按项目聚合口径重算 ad_ecpm：SUM(ad_revenue)/SUM(ad_impressions)*1000。
            'ad_ecpm' => $target['ad_ecpm'] !== null ? (float) $target['ad_ecpm'] : null,
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
     * 创建或读取规则状态。
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
     * 批量执行动作。
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

        return match ($type) {
            'telegram_admin' => $this->dispatchTelegramAction($action, $context, $recovery),
            'email' => $this->dispatchEmailAction($action, $context, $recovery),
            default => [
                'type' => $type,
                'ok' => false,
                'message' => 'unsupported action type',
            ],
        };
    }

    /**
     * Telegram 通知动作。
     */
    private function dispatchTelegramAction(array $action, array $context, bool $recovery): array
    {
        $template = $recovery
            ? (string) ($action['recoverTemplate'] ?? '[Project Recovery] {rule_name} | {project_name}({project_code}) | ad_ecpm={ad_ecpm}')
            : (string) ($action['template'] ?? '[Project Alert] {rule_name} | {project_name}({project_code}) | profit={profit} | roi={roi} | ad_ecpm={ad_ecpm}');

        $message = $this->renderTemplate($template, $context);
        (new TelegramService())->sendMessageWithAdmin($message);

        return [
            'type' => 'telegram_admin',
            'ok' => true,
        ];
    }

    /**
     * 邮件通知动作。
     */
    private function dispatchEmailAction(array $action, array $context, bool $recovery): array
    {
        $template = $recovery
            ? (string) ($action['recoverTemplate'] ?? '[Project Recovery] {rule_name} | {project_name}({project_code}) | ad_ecpm={ad_ecpm}')
            : (string) ($action['template'] ?? '[Project Alert] {rule_name} | {project_name}({project_code}) | profit={profit} | roi={roi} | ad_ecpm={ad_ecpm}');

        $subjectTemplate = $recovery
            ? (string) ($action['recoverSubject'] ?? '[Project Aggregate] Recovered - {rule_name}')
            : (string) ($action['subject'] ?? '[Project Aggregate] Alert - {rule_name}');

        $message = $this->renderTemplate($template, $context);
        $subject = $this->renderTemplate($subjectTemplate, $context);

        $receivers = array_values(array_filter((array) ($action['recipients'] ?? [])));
        $toAdmin = !array_key_exists('toAdmin', $action) || (int) $action['toAdmin'] === 1;
        if ($toAdmin) {
            $adminEmails = User::query()->where('is_admin', 1)->whereNotNull('email')->pluck('email')->all();
            $receivers = array_values(array_unique(array_merge($receivers, $adminEmails)));
        }

        foreach ($receivers as $email) {
            SendEmailJob::dispatch([
                'email' => $email,
                'subject' => $subject,
                'template_name' => 'notify',
                'template_value' => [
                    'name' => admin_setting('app_name', 'NxPanel'),
                    'content' => $message,
                    'url' => admin_setting('app_url'),
                ],
            ]);
        }

        return [
            'type' => 'email',
            'ok' => true,
            'receiver_count' => count($receivers),
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

    /**
     * 模板渲染，替换 {placeholder}。
     */
    private function renderTemplate(string $template, array $context): string
    {
        return (string) preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function ($matches) use ($context) {
            $key = $matches[1] ?? '';
            $value = $context[$key] ?? '';
            return is_scalar($value) || $value === null ? (string) $value : json_encode($value);
        }, $template);
    }
}
