<?php

namespace App\Services\Automation;

use App\Jobs\SendEmailJob;
use App\Models\AutomationRule;
use App\Models\AutomationRuleState;
use App\Models\TrafficPlatformAccount;
use App\Models\User;
use App\Services\Automation\Contracts\AutomationModuleHandler;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TrafficPlatformAutomationService implements AutomationModuleHandler
{
    public const MODULE_KEY = 'traffic_platform';
    public const TARGET_TYPE = 'traffic_platform_account';

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
     * 执行 traffic_platform 模块自动化规则。
     */
    public function run(array $params = []): array
    {
        $ruleId = isset($params['ruleId']) ? (int) $params['ruleId'] : null;
        $accountIds = (array) ($params['accountIds'] ?? $params['targetIds'] ?? []);
        $accountIds = array_values(array_filter(array_map('intval', $accountIds)));
        $dryRun = (bool) ($params['dryRun'] ?? false);

        $query = AutomationRule::query()
            ->where('module', self::MODULE_KEY)
            ->where('enabled', 1);

        if ($ruleId) {
            $query->where('id', $ruleId);
        }

        $rules = $query->orderBy('id')->get();

        $summary = [
            'ruleCount' => $rules->count(),
            'targetCount' => 0,
            'triggeredCount' => 0,
            'recoveredCount' => 0,
            'skippedCount' => 0,
            'failedCount' => 0,
            'dryRun' => $dryRun,
        ];

        foreach ($rules as $rule) {
            $ruleSummary = $this->runRule($rule, $accountIds, $dryRun);
            $summary['targetCount'] += $ruleSummary['targetCount'];
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
    private function runRule(AutomationRule $rule, array $accountIdFilter, bool $dryRun): array
    {
        $targets = $this->resolveTargets($rule, $accountIdFilter);
        $usageMap = $this->loadUsageMetrics($targets->pluck('id')->all());
        $now = now();

        $summary = [
            'targetCount' => $targets->count(),
            'triggeredCount' => 0,
            'recoveredCount' => 0,
            'skippedCount' => 0,
            'failedCount' => 0,
        ];

        foreach ($targets as $target) {
            $metrics = $this->buildMetrics($target, $usageMap[(int) $target->id] ?? []);
            $evaluation = $this->evaluate($rule, $metrics);
            $state = $this->getOrCreateState($rule, $target);

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
                    $this->logExecution(
                        $rule,
                        $target,
                        self::EXEC_STATUS_TRIGGERED,
                        $metrics,
                        $evaluation['details'],
                        $rule->actions_json,
                        $actionResults
                    );
                } catch (\Throwable $e) {
                    $summary['failedCount']++;
                    Log::error('TrafficPlatform automation action failed', [
                        'rule_id' => $rule->id,
                        'target_id' => $target->id,
                        'error' => $e->getMessage(),
                    ]);

                    $this->logExecution(
                        $rule,
                        $target,
                        self::EXEC_STATUS_FAILED,
                        $metrics,
                        $evaluation['details'],
                        $rule->actions_json,
                        null,
                        $e->getMessage()
                    );
                }

                continue;
            }

            if (
                $state->status === AutomationRuleState::STATUS_ALERTING
                && (int) $rule->recovery_enabled === 1
            ) {
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
                    $this->logExecution(
                        $rule,
                        $target,
                        self::EXEC_STATUS_RECOVERED,
                        $metrics,
                        $evaluation['details'],
                        $rule->actions_json,
                        $actionResults
                    );
                } catch (\Throwable $e) {
                    $summary['failedCount']++;
                    Log::error('TrafficPlatform automation recovery failed', [
                        'rule_id' => $rule->id,
                        'target_id' => $target->id,
                        'error' => $e->getMessage(),
                    ]);

                    $this->logExecution(
                        $rule,
                        $target,
                        self::EXEC_STATUS_FAILED,
                        $metrics,
                        $evaluation['details'],
                        $rule->actions_json,
                        null,
                        $e->getMessage()
                    );
                }
            }
        }

        return $summary;
    }

    /**
     * 根据规则解析目标账号集合。
     */
    private function resolveTargets(AutomationRule $rule, array $accountIdFilter)
    {
        $scope = is_array($rule->target_scope_json) ? $rule->target_scope_json : [];
        $scopeAccountIds = array_values(array_filter(array_map('intval', (array) ($scope['accountIds'] ?? []))));
        $scopePlatformCodes = array_values(array_filter(array_map('strval', (array) ($scope['platformCodes'] ?? []))));
        $includeDisabled = (int) ($scope['includeDisabled'] ?? 0) === 1;

        $query = TrafficPlatformAccount::query();
        if (!$includeDisabled) {
            $query->where('enabled', 1);
        }

        if (!empty($scopeAccountIds)) {
            $query->whereIn('id', $scopeAccountIds);
        }

        if (!empty($scopePlatformCodes)) {
            $query->whereIn('platform_code', $scopePlatformCodes);
        }

        if (!empty($accountIdFilter)) {
            $query->whereIn('id', $accountIdFilter);
        }

        return $query->orderBy('id')->get();
    }

    /**
     * 批量加载近 1 小时和近 6 小时流量消耗。
     */
    private function loadUsageMetrics(array $accountIds): array
    {
        if (empty($accountIds)) {
            return [];
        }

        $now = now();
        $oneHourAgo = $now->copy()->subHour()->toDateTimeString();
        $sixHourAgo = $now->copy()->subHours(6)->toDateTimeString();

        $rows = DB::table('traffic_platform_usage_stat')
            ->select('platform_account_id')
            ->selectRaw(
                'SUM(CASE WHEN stat_time >= ? THEN traffic_mb ELSE 0 END) AS usage_1h_mb',
                [$oneHourAgo]
            )
            ->selectRaw(
                'SUM(CASE WHEN stat_time >= ? THEN traffic_mb ELSE 0 END) AS usage_6h_mb',
                [$sixHourAgo]
            )
            ->whereIn('platform_account_id', $accountIds)
            ->where('stat_time', '>=', $sixHourAgo)
            ->groupBy('platform_account_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->platform_account_id] = [
                'usage_1h_mb' => (float) $row->usage_1h_mb,
                'usage_6h_mb' => (float) $row->usage_6h_mb,
            ];
        }

        return $map;
    }

    /**
     * 构建目标账号指标。
     */
    private function buildMetrics(TrafficPlatformAccount $account, array $usage): array
    {
        $usage1h = (float) ($usage['usage_1h_mb'] ?? 0);
        $usage6h = (float) ($usage['usage_6h_mb'] ?? 0);
        $avgHourly = $usage6h > 0 ? $usage6h / 6 : 0;
        $balance = (int) $account->balance;

        return [
            'account_id' => (int) $account->id,
            'account_name' => (string) $account->account_name,
            'platform_code' => (string) $account->platform_code,
            'balance_mb' => $balance,
            'enabled' => (int) $account->enabled,
            'usage_1h_mb' => round($usage1h, 6),
            'usage_6h_mb' => round($usage6h, 6),
            'avg_hourly_usage_mb' => round($avgHourly, 6),
            'eta_hours' => $avgHourly > 0 ? round($balance / $avgHourly, 2) : null,
            'last_sync_minutes' => $account->last_sync_at
                ? Carbon::parse($account->last_sync_at)->diffInMinutes(now())
                : null,
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
    private function getOrCreateState(AutomationRule $rule, TrafficPlatformAccount $target): AutomationRuleState
    {
        return AutomationRuleState::query()->firstOrCreate(
            [
                'rule_id' => $rule->id,
                'target_type' => self::TARGET_TYPE,
                'target_id' => (string) $target->id,
            ],
            [
                'status' => AutomationRuleState::STATUS_NORMAL,
            ]
        );
    }

    /**
     * 分发动作。
     */
    private function dispatchActions(
        AutomationRule $rule,
        TrafficPlatformAccount $target,
        array $metrics,
        bool $recovery
    ): array {
        $actions = is_array($rule->actions_json) ? $rule->actions_json : [];
        $results = [];

        foreach ($actions as $action) {
            $results[] = $this->dispatchOneAction($action, $rule, $target, $metrics, $recovery);
        }

        return $results;
    }

    /**
     * 执行单个动作。
     */
    private function dispatchOneAction(
        array $action,
        AutomationRule $rule,
        TrafficPlatformAccount $target,
        array $metrics,
        bool $recovery
    ): array {
        $type = (string) ($action['type'] ?? '');
        $context = array_merge($metrics, [
                'rule_name' => $rule->name,
                'rule_id' => $rule->id,
                'status' => $recovery ? 'recovered' : 'alert',
        ]);

        return match ($type) {
            'telegram_admin' => $this->dispatchTelegramAction($action, $context, $recovery),
            'email' => $this->dispatchEmailAction($action, $context, $recovery),
            'disable_account' => $this->dispatchDisableAccountAction($target, $recovery),
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
            ? (string) ($action['recoverTemplate'] ?? '[TrafficPlatform Recovery] {rule_name} | {account_name}({account_id}) | balance={balance_mb}MB')
            : (string) ($action['template'] ?? '[TrafficPlatform Alert] {rule_name} | {account_name}({account_id}) | balance={balance_mb}MB | usage1h={usage_1h_mb}MB | eta={eta_hours}h');

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
            ? (string) ($action['recoverTemplate'] ?? '[TrafficPlatform Recovery] {rule_name} | {account_name}({account_id}) | balance={balance_mb}MB')
            : (string) ($action['template'] ?? '[TrafficPlatform Alert] {rule_name} | {account_name}({account_id}) | balance={balance_mb}MB | usage1h={usage_1h_mb}MB | eta={eta_hours}h');

        $subjectTemplate = $recovery
            ? (string) ($action['recoverSubject'] ?? '[TrafficPlatform] Recovered - {rule_name}')
            : (string) ($action['subject'] ?? '[TrafficPlatform] Alert - {rule_name}');

        $message = $this->renderTemplate($template, $context);
        $subject = $this->renderTemplate($subjectTemplate, $context);

        $receivers = array_values(array_filter((array) ($action['recipients'] ?? [])));
        $toAdmin = !array_key_exists('toAdmin', $action) || (int) $action['toAdmin'] === 1;

        if ($toAdmin) {
            $adminEmails = User::query()
                ->where('is_admin', 1)
                ->whereNotNull('email')
                ->pluck('email')
                ->all();
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
     * 自动禁用账号动作。
     */
    private function dispatchDisableAccountAction(TrafficPlatformAccount $target, bool $recovery): array
    {
        if ($recovery) {
            return [
                'type' => 'disable_account',
                'ok' => true,
                'skipped' => true,
                'reason' => 'recovery stage',
            ];
        }

        if ((int) $target->enabled === 0) {
            return [
                'type' => 'disable_account',
                'ok' => true,
                'skipped' => true,
                'reason' => 'already disabled',
            ];
        }

        $target->update(['enabled' => 0]);

        return [
            'type' => 'disable_account',
            'ok' => true,
            'disabled' => true,
        ];
    }

    /**
     * 写入执行日志。
     */
    private function logExecution(
        AutomationRule $rule,
        TrafficPlatformAccount $target,
        string $status,
        array $metrics,
        array $details,
        $actions,
        ?array $actionResults,
        ?string $errorMessage = null
    ): void {
        $record = [
            'rule_id' => $rule->id,
            'rule_name' => $rule->name,
            'module' => $rule->module,
            'target_type' => self::TARGET_TYPE,
            'target_id' => (string) $target->id,
            'target_name' => (string) $target->account_name,
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
