<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu baris jurnal realisasi pada Actual Costing COGS.
 *
 * Penambahan & pengurangan berdiri sebagai DUA kolom, bukan satu angka bersih: saldo akhir
 * adalah turunan, dan satu angka bersih menyembunyikan koreksi yang justru perlu terlihat saat
 * menelusuri selisih antara realisasi dan budget.
 */
class BsCogs2Actual extends Model
{
    use HasTenant;

    protected $table = 'bs_cogs2_actual';

    protected $fillable = [
        'tenant_id', 'periode_id', 'komponen', 'lokasi', 'tahap', 'kategori',
        'no_spk', 'no_jurnal', 'keterangan', 'penambahan', 'pengurangan', 'urut',
    ];

    protected $casts = [
        'periode_id'  => 'integer',
        'penambahan'  => 'decimal:2',
        'pengurangan' => 'decimal:2',
        'urut'        => 'integer',
    ];

    public function getSaldoAkhirAttribute(): float
    {
        return round((float) $this->penambahan - (float) $this->pengurangan, 2);
    }
}
