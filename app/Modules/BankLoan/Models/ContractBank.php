<?php

namespace App\Modules\BankLoan\Models;

use App\Models\Bank;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Pengajuan kontrak ke bank (multi-bank). status: PENGAJUAN/APPROVED/REJECTED/AKAD. */
class ContractBank extends Model
{
    use SoftDeletes;

    protected $table = 'bankloan_contract_banks';

    protected $fillable = [
        'contract_id', 'bank_id', 'requirement_status',
        'approved_amount', 'approved_description', 'status',
    ];

    protected $casts = ['approved_amount' => 'decimal:2'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(ContractBankRequirement::class, 'contract_bank_id');
    }

    public function interview(): HasOne
    {
        return $this->hasOne(ContractBankInterview::class, 'contract_bank_id');
    }

    public function sp3k(): HasOne
    {
        return $this->hasOne(ContractBankSp3k::class, 'contract_bank_id');
    }

    public function agreement(): HasOne
    {
        return $this->hasOne(ContractBankAgreement::class, 'contract_bank_id');
    }

    public function retentions(): HasMany
    {
        return $this->hasMany(ContractBankRetention::class, 'contract_bank_id');
    }
}
