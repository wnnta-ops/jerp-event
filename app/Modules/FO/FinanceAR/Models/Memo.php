<?php

namespace App\Modules\FO\FinanceAR\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Memo extends TenantAwareModel
{
    protected $table = 'fo_memo';

    protected $fillable = [
        'tenant_id',
        'no_memo',
        'tgl_memo',
        'customer_id',
        'kontrak_id',
        'jenis',
        'arah',
        'amount',
        'akun_pasangan',
        'status',
        'keterangan',
    ];

    protected $casts = [
        'tgl_memo' => 'date',
        'amount' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function kontrak(): BelongsTo
    {
        return $this->belongsTo(Kontrak::class);
    }
}
