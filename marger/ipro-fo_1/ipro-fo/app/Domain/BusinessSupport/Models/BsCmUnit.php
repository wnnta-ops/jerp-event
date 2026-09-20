<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Salinan data BAST per unit dari sistem Komplain. Lihat migrasi 2026_09_21_000010.
 *
 * Hanya dibaca; tidak ada penulisan balik — API mereka baca-saja.
 */
class BsCmUnit extends Model
{
    use HasTenant;

    protected $table = 'bs_cm_units';

    protected $fillable = [
        'tenant_id', 'cm_id', 'kode_unit', 'kode_upper', 'cluster_kode', 'cluster_nama',
        'nama', 'bast_date', 'bast_no', 'bast_signed_at', 'bast_handover_name',
        'is_active', 'diperbarui', 'ditarik_pada',
    ];

    protected $casts = [
        'bast_date' => 'date',
        'bast_signed_at' => 'datetime',
        'diperbarui' => 'datetime',
        'ditarik_pada' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Peta kode unit (HURUF BESAR) => baris, untuk mencocokkan dengan unit BO.
     *
     * Dimuat sekali lalu dicocokkan di memori: layar Detail BAST memuat ratusan baris, dan
     * satu kueri per baris berarti ratusan kueri untuk dua kolom.
     *
     * Kuncinya di-uppercase karena BO menulis "JRS5-001-II-53a" sementara sistem Komplain
     * menulis "…-53A". Diuji pada 27 unit yang punya booking: 22 cocok bila huruf ikut
     * dibandingkan, 27 bila tidak.
     *
     * @return array<string, self>
     */
    public static function petaBast(): array
    {
        static $peta = null;
        if ($peta !== null) {
            return $peta;
        }

        $peta = [];
        foreach (static::query()->whereNotNull('kode_upper')->get() as $u) {
            $peta[(string) $u->kode_upper] = $u;
        }

        return $peta;
    }
}
