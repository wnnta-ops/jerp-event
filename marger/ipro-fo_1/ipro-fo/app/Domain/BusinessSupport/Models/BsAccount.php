<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Chart of Account (Buku Besar / General Ledger).
 */
class BsAccount extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_accounts';

    protected $fillable = [
        'tenant_id', 'code', 'category_code', 'name', 'type', 'level', 'parent_code', 'normal_balance', 'parent_id', 'is_postable', 'is_active',
        'cf_direct_category_id', 'cf_indirect_category_id',
    ];

    /** Kategori (head) sebuah kode — biasanya digit pertama, kecuali pengelompokan eProperty. */
    public static function categoryOf(string $code): string
    {
        return match (true) {
            str_starts_with($code, '502'), str_starts_with($code, '503') => '6', // BEBAN PENJUALAN & UMUM → Kategori 6
            default => $code[0],
        };
    }

    protected $casts = [
        'level'       => 'integer',
        'is_postable' => 'boolean',
        'is_active'   => 'boolean',
        // Akun boleh dijurnal TANPA cost center (lihat CoaTanpaCostCenterSeeder).
        'cost_center_optional' => 'boolean',
    ];

    /**
     * Enam tingkatan bagan akun eProperty, dari yang paling tinggi ke paling rendah.
     *
     * Panjang kode mengikuti levelnya: 2 x level - 1 digit (1, 3, 5, 7, 9, 11), sebab tiap
     * turun satu tingkat menambah dua digit di belakang kode induknya.
     */
    public const LEVELS = [
        1 => 'Kategori',
        2 => 'Sub Kategori',
        3 => 'Ledger',
        4 => 'Detil',
        5 => 'Detil Transaksi',
        6 => 'Detil Sub Transaksi',
    ];

    public const TYPES = [
        'asset'     => 'Aktiva',
        'liability' => 'Kewajiban',
        'equity'    => 'Ekuitas',
        'income'    => 'Pendapatan',
        'expense'   => 'Beban',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Kategori Cash Flow Direct akun ini (bs_cash_flow_categories, kind=direct). */
    public function cfDirectCategory(): BelongsTo
    {
        return $this->belongsTo(BsCashFlowCategory::class, 'cf_direct_category_id');
    }

    /** Kategori Cash Flow Indirect akun ini (bs_cash_flow_categories, kind=indirect). */
    public function cfIndirectCategory(): BelongsTo
    {
        return $this->belongsTo(BsCashFlowCategory::class, 'cf_indirect_category_id');
    }
}
