<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Transaction Rule — template pemetaan transaksi sumber → jurnal otomatis.
 */
class BsTransactionRule extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_transaction_rules';

    protected $fillable = ['tenant_id', 'code', 'name', 'source', 'event', 'description', 'sort_order', 'is_active'];

    protected $casts = ['sort_order' => 'integer', 'is_active' => 'boolean'];

    public const AMOUNT_BASES = [
        'total'    => 'Total',
        'dpp'      => 'DPP',
        'ppn'      => 'PPN',
        'pph'      => 'PPh',
        'net'      => 'Nett (DPP+PPN−PPh)',
        'nominal'  => 'Nominal',
        'qty_cost' => 'Qty × Cost',
    ];

    public function lines(): HasMany { return $this->hasMany(BsTransactionRuleLine::class, 'rule_id')->orderBy('sort_order'); }
}
