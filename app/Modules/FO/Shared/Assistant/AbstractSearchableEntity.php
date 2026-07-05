<?php

namespace App\Modules\FO\Shared\Assistant;

use Carbon\Carbon;

/**
 * Basis entitas: helper filter/format bersama (tanggal, limit, rupiah).
 */
abstract class AbstractSearchableEntity implements SearchableEntity
{
    protected function parseDate(mixed $v): ?Carbon
    {
        if (! $v) {
            return null;
        }
        try {
            return Carbon::parse((string) $v)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $f */
    protected function clampLimit(array $f, int $default = 100): int
    {
        return max(1, min(1000, (int) ($f['limit'] ?? $default)));
    }

    protected function rupiah(mixed $v): ?string
    {
        return (float) $v > 0 ? number_format((float) $v, 0, ',', '.') : null;
    }

    protected function tanggal(mixed $v): ?string
    {
        return $v ? Carbon::parse((string) $v)->format('d/m/Y') : null;
    }

    protected function rangeLabel(?Carbon $dari, ?Carbon $sampai): string
    {
        return trim(($dari ? $dari->format('d/m/Y') : '…').' s/d '.($sampai ? $sampai->format('d/m/Y') : '…'));
    }

    protected function status(array $f): string
    {
        return strtoupper(trim((string) ($f['status'] ?? '')));
    }
}
