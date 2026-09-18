<?php

namespace App\Exceptions;

use RuntimeException;

class DeviceCommandException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly int $httpStatus = 409,
    ) {
        parent::__construct($message);
    }
}
