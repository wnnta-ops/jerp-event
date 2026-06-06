<?php

namespace App\Modules\FO\FinanceAR\Models;

use App\Models\Bank;
use App\Models\Concerns\TenantAwareModel;
use App\Modules\FO\MarketingSales\Models\Customer;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Va extends TenantAwareModel
{
    protected $table = 'fo_va';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'bank_id',
        'va_number',
        'rekening_holder',
        'tgl_aktif',
        'tgl_kadaluarsa',
        'is_active',
        'keterangan',
    ];

    protected $casts = [
        'tgl_aktif' => 'date',
        'tgl_kadaluarsa' => 'date',
        'is_active' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }
}
