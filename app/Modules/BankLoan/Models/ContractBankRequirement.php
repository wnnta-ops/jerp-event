<?php

namespace App\Modules\BankLoan\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractBankRequirement extends Model
{
    protected $table = 'bankloan_contract_bank_requirements';

    protected $fillable = [
        'contract_bank_id', 'requirement_id', 'filing_date',
        'file_path', 'original_file_name', 'status',
    ];

    protected $casts = ['filing_date' => 'date'];

    public function contractBank(): BelongsTo
    {
        return $this->belongsTo(ContractBank::class, 'contract_bank_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(Requirement::class);
    }
}
