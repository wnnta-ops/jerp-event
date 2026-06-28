<?php

declare(strict_types=1);

namespace App\Domain\Master\Customer\Enums;

enum EmergencyRelationship: string
{
    case OrangTua = 'orang_tua';
    case Anak     = 'anak';
    case Suami    = 'suami';
    case Istri    = 'istri';
    case Saudara  = 'saudara';
    case Teman    = 'teman';
    case Lainnya  = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::OrangTua  => 'Orang Tua',
            self::Anak      => 'Anak',
            self::Suami     => 'Suami',
            self::Istri     => 'Istri',
            self::Saudara   => 'Saudara',
            self::Teman     => 'Teman',
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
