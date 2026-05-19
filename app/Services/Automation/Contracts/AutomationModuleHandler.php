<?php

namespace App\Services\Automation\Contracts;

interface AutomationModuleHandler
{
    /**
     * 返回模块唯一标识，例如 traffic_platform。
     */
    public function moduleKey(): string;

    /**
     * 返回模块默认目标类型。
     */
    public function defaultTargetType(): string;

    /**
     * 执行模块规则评估与动作分发。
     */
    public function run(array $params = []): array;
}
