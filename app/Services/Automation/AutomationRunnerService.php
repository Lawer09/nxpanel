<?php

namespace App\Services\Automation;

class AutomationRunnerService
{
    public function __construct(
        protected AutomationModuleRegistry $registry
    ) {}

    /**
     * 通过模块入口执行自动化规则。
     */
    public function runByModule(string $module, array $params = []): array
    {
        $module = $this->registry->normalizeModule($module);
        $handler = $this->registry->getHandlerOrFail($module);

        return $handler->run($params);
    }
}
