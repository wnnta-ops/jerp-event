<?php

declare(strict_types=1);

namespace App\Domain\Master\Customer\Enums;

enum Religion: string
{
    case Islam     = 'islam';
    case Kristen   = 'kristen';
    case Katolik   = 'katolik';
    case Hindu     = 'hindu';
    case Budha     = 'budha';
    case Konghucu  = 'konghucu';
    case Lainnya   = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::Islam     => 'Islam',
            self::Kristen   => 'Kristen',
            self::Katolik   => 'Katolik',
            self::Hindu     => 'Hindu',
            self::Budha     => 'Budha',
            self::Konghucu  => 'Konghucu',
            self::Lainnya   => 'Lainnya',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $r) => ['value' => $r->value, 'label' => $r->label()],
            self::cases(),
        );
    }
}
