<?php

namespace App\Modules\FO\FinanceAR\Models;

use App\Models\Bank;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris biaya proses KPR. jenis: HT_SEBAGIAN | KPR (diproses cepat),
 * NOTARIS | SISA_HT (menyusul). Diproses → Kas Keluar + posting GL (FOBO).
 */
class KprPengajuanBiaya extends Model
{
    protected $table = 'fo_kpr_pengajuan_biaya';

    protected $fillable = [
        'pengajuan_id',
        'jenis',
        'nama',
        'nominal',
        'status',
        'tgl_proses',
        'bank_id',
        'no_referensi',
        'keterangan',
    ];

    protected $casts = [
        'nominal' => 'decimal:2',
        'tgl_proses' => 'date',
    ];

    /** Label tampilan per jenis biaya. */
    public const LABELS = [
        'HT_SEBAGIAN' => 'Biaya HT Sebagian',
        'KPR' => 'Biaya KPR',
        'NOTARIS' => 'Biaya Tagihan Notaris',
        'SISA_HT' => 'Sisa Tagihan HT',
    ];

    public function getLabelAttribute(): string
    {
        return $this->nama ?: (self::LABELS[$this->jenis] ?? $this->jenis);
    }

    public function pengajuan(): BelongsTo
    {
        return $this->belongsTo(KprPengajuan::class, 'pengajuan_id');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }
}
