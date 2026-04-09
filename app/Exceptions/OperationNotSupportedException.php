<?php

namespace App\Exceptions;

use Exception;

/**
 * 云服务商操作不支持异常
 */
class OperationNotSupportedException extends Exception
{
    public function __construct(string $operation = '', string $provider = '')
    {
        $message = $operation 
            ? "Operation '{$operation}' is not supported" . ($provider ? " by provider '{$provider}'" : '')
            : 'Operation not supported';
            
        parent::__construct($message);
    }
}
