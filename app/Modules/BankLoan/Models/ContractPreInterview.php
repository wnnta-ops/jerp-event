<?php

namespace App\Modules\BankLoan\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractPreInterview extends Model
{
    protected $table = 'bankloan_contract_pre_interviews';

    protected $fillable = ['contract_id', 'fund_source', 'purchasing_purpose'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }
}
