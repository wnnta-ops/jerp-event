<?php

namespace App\Modules\BankLoan\Models;

use App\Models\Company;
use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kontrak KPR — TULANG PUNGGUNG modul, di-mirror dari FO Kontrak (cara_bayar=KPR)
 * via SyncContractToBankLoan. contract_number = no_kontrak FO (sama, terhubung).
 * Operator KPR memproses di atasnya: pre-interview → BI checking → bank → SP3K →
 * akad → realisasi (RA) → retensi (PR/RR).
 *
 * active_status: DRAFT → REGISTERED → ACTIVE → CLOSED / CANCELLED
 */
class Contract extends TenantAwareModel
{
    use SoftDeletes;

    protected $table = 'bankloan_contracts';

    protected $fillable = [
        'tenant_id', 'contract_number', 'booking_number', 'booking_date',
        'customer_id', 'project_id', 'company_id', 'unit_code', 'sales_person_name',
        'total_price', 'submission_amount', 'down_payment',
        'active_status', 'buyback_status',
    ];

    protected $casts = [
        'booking_date' => 'date',
        'total_price' => 'decimal:2',
        'submission_amount' => 'decimal:2',
        'down_payment' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(BankloanCustomer::class, 'customer_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function preInterview(): HasOne
    {
        return $this->hasOne(ContractPreInterview::class, 'contract_id');
    }

    public function biCheckings(): HasMany
    {
        return $this->hasMany(BiChecking::class, 'contract_id');
    }

    public function contractBanks(): HasMany
    {
        return $this->hasMany(ContractBank::class, 'contract_id');
    }
}
