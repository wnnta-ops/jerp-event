<?php

declare(strict_types=1);

namespace App\Domain\Collection\Denda\Services;

use Illuminate\Support\Facades\DB;

/**
 * Perumusan Denda — sumber tunggal parameter rumus denda.
 * Membaca dari tabel `parameters` (group=denda) per tenant; fallback ke config/env.
 *
 * Rumus: denda = outstanding × rate_per_day × overdue_days,
 *        dibatasi maks (max_pct% × outstanding) bila max_pct > 0.
 */
class DendaConfig
{
    public const KEYS = ['rate_per_day', 'grace_days', 'max_pct'];

    /** @return array<string,string> */
    public static function all(?int $tenantId = null): array
    {
        $tenantId ??= tenant_id() ?? default_tenant_id();

        return DB::table('parameters')->where('tenant_id', $tenantId)->where('group', 'denda')
            ->pluck('value', 'key')->all();
    }

    public static function rate(?int $tenantId = null): float
    {
        $v = self::all($tenantId)['rate_per_day'] ?? null;

        return $v !== null ? (float) $v : (float) config('domain.denda.rate_per_day', 0.0005);
    }

    public static function graceDays(?int $tenantId = null): int
    {
        $v = self::all($tenantId)['grace_days'] ?? null;

        return $v !== null ? (int) $v : (int) config('domain.denda.grace_days', 0);
    }

    /** Cap maksimum denda sebagai % dari outstanding (0 = tanpa cap). */
    public static function maxPct(?int $tenantId = null): float
    {
        return (float) (self::all($tenantId)['max_pct'] ?? 0);
    }

    /** Hitung nominal denda sesuai rumus + cap. */
    public static function compute(float $outstanding, int $overdueDays, ?int $tenantId = null): float
    {
        $denda = $outstanding * self::rate($tenantId) * max(0, $overdueDays);
        $maxPct = self::maxPct($tenantId);
        if ($maxPct > 0) {
            $denda = min($denda, $outstanding * $maxPct / 100);
        }

        return round($denda, 2);
    }
}
