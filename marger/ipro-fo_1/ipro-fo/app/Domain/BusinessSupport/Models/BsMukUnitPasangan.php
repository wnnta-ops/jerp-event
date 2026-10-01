<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Domain\Master\Unit\Models\Unit;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pasangan satu lokasi MUK (lewat kodenya) dengan satu unit kita.
 *
 * Satu baris per rumah, bukan per baris kontrak — alasannya di migrasi 2026_10_01_120000.
 * Ringkasnya: MUK menerbitkan lokasi_id baru tiap baris kontrak, sehingga rumah yang sama
 * muncul berkali-kali; yang menandainya adalah lokasi_kode.
 */
class BsMukUnitPasangan extends Model
{
    use HasTenant;

    protected $table = 'bs_muk_unit_pasangan';

    protected $fillable = [
        'tenant_id', 'lokasi_kode', 'nama_saat_dipasangkan', 'unit_id',
        'dipasangkan_oleh', 'dipasangkan_pada',
    ];

    protected $casts = ['dipasangkan_pada' => 'datetime'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    /** Seluruh kemunculan lokasi ini di berbagai kontrak — di situlah angka progresnya. */
    public function lokasi(): HasMany
    {
        return $this->hasMany(BsMukLokasi::class, 'lokasi_kode', 'lokasi_kode');
    }
}
