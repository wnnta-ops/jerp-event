<?php

declare(strict_types=1);

namespace App\Domain\Master\Customer\Enums;

enum MaritalStatus: string
{
    case Menikah        = 'menikah';
    case Cerai          = 'cerai';
    case BelumMenikah   = 'belum_menikah';
    case LainLain       = 'lain_lain';

    public function label(): string
    {
        return match ($this) {
            self::Menikah       => 'Menikah',
            self::Cerai         => 'Cerai',
            self::BelumMenikah  => 'Belum Menikah',
            self::LainLain      => 'Lain-Lain',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $m) => ['value' => $m->value, 'label' => $m->label()],
            self::cases(),
        );
    }
}
