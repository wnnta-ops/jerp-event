<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Domain\Master\Unit\Models\Unit;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan perpindahan satu rumah ke Barang Siap Dijual. Alasannya di migrasi 2026_10_07_120000.
 *
 * `nilai` DIBEKUKAN saat perpindahan - itu jumlah yang benar-benar dijurnal. Angka sumbernya
 * masih bisa bertambah sesudahnya karena MUK terus menagih, dan selisih yang muncul kemudian
 * bukan kesalahan melainkan biaya yang datang belakangan.
 */
class BsUnitSiapJual extends Model
{
    use HasTenant;

    protected $table = 'bs_unit_siap_jual';

    protected $fillable = [
        'tenant_id', 'unit_id', 'lokasi_kode', 'tanggal', 'nilai',
        'akun_wip', 'akun_siap_jual', 'journal_id', 'dibuat_oleh', 'catatan',
    ];

    protected $casts = ['tanggal' => 'date:Y-m-d', 'nilai' => 'decimal:2'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }
}
