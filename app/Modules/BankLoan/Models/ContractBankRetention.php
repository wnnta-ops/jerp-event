<?php

namespace App\Modules\BankLoan\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Retensi yang ditahan bank per pengajuan-bank (untuk dilepas bertahap via PR→RR). */
class ContractBankRetention extends Model
{
    protected $table = 'bankloan_contract_bank_retentions';

    protected $fillable = ['contract_bank_id', 'retention_category_id', 'amount_held', 'scheduled_release_date'];

    protected $casts = ['amount_held' => 'decimal:2', 'scheduled_release_date' => 'date'];

    public function contractBank(): BelongsTo
    {
        return $this->belongsTo(ContractBank::class, 'contract_bank_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(RetentionCategory::class, 'retention_category_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(RetentionSubmission::class, 'contract_bank_retention_id');
    }
}
