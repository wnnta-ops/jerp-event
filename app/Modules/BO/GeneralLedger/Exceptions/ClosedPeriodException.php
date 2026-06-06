<?php

namespace App\Modules\BO\GeneralLedger\Exceptions;

use RuntimeException;

class ClosedPeriodException extends RuntimeException
{
    public static function for(string $periode): self
    {
        return new self("Periode {$periode} sudah ditutup; posting/perubahan jurnal ditolak.");
    }
}
