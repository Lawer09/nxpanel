<?php

namespace App\Services\Automation;

use App\Exceptions\BusinessException;
use App\Services\Automation\Contracts\AutomationModuleHandler;

class AutomationModuleRegistry
{
    /**
     * @var array<string, AutomationModuleHandler>
     */
    protected array $handlers = [];

    /**
     * @param iterable<AutomationModuleHandler> $handlers
     */
    public function __construct(iterable $handlers = [])
    {
        $this->registerHandlers($handlers);
    }

    /**
     * 批量注册模块处理器。
     *
     * @param iterable<AutomationModuleHandler> $handlers
     */
    public function registerHandlers(iterable $handlers): void
    {
        foreach ($handlers as $handler) {
            $this->registerHandler($handler);
        }
    }

    /**
     * 注册单个模块处理器。
     */
    public function registerHandler(AutomationModuleHandler $handler): void
    {
        $module = $this->normalizeModule($handler->moduleKey());
        if ($module === '') {
            return;
        }

        $this->handlers[$module] = $handler;
    }

    /**
     * 按模块查询处理器，不存在时抛出业务异常。
     */
    public function getHandlerOrFail(string $module): AutomationModuleHandler
    {
        $module = $this->normalizeModule($module);
        if (isset($this->handlers[$module])) {
            return $this->handlers[$module];
        }

        throw new BusinessException([422, '不支持的自动化模块: ' . $module]);
    }

    /**
     * 获取系统支持的模块列表。
     *
     * @return array<int, string>
     */
    public function supportedModules(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * 查询指定模块可用的策略 model 列表。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getModels(string $module): array
    {
        $handler = $this->getHandlerOrFail($module);
        return $handler->supportedModels();
    }

    /**
     * 统一模块名格式，兼容 traffic-platform / traffic_platform。
     */
    public function normalizeModule(string $module): string
    {
        return str_replace('-', '_', strtolower(trim($module)));
    }
}
