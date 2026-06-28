<?php

declare(strict_types=1);

namespace App\Domain\Finance\Tts\Enums;

enum PaymentMethod: string
{
    case Cash     = 'cash';
    case Transfer = 'transfer';
    case Giro     = 'giro';
    case Va       = 'va';

    public function label(): string
    {
        return match ($this) {
            self::Cash     => 'Tunai',
            self::Transfer => 'Transfer',
            self::Giro     => 'Bilyet Giro',
            self::Va       => 'Virtual Account',
        };
    }
}
