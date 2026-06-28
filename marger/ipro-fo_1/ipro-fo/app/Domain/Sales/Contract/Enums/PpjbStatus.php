<?php

declare(strict_types=1);

namespace App\Domain\Sales\Contract\Enums;

/**
 * PPJB 4-state lifecycle (sesuai pattern Java Core System Lama).
 *
 *   BelumDiProses → Print → ProsesTandaTangan → Selesai
 */
enum PpjbStatus: string
{
    case BelumDiProses     = 'belum_diproses';
    case Print             = 'print';
    case ProsesTandaTangan = 'proses_tanda_tangan';
    case Selesai           = 'selesai';

    public function label(): string
    {
        return match ($this) {
            self::BelumDiProses     => 'Belum di Proses',
            self::Print             => 'Sudah di-Print',
            self::ProsesTandaTangan => 'Proses Tanda Tangan',
            self::Selesai           => 'Selesai',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::BelumDiProses     => 'neutral',
            self::Print             => 'info',
            self::ProsesTandaTangan => 'warning',
            self::Selesai           => 'success',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::BelumDiProses     => $next === self::Print,
            self::Print             => in_array($next, [self::ProsesTandaTangan, self::BelumDiProses], true),
            self::ProsesTandaTangan => in_array($next, [self::Selesai, self::Print], true),
            self::Selesai           => false,
        };
    }

    /** @return array<int, array{value: string, label: string, color: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $s) => ['value' => $s->value, 'label' => $s->label(), 'color' => $s->color()],
            self::cases(),
        );
    }
}
