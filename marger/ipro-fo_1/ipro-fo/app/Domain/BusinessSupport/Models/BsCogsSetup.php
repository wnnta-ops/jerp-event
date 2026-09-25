<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Set Up COGS — "Perhitungan Lahan" satu lokasi/tahap.
 *
 * Yang DISIMPAN hanya angka yang diketik orang (Luas Lahan, Saleable, RTH, jumlah unit) dan dua
 * setelan pembagi. Jalan serta seluruh persentase DITURUNKAN di sini, tidak pernah disimpan:
 * di lampiran pun Jalan adalah rumus (=Luas Lahan - Saleable - RTH), dan menyimpan angka turunan
 * membuat ia bisa berbeda dari rumusnya tanpa ada yang memberi tahu.
 */
class BsCogsSetup extends Model
{
    use HasTenant;

    protected $table = 'bs_cogs_setup';

    protected $fillable = [
        'tenant_id', 'lokasi_id', 'lokasi_kode', 'lokasi_nama', 'tahap',
        'luas_lahan', 'saleable', 'rth', 'jumlah_unit',
        'dasar_tanah', 'termasuk_lebih_tanah', 'catatan',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'lokasi_id'            => 'integer',
        'luas_lahan'           => 'decimal:2',
        'saleable'             => 'decimal:2',
        'rth'                  => 'decimal:2',
        'jumlah_unit'          => 'integer',
        'termasuk_lebih_tanah' => 'boolean',
    ];

    public function komponen(): HasMany
    {
        return $this->hasMany(BsCogsSetupComponent::class, 'setup_id')->orderBy('urut')->orderBy('id');
    }

    /** Jalan = sisa lahan setelah Saleable & RTH. Bisa NEGATIF bila angkanya tidak konsisten — dan memang harus terlihat begitu. */
    public function getJalanAttribute(): float
    {
        return round((float) $this->luas_lahan - (float) $this->saleable - (float) $this->rth, 2);
    }

    /** Label cakupan untuk layar & pesan. */
    public function getCakupanAttribute(): string
    {
        $nama = trim((string) ($this->lokasi_nama ?: $this->lokasi_kode ?: ('Lokasi #'.$this->lokasi_id)));

        return $this->tahap === '' ? $nama.' — seluruh tahap' : $nama.' — Tahap '.$this->tahap;
    }
}
