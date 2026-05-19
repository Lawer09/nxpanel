<?php

namespace App\Services\Automation;

use App\Exceptions\BusinessException;
use App\Models\AutomationRule;

class AutomationRuleService
{
    public function __construct(
        protected AutomationModuleRegistry $registry
    ) {}

    /**
     * 查询指定模块自动化规则列表。
     */
    public function index(string $module, array $params): array
    {
        $module = $this->registry->normalizeModule($module);
        $this->registry->getHandlerOrFail($module);

        $query = AutomationRule::query()->where('module', $module);

        if (array_key_exists('enabled', $params) && $params['enabled'] !== null) {
            $query->where('enabled', (int) $params['enabled']);
        }

        if (!empty($params['keyword'])) {
            $keyword = trim((string) $params['keyword']);
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                    ->orWhere('description', 'like', "%{$keyword}%");
            });
        }

        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 20);

        $total = $query->count();
        $items = $query->orderByDesc('id')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        return [
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'data' => $items,
        ];
    }

    /**
     * 查询指定模块规则详情。
     */
    public function detail(string $module, int $id): AutomationRule
    {
        $module = $this->registry->normalizeModule($module);
        $this->registry->getHandlerOrFail($module);

        $rule = AutomationRule::query()
            ->where('module', $module)
            ->find($id);

        if (!$rule) {
            throw new BusinessException([404, '自动化规则不存在']);
        }

        return $rule;
    }

    /**
     * 创建规则。
     */
    public function store(array $params): AutomationRule
    {
        $module = $this->registry->normalizeModule((string) $params['module']);
        $handler = $this->registry->getHandlerOrFail($module);
        $params['module'] = $module;

        return AutomationRule::create($this->buildPayload($params, $handler->defaultTargetType()));
    }

    /**
     * 更新规则。
     */
    public function update(array $params): AutomationRule
    {
        $module = $this->registry->normalizeModule((string) $params['module']);
        $handler = $this->registry->getHandlerOrFail($module);
        $rule = $this->detail($module, (int) $params['id']);
        $params['module'] = $module;
        $payload = $this->buildPayload($params, $handler->defaultTargetType(), false);

        if (!empty($payload)) {
            $rule->update($payload);
        }

        return $rule->fresh();
    }

    /**
     * 更新规则启停状态。
     */
    public function updateStatus(string $module, int $id, int $enabled): void
    {
        $rule = $this->detail($module, $id);
        $rule->update(['enabled' => $enabled]);
    }

    /**
     * 构建规则存储数据。
     */
    private function buildPayload(array $params, string $defaultTargetType, bool $isCreate = true): array
    {
        $payload = [];
        if ($isCreate) {
            $payload['module'] = (string) $params['module'];
        }

        if ($isCreate || array_key_exists('name', $params)) {
            $payload['name'] = trim((string) ($params['name'] ?? ''));
        }
        if ($isCreate || array_key_exists('description', $params)) {
            $payload['description'] = isset($params['description'])
                ? trim((string) $params['description'])
                : null;
        }
        if ($isCreate || array_key_exists('targetType', $params)) {
            $payload['target_type'] = (string) ($params['targetType'] ?? $defaultTargetType);
        }
        if ($isCreate || array_key_exists('targetScope', $params)) {
            $payload['target_scope_json'] = $params['targetScope'] ?? null;
        }
        if ($isCreate || array_key_exists('conditionLogic', $params)) {
            $payload['condition_logic'] = (string) ($params['conditionLogic'] ?? AutomationRule::LOGIC_ALL);
        }
        if ($isCreate || array_key_exists('conditions', $params)) {
            $payload['conditions_json'] = (array) ($params['conditions'] ?? []);
        }
        if ($isCreate || array_key_exists('actions', $params)) {
            $payload['actions_json'] = (array) ($params['actions'] ?? []);
        }
        if ($isCreate || array_key_exists('cooldownSeconds', $params)) {
            $payload['cooldown_seconds'] = (int) ($params['cooldownSeconds'] ?? 3600);
        }
        if ($isCreate || array_key_exists('recoveryEnabled', $params)) {
            $payload['recovery_enabled'] = (int) ($params['recoveryEnabled'] ?? 1);
        }
        if ($isCreate || array_key_exists('enabled', $params)) {
            $payload['enabled'] = (int) ($params['enabled'] ?? 1);
        }

        return $payload;
    }
}
