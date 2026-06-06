<?php

namespace App\Modules\FO\Adminjual\Support;

use App\Models\Project;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Modules\FO\MarketingSales\Models\Unit;

/**
 * Parser No. Unit format `{LOKASI}-{TAHAP}-{BLOK}-{NOMER}` mis. "JRK3-004-XV-2"
 * dipakai oleh Excel importers (Pricelist, Koordinat) untuk lookup unit existing.
 * Segmen pertama = kode Lokasi (master Setup Unit); fallback ke kode Project (legacy).
 */
class UnitCodeParser
{
    /**
     * @return array{lokasi_code:?string, tahap:?string, blok:?string, no_unit:?string}
     */
    public static function parse(string $code): array
    {
        $code = trim($code);
        $parts = explode('-', $code);

        if (count($parts) < 4) {
            return ['lokasi_code' => null, 'tahap' => null, 'blok' => null, 'no_unit' => null];
        }

        // Gabung sisanya kalau ada lebih dari 4 part (mis. "JRK3-004-XV-11A" → blok=XV, no=11A)
        return [
            'lokasi_code' => $parts[0],
            'tahap' => $parts[1],
            'blok' => $parts[2],
            'no_unit' => implode('-', array_slice($parts, 3)),
        ];
    }

    public static function findUnit(string $code, ?int $tenantId = null): ?Unit
    {
        $code = trim($code);
        $tenantId = $tenantId ?? 1;

        // Fast path: cocokkan langsung ke No. Unit kanonik (kode_unit).
        $direct = Unit::query()
            ->where('tenant_id', $tenantId)
            ->where('kode_unit', $code)
            ->first();
        if ($direct) {
            return $direct;
        }

        // Fallback: pecah komponen {lokasi}-{tahap}-{blok}-{nomer}.
        $p = self::parse($code);
        if (! $p['lokasi_code']) {
            return null;
        }

        // Utama: segmen pertama = kode Lokasi (master).
        $lokasi = Lokasi::query()
            ->where('tenant_id', $tenantId)
            ->where('kode', $p['lokasi_code'])
            ->first();
        if ($lokasi) {
            return Unit::query()
                ->where('tenant_id', $tenantId)
                ->where('lokasi_id', $lokasi->id)
                ->when($p['tahap'], fn ($q, $v) => $q->where('tahap', $v))
                ->where('blok', $p['blok'])
                ->where('no_unit', $p['no_unit'])
                ->first();
        }

        // Legacy: segmen pertama = kode Project.
        $project = Project::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $p['lokasi_code'])
            ->first();
        if (! $project) {
            return null;
        }

        return Unit::query()
            ->where('tenant_id', $tenantId)
            ->where('project_id', $project->id)
            ->when($p['tahap'], fn ($q, $v) => $q->where('tahap', $v))
            ->where('blok', $p['blok'])
            ->where('no_unit', $p['no_unit'])
            ->first();
    }
}
