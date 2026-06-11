<?php

namespace App\Modules\BankLoan\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Wawancara calon debitur. result: LANJUT/PROSES/DITOLAK/DEFER/PENDING. */
class ContractBankInterview extends Model
{
    protected $table = 'bankloan_contract_bank_interviews';

    protected $fillable = ['contract_bank_id', 'interview_date', 'result', 'notes'];

    protected $casts = ['interview_date' => 'date'];

    public function contractBank(): BelongsTo
    {
        return $this->belongsTo(ContractBank::class, 'contract_bank_id');
    }
}
