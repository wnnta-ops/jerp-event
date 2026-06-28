<?php

declare(strict_types=1);

namespace App\Domain\Master\Customer\Enums;

enum CustomerStatus: string
{
    case Prospect    = 'prospect';
    case Active      = 'active';
    case Inactive    = 'inactive';
    case Blacklisted = 'blacklisted';
    case Anonymized  = 'anonymized';

    public function label(): string
    {
        return match ($this) {
            self::Prospect    => 'Prospect',
            self::Active      => 'Aktif',
            self::Inactive    => 'Tidak Aktif',
            self::Blacklisted => 'Blacklist',
            self::Anonymized  => 'Anonim',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Prospect    => 'info',
            self::Active      => 'success',
            self::Inactive    => 'neutral',
            self::Blacklisted => 'danger',
            self::Anonymized  => 'neutral',
        };
    }
}
