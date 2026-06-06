<?php

namespace App\Modules\BO\GeneralLedger\Exceptions;

use RuntimeException;

class UnbalancedJournalException extends RuntimeException
{
    public static function make(float $debet, float $kredit): self
    {
        return new self(sprintf(
            'Jurnal tidak balance: total debet %s ≠ total kredit %s (selisih %s).',
            number_format($debet, 2),
            number_format($kredit, 2),
            number_format(abs($debet - $kredit), 2),
        ));
    }
}
