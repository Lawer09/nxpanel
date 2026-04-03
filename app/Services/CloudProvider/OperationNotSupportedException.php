<?php

namespace App\Services\CloudProvider;

/**
 * 当驱动不支持某项操作时抛出此异常
 */
class OperationNotSupportedException extends \RuntimeException
{
    public function __construct(string $operation, string $driver)
    {
        parent::__construct(
            "驱动 [{$driver}] 不支持操作: {$operation}",
            501
        );
    }
}
