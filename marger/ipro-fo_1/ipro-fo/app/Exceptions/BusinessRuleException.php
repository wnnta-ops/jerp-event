<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class BusinessRuleException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message,
        private readonly string $errorCode = 'BUSINESS_RULE_VIOLATION',
        private readonly int $httpStatus = 422,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}
