<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan Kalkulator KPR — satu baris per tenant, isinya satu dokumen JSON
 * (bank, skema bunga, tahapan, promo, foto, bulan berlaku).
 *
 * Dipakai dua layar: KPR → Set Up → Kategori Perhitungan KPR (mengubah) dan
 * KPR → Query/Report → Kalkulator KPR (membaca).
 */
class KprCalculatorSetting extends Model
{
    use HasTenant;

    // Ditulis eksplisit: tanpa ini Laravel menebak 'kpr_calculator_settings' dari nama kelas
    // yang berbeda, dan seluruh master KPR di sini memakai awalan kpr_.
    protected $table = 'kpr_calculator_settings';

    protected $fillable = ['tenant_id', 'config'];

    protected $casts = [
        'config' => 'array',
    ];
}
