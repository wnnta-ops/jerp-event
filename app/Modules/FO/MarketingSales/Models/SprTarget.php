<?php

namespace App\Modules\FO\MarketingSales\Models;

use App\Models\Concerns\TenantAwareModel;

/**
 * Target SPR per bulan untuk satu skenario dalam satu tahun.
 *
 * Satu baris = satu garis target di grafik "Sales Report — SPR per Bulan". Skenarionya teks
 * bebas (lihat migrasi) sehingga jumlah garis mengikuti isi berkas impor, bukan dipatok kode.
 */
class SprTarget extends TenantAwareModel
{
    protected $table = 'fo_spr_targets';

    protected $fillable = [
        'tenant_id', 'tahun', 'skenario', 'urutan',
        'm1', 'm2', 'm3', 'm4', 'm5', 'm6', 'm7', 'm8', 'm9', 'm10', 'm11', 'm12',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'tahun' => 'integer', 'urutan' => 'integer',
        'm1' => 'integer', 'm2' => 'integer', 'm3' => 'integer', 'm4' => 'integer',
        'm5' => 'integer', 'm6' => 'integer', 'm7' => 'integer', 'm8' => 'integer',
        'm9' => 'integer', 'm10' => 'integer', 'm11' => 'integer', 'm12' => 'integer',
    ];

    /** @return array<int,int> nilai target bulan 1..12 */
    public function perBulan(): array
    {
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $out[$m] = (int) ($this->{'m'.$m} ?? 0);
        }

        return $out;
    }

    public function setahun(): int
    {
        return array_sum($this->perBulan());
    }
}
