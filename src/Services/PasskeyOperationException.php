<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use RuntimeException;

class PasskeyOperationException extends RuntimeException
{
    public function __construct(
        string $message,
        private string $errorCode,
        private int $httpStatus = 400,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
