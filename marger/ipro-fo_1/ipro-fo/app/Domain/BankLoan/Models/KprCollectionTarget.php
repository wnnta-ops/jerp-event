<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Target akad KPR sebulan — sisi "budget" pada layar Realisasi vs Budget.
 *
 * target_value disimpan dalam RUPIAH PENUH. Layar menyingkatnya menjadi "Rp M" hanya saat
 * menampilkan; menyimpan angka yang sudah dibulatkan ke miliar membuat selisih ratusan juta
 * lenyap tanpa jejak.
 */
class KprCollectionTarget extends Model
{
    use HasTenant;

    protected $table = 'kpr_collection_targets';

    protected $fillable = ['tenant_id', 'period_year', 'period_month', 'target_unit', 'target_value', 'created_by'];

    protected $casts = [
        'period_year'  => 'integer',
        'period_month' => 'integer',
        'target_unit'  => 'integer',
        'target_value' => 'decimal:2',
    ];
}
