<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Salinan satu tagihan (COP Owner) milik kontrak MUK. Alasannya di migrasi 2026_10_07_100000.
 *
 * TIDAK ADA penulisan balik ke MUK — API mereka baca-saja. Kolom nilainya ditimpa tiap
 * penarikan; kolom posting (supplier_id, due_date, diposting_*) milik kita dan tidak pernah
 * disentuh penarikan.
 */
class BsMukTagihan extends Model
{
    use HasTenant;

    protected $table = 'bs_muk_tagihan';

    protected $fillable = [
        'tenant_id', 'muk_id', 'no_kontrak', 'no_tagihan', 'jenis',
        'tanggal', 'jatuh_tempo', 'dpp', 'ppn', 'pph', 'total', 'diterima', 'status',
        'ditarik_pada', 'supplier_id', 'due_date', 'diposting_oleh', 'diposting_pada',
    ];

    protected $casts = [
        'tanggal' => 'date:Y-m-d',
        'jatuh_tempo' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
        'ditarik_pada' => 'datetime',
        'diposting_pada' => 'datetime',
        'dpp' => 'decimal:2',
        'ppn' => 'decimal:2',
        'pph' => 'decimal:2',
        'total' => 'decimal:2',
        'diterima' => 'decimal:2',
    ];

    public function kontrak(): BelongsTo
    {
        return $this->belongsTo(BsMukContract::class, 'muk_id', 'muk_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(BsSupplier::class, 'supplier_id');
    }

    /**
     * Nilai yang ditagihkan = DPP + PPN − PPh 4(2).
     *
     * Rumus yang SAMA dengan kolom "Nilai SPK (Tagihan)" di Kontrak SPK (MUK) dan Informasi
     * CTC (lihat BarisSpkMuk), supaya satu angka tidak punya dua arti di modul yang sama.
     *
     * Dihitung dari komponennya, bukan memakai `total` dari MUK, karena arti `total` mereka
     * BELUM DIPASTIKAN — tidak ada di dokumen API, dan belum ditanyakan ke tim MUK. Kalau
     * keduanya ternyata berbeda, selisihnya terlihat lewat selisihTotalMuk() dan layar
     * menandainya, bukan diam-diam memilih salah satu.
     */
    public function getNilaiTagihanAttribute(): float
    {
        return (float) $this->dpp + (float) $this->ppn - (float) $this->pph;
    }

    /**
     * Selisih antara `total` kiriman MUK dan DPP+PPN−PPh, atau null bila sama.
     *
     * Ditampilkan supaya kalau dugaan di atas keliru, ia KETAHUAN pada baris yang
     * bersangkutan — bukan menjadi pembayaran yang nilainya meleset tanpa ada yang tahu.
     */
    public function selisihTotalMuk(): ?float
    {
        $selisih = (float) $this->total - $this->nilai_tagihan;

        return abs($selisih) >= 0.005 ? $selisih : null;
    }

    /** Sudah dimasukkan ke antrean Posting Invoice SPK. */
    public function getSudahDipostingAttribute(): bool
    {
        return $this->diposting_pada !== null;
    }
}
