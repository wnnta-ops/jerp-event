<?php

namespace App\Modules\BankLoan\Models;

use App\Services\Numerator\NumeratorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PR — Pengajuan Retensi (request pelepasan dana retensi ke bank). number = PR-...
 * status: DRAFT → SUBMITTED → APPROVED → REALIZED / REJECTED.
 */
class RetentionSubmission extends Model
{
    use SoftDeletes;

    protected $table = 'bankloan_retention_submissions';

    protected $fillable = [
        'number', 'contract_bank_retention_id', 'submission_date',
        'requested_amount', 'sales_person_name', 'contract_number', 'status',
    ];

    protected $casts = ['submission_date' => 'date', 'requested_amount' => 'decimal:2'];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (empty($m->number)) {
                $m->number = app(NumeratorService::class)->next('PR');
            }
        });
    }

    public function retention(): BelongsTo
    {
        return $this->belongsTo(ContractBankRetention::class, 'contract_bank_retention_id');
    }

    public function realizations(): HasMany
    {
        return $this->hasMany(RetentionRealization::class, 'retention_submission_id');
    }
}
