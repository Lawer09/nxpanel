<?php

namespace App\Services\Automation;

use App\Exceptions\BusinessException;
use App\Services\Automation\Contracts\AutomationModuleHandler;

class AutomationModuleRegistry
{
    /**
     * @param iterable<AutomationModuleHandler> $handlers
     */
    public function __construct(
        protected iterable $handlers
    ) {}

    /**
     * 查询模块处理器，不存在则抛出业务异常。
     */
    public function getHandlerOrFail(string $module): AutomationModuleHandler
    {
        $module = trim($module);
        foreach ($this->handlers as $handler) {
            if ($handler->moduleKey() === $module) {
                return $handler;
            }
        }

        throw new BusinessException([422, '不支持的自动化模块: ' . $module]);
    }

    /**
     * 获取系统支持的模块列表。
     */
    public function supportedModules(): array
    {
        $modules = [];
        foreach ($this->handlers as $handler) {
            $modules[] = $handler->moduleKey();
        }

        return array_values(array_unique($modules));
    }
}
