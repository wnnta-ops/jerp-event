<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Salinan kontrak owner MUK ERP. Lihat migrasi 2026_09_19_000010 untuk alasan disalin.
 *
 * TIDAK ADA penulisan balik ke MUK — API mereka baca-saja. Baris di sini hanya ditimpa
 * oleh perintah tarikan; mengubahnya lewat layar berarti angka kita dan angka mereka
 * berbeda tanpa ada yang tahu.
 */
class BsMukContract extends Model
{
    use HasTenant;

    protected $table = 'bs_muk_contracts';

    protected $fillable = [
        'tenant_id', 'muk_id', 'no_kontrak', 'no_spk', 'jenis', 'status',
        'proyek_kode', 'proyek_nama', 'pelanggan_kode', 'pelanggan_nama',
        'tanggal', 'mulai', 'selesai',
        'nilai_kontrak', 'retensi_persen',
        'kum_diopname', 'kum_ditagih_neto', 'kum_diterima', 'kum_retensi_ditahan',
        'diperbarui', 'ditarik_pada',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'mulai' => 'date',
        'selesai' => 'date',
        'diperbarui' => 'datetime',
        'ditarik_pada' => 'datetime',
        'nilai_kontrak' => 'decimal:2',
        'retensi_persen' => 'decimal:4',
        'kum_diopname' => 'decimal:2',
        'kum_ditagih_neto' => 'decimal:2',
        'kum_diterima' => 'decimal:2',
        'kum_retensi_ditahan' => 'decimal:2',
    ];

    /**
     * Sisa yang belum ditagih menurut angka MUK: opname terposting dikurangi yang sudah
     * tertaut ke COP Owner. Inilah angka yang biasanya dipakai menyiapkan tagihan berikutnya.
     *
     * Dijaga tidak negatif: pada sebagian kontrak MUK, "ditagih" melampaui "diopname"
     * (mis. NK2024060002 — ditagih 56,8 jt dari opname 38,2 jt). Menampilkan sisa negatif
     * di layar tagihan mengundang orang mengurangi tagihan yang sudah benar; yang seperti
     * itu perlu ditanyakan ke MUK, bukan dihitung sendiri di sini.
     */
    public function getBelumDitagihAttribute(): float
    {
        return max(0.0, (float) $this->kum_diopname - (float) $this->kum_ditagih_neto);
    }

    /** Benar bila angka MUK sendiri tidak masuk akal — ditandai di layar, bukan disembunyikan. */
    public function getAngkaJanggalAttribute(): bool
    {
        return (float) $this->kum_ditagih_neto > (float) $this->kum_diopname
            || (float) $this->kum_diopname > (float) $this->nilai_kontrak;
    }
}
