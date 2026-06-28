<?php

declare(strict_types=1);

namespace App\Exceptions;

final class InvalidStateTransitionException extends BusinessRuleException
{
    public function __construct(string $from, string $to, string $entityName = 'Entity')
    {
        parent::__construct(
            message: "{$entityName} tidak dapat berubah status dari [{$from}] ke [{$to}].",
            errorCode: 'INVALID_STATE_TRANSITION',
            httpStatus: 422,
            details: ['from' => $from, 'to' => $to, 'entity' => $entityName],
        );
    }
}
