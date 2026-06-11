<?php

namespace App\Modules\BankLoan\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SP3K — Surat Persetujuan Prinsip Kredit. result: APPROVED/CONDITIONAL/REJECTED. */
class ContractBankSp3k extends Model
{
    protected $table = 'bankloan_contract_bank_sp3k';

    protected $fillable = ['contract_bank_id', 'sp3k_number', 'sp3k_date', 'result', 'approved_amount'];

    protected $casts = ['sp3k_date' => 'date', 'approved_amount' => 'decimal:2'];

    public function contractBank(): BelongsTo
    {
        return $this->belongsTo(ContractBank::class, 'contract_bank_id');
    }
}
