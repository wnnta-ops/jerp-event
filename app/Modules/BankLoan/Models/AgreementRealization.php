<?php

namespace App\Modules\BankLoan\Models;

use App\Services\Numerator\NumeratorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * RA — Realisasi Akad (pencairan dana dari bank). number = RA-... (numerator).
 * status: DRAFT → POSTED / CANCELLED. excess = disbursed − submission (untuk akunting).
 */
class AgreementRealization extends Model
{
    use SoftDeletes;

    protected $table = 'bankloan_agreement_realizations';

    protected $fillable = [
        'number', 'contract_bank_agreement_id', 'realization_date',
        'disbursed_amount', 'excess_realization_amount', 'status',
    ];

    protected $casts = [
        'realization_date' => 'date',
        'disbursed_amount' => 'decimal:2',
        'excess_realization_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (empty($m->number)) {
                $m->number = app(NumeratorService::class)->next('RA');
            }
        });
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(ContractBankAgreement::class, 'contract_bank_agreement_id');
    }
}
