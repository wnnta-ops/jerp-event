<?php

namespace App\Modules\BO\GeneralLedger\Exceptions;

use RuntimeException;

class InvalidJournalStateException extends RuntimeException
{
    public static function transition(string $from, string $action): self
    {
        return new self("Jurnal berstatus {$from} tidak dapat di-{$action}.");
    }
}
