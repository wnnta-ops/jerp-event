<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kategori Invoice (AR): memetakan kategori → akun pendapatan + akun piutang.
 */
class BsArCategory extends Model
{
    use HasTenant;

    protected $table = 'bs_ar_categories';
    protected $fillable = ['tenant_id', 'code', 'name', 'income_account_id', 'receivable_account_id', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function incomeAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'income_account_id'); }
    public function receivableAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'receivable_account_id'); }
}
