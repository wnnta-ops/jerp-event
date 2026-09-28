<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Tipe rumah beserta luas bangunannya (blok atas lembar SET UP KATEGORI COGS-HOUSING).
 *
 * Hanya nama dan luas bangunan yang diketik. Jumlah unit, budget per unit, budget per meter,
 * dan seluruh angka realisasinya dihitung dari baris SPK dan dari daftar unit — menyimpannya
 * akan membuat layar ini bisa berbeda dari Rincian COGS tanpa ada yang tahu yang mana benar.
 */
class BsCogs2HousingTipe extends Model
{
    use HasTenant;

    protected $table = 'bs_cogs2_housing_tipe';

    protected $fillable = ['tenant_id', 'periode_id', 'nama', 'luas_bangunan', 'urut'];

    protected $casts = ['periode_id' => 'integer', 'luas_bangunan' => 'decimal:2', 'urut' => 'integer'];
}
