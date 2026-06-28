<?php

declare(strict_types=1);

namespace App\Domain\Master\Customer\Enums;

enum IdType: string
{
    case KTP      = 'KTP';
    case Passport = 'Passport';
    case SIM      = 'SIM';

    public function label(): string
    {
        return match ($this) {
            self::KTP      => 'KTP',
            self::Passport => 'Paspor',
            self::SIM      => 'SIM',
        };
    }

    public function minLength(): int
    {
        return match ($this) {
            self::KTP      => 16,
            self::Passport => 6,
            self::SIM      => 12,
        };
    }
}
