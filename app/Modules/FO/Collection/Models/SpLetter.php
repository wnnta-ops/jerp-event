<?php

namespace App\Modules\FO\Collection\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Surat Peringatan (SP) tercetak — penerbitan resmi bernomor ke customer atas
 * keterlambatan pembayaran. Menyimpan snapshot identitas + baris tunggakan terpilih
 * (items JSON) + denda manual per baris. Dicetak PDF (pdf.fo.sp) sesuai
 * FORMAT SURAT SP.pdf. Berbeda dgn CollectionNotice (riwayat kirim Email/WA).
 */
class SpLetter extends TenantAwareModel
{
    protected $table = 'fo_sp_letters';

    protected $fillable = [
        'tenant_id',
        'kontrak_id',
        'no_surat',
        'level',
        'tgl_surat',
        'jatuh_tempo',
        'nama',
        'alamat',
        'telp',
        'ref_spr',
        'items',
        'total_tagihan',
        'total_pembayaran',
        'total_kekurangan',
        'total_denda',
        'penandatangan_nama',
        'penandatangan_jabatan',
        'created_by',
    ];

    protected $casts = [
        'tgl_surat' => 'date',
        'jatuh_tempo' => 'date',
        'items' => 'array',
        'total_tagihan' => 'decimal:2',
        'total_pembayaran' => 'decimal:2',
        'total_kekurangan' => 'decimal:2',
        'total_denda' => 'decimal:2',
    ];

    public function kontrak(): BelongsTo
    {
        return $this->belongsTo(Kontrak::class);
    }

    /** Label ramah untuk level surat (mengikuti penomoran SP I/II/III). */
    public function getLevelLabelAttribute(): string
    {
        return match ($this->level) {
            'SP1' => 'Surat Peringatan I',
            'SP2' => 'Surat Peringatan II',
            'SP3' => 'Surat Peringatan III',
            'PEMBATALAN' => 'Pembatalan',
            default => (string) $this->level,
        };
    }

    /** Angka romawi level (I/II/III) untuk judul "Hal". */
    public function getLevelRomanAttribute(): string
    {
        return match ($this->level) {
            'SP1' => 'I',
            'SP2' => 'II',
            'SP3' => 'III',
            default => '',
        };
    }
}
