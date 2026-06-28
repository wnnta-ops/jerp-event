<?php

declare(strict_types=1);

namespace App\Domain\Crm\Enums;

enum LeadStage: string
{
    case New        = 'new';
    case Contacted  = 'contacted';
    case Prospect   = 'prospect';
    case Hot        = 'hot';
    case Converted  = 'converted';
    case Lost       = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::New       => 'Lead Baru',
            self::Contacted => 'Dihubungi',
            self::Prospect  => 'Prospek',
            self::Hot       => 'Hot / Negosiasi',
            self::Converted => 'Konversi (Booking)',
            self::Lost      => 'Batal / Lost',
        };
    }

    public function order(): int
    {
        return match ($this) {
            self::New => 1, self::Contacted => 2, self::Prospect => 3,
            self::Hot => 4, self::Converted => 5, self::Lost => 6,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New       => 'neutral',
            self::Contacted => 'primary',
            self::Prospect  => 'info',
            self::Hot       => 'warning',
            self::Converted => 'success',
            self::Lost      => 'danger',
        };
    }

    /** Tahap aktif dalam pipeline (kolom kanban). @return array<int,self> */
    public static function pipeline(): array
    {
        return [self::New, self::Contacted, self::Prospect, self::Hot];
    }

    /** @return array<string,string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
