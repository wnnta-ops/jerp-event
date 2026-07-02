<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kategori Gimmick: memetakan kategori → akun debit + akun kredit.
 * Fungsi identik dengan BsApCategory (Kategori Invoice).
 */
class BsGimmickCategory extends Model
{
    use HasTenant;

    protected $table = 'bs_gimmick_categories';
    protected $fillable = ['tenant_id', 'code', 'name', 'debit_account_id', 'payable_account_id', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function debitAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'debit_account_id'); }
    public function payableAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'payable_account_id'); }
}
