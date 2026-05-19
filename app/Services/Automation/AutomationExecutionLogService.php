<?php

namespace App\Services\Automation;

use Illuminate\Support\Facades\Redis;

class AutomationExecutionLogService
{
    private const MAX_RECORDS = 100;

    /**
     * 写入模块执行记录并截断到最近 100 条。
     */
    public function appendExecution(string $module, array $record): void
    {
        $module = trim($module);
        if ($module === '') {
            return;
        }

        $payload = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }

        $redisKey = $this->buildRedisKey($module);
        Redis::pipeline(function ($pipe) use ($payload, $redisKey) {
            $pipe->lpush($redisKey, $payload);
            $pipe->ltrim($redisKey, 0, self::MAX_RECORDS - 1);
        });
    }

    /**
     * 查询模块执行记录列表。
     */
    public function listExecutions(string $module, array $params): array
    {
        $rawItems = Redis::lrange($this->buildRedisKey($module), 0, self::MAX_RECORDS - 1);
        $items = [];

        foreach ($rawItems as $rawItem) {
            $decoded = json_decode((string) $rawItem, true);
            if (!is_array($decoded)) {
                continue;
            }
            $items[] = $decoded;
        }

        $items = $this->applyFilters($items, $params);

        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 20);
        $total = count($items);
        $offset = max(0, ($page - 1) * $pageSize);
        $list = array_slice($items, $offset, $pageSize);

        return [
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'data' => $list,
        ];
    }

    /**
     * 按查询参数过滤执行记录。
     */
    private function applyFilters(array $items, array $params): array
    {
        return array_values(array_filter($items, function (array $item) use ($params) {
            if (!empty($params['ruleId']) && (int) ($item['rule_id'] ?? 0) !== (int) $params['ruleId']) {
                return false;
            }

            if (!empty($params['status']) && (string) ($item['status'] ?? '') !== (string) $params['status']) {
                return false;
            }

            if (!empty($params['targetId']) && (string) ($item['target_id'] ?? '') !== (string) $params['targetId']) {
                return false;
            }

            return true;
        }));
    }

    /**
     * 构建模块执行记录 Redis Key。
     */
    private function buildRedisKey(string $module): string
    {
        return 'automation:executions:' . $module;
    }
}
