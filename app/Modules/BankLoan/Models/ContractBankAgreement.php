<?php

namespace App\Modules\BankLoan\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Akad / Perjanjian Kredit (dinotarisasi). disbursement_type: FULL/TERMIN/CONDITIONAL. */
class ContractBankAgreement extends Model
{
    protected $table = 'bankloan_contract_bank_agreements';

    protected $fillable = ['contract_bank_id', 'agreement_number', 'agreement_date', 'notary', 'disbursement_type'];

    protected $casts = ['agreement_date' => 'date'];

    public function contractBank(): BelongsTo
    {
        return $this->belongsTo(ContractBank::class, 'contract_bank_id');
    }

    public function realizations(): HasMany
    {
        return $this->hasMany(AgreementRealization::class, 'contract_bank_agreement_id');
    }
}
