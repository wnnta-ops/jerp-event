<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pemakaian satu Kategori Invoice di satu modul (Invoice Hutang / Reimburse / Deposit):
 * posisi kredit dan akun yang dikredit saat pengajuan modul itu dijurnal.
 *
 * Akun debit tetap milik kategori — sama untuk semua modul.
 */
class BsApCategoryUsage extends Model
{
    use HasTenant;

    protected $table = 'bs_ap_category_usages';
    protected $fillable = ['tenant_id', 'category_id', 'jenis', 'credit_position', 'payable_account_id'];

    public function category(): BelongsTo { return $this->belongsTo(BsApCategory::class, 'category_id'); }
    public function payableAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'payable_account_id'); }
}
