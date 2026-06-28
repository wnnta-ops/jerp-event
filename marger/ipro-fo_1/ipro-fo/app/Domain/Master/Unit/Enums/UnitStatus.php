<?php

declare(strict_types=1);

namespace App\Domain\Master\Unit\Enums;

enum UnitStatus: string
{
    case Available   = 'available';
    case Hold        = 'hold';
    case Booked      = 'booked';
    case Sold        = 'sold';
    case Cancelled   = 'cancelled';
    case Maintenance = 'maintenance';

    public function label(): string
    {
        return match ($this) {
            self::Available   => 'Tersedia',
            self::Hold        => 'Hold',
            self::Booked      => 'Booked',
            self::Sold        => 'Sold',
            self::Cancelled   => 'Cancelled',
            self::Maintenance => 'Maintenance',
        };
    }
}
