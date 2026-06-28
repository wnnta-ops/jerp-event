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
}
