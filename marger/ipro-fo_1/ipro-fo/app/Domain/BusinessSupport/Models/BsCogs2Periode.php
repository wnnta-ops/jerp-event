<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * COGS Baru — satu periode = satu lokasi + tahap, seperti judul tiap lembar di lampiran
 * ("LOKASI ….. TAHAP …..").
 *
 * Yang disimpan hanya blok Perhitungan Lahan yang diketik. Jalan dan seluruh persentase
 * diturunkan di sini; angka turunan yang disimpan bisa menyimpang dari rumusnya tanpa ada
 * yang menolak simpan.
 */
class BsCogs2Periode extends Model
{
    use HasTenant;

    protected $table = 'bs_cogs2_periode';

    protected $fillable = [
        'tenant_id', 'lokasi_id', 'lokasi_kode', 'lokasi_nama', 'tahap',
        'luas_lahan', 'saleable', 'rth', 'luas_bangunan', 'jumlah_unit',
        'ikut_muk', 'ikut_gl',
        'catatan', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'lokasi_id'     => 'integer',
        'luas_lahan'    => 'decimal:2',
        'saleable'      => 'decimal:2',
        'rth'           => 'decimal:2',
        'luas_bangunan' => 'decimal:2',
        'jumlah_unit'   => 'integer',
        'ikut_muk'      => 'boolean',
        'ikut_gl'       => 'boolean',
    ];

    public function tipe(): HasMany
    {
        return $this->hasMany(BsCogs2HousingTipe::class, 'periode_id')->orderBy('urut')->orderBy('id');
    }

    public function kategori(): HasMany
    {
        return $this->hasMany(BsCogs2Kategori::class, 'periode_id')->orderBy('urut')->orderBy('id');
    }

    public function actual(): HasMany
    {
        return $this->hasMany(BsCogs2Actual::class, 'periode_id')->orderBy('urut')->orderBy('id');
    }

    public function unit(): HasMany
    {
        return $this->hasMany(BsCogs2Unit::class, 'periode_id')->orderBy('urut')->orderBy('id');
    }

    /** Jalan = sisa lahan. Bisa NEGATIF bila angkanya tidak konsisten — dan memang harus terlihat begitu. */
    public function getJalanAttribute(): float
    {
        return round((float) $this->luas_lahan - (float) $this->saleable - (float) $this->rth, 2);
    }

    public function getCakupanAttribute(): string
    {
        $nama = trim((string) ($this->lokasi_nama ?: $this->lokasi_kode ?: ('Lokasi #'.$this->lokasi_id)));

        return $this->tahap === '' ? $nama.' — seluruh tahap' : $nama.' — Tahap '.$this->tahap;
    }
}
