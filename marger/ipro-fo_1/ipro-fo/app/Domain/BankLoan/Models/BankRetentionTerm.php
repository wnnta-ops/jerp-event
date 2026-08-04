<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ketentuan retensi sebuah bank untuk satu Kategori Retensi (mis. BTN → PONDASI 30%).
 *
 * gl_account_id = COA Bank yang di-link-kan; menjadi default akun Debit saat pencairan/retensi
 * masuk Approval Retensi (lihat BankApprovalController::bankTermAccountMap()).
 */
class BankRetentionTerm extends Model
{
    protected $fillable = ['bank_partner_id', 'retention_category_id', 'gl_account_id', 'percentage', 'amount', 'notes'];

    protected $casts = [
        'percentage' => 'decimal:2',
        'amount'     => 'decimal:2',
    ];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(BankPartner::class, 'bank_partner_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(RetentionCategory::class, 'retention_category_id');
    }

    /** COA Bank yang di-link-kan untuk kategori ini. */
    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\BusinessSupport\Models\BsAccount::class, 'gl_account_id');
    }
}
