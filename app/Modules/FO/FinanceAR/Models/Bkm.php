<?php

namespace App\Modules\FO\FinanceAR\Models;

use App\Models\Bank;
use App\Models\Concerns\TenantAwareModel;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\KontrakTagihan;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bkm extends TenantAwareModel
{
    protected $table = 'fo_bkm';

    protected $fillable = [
        'tenant_id',
        'no_bkm',
        'tgl_bkm',
        'tts_id',
        'customer_id',
        'kontrak_id',
        'kontrak_tagihan_id',
        'bank_id',
        'amount',
        'jenis_pembayaran',
        'no_referensi',
        'status',
        'bo_posted_at',
        'keterangan',
    ];

    protected $casts = [
        'tgl_bkm' => 'date',
        'amount' => 'decimal:2',
        'bo_posted_at' => 'datetime',
    ];

    public function tts(): BelongsTo
    {
        return $this->belongsTo(Tts::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function kontrak(): BelongsTo
    {
        return $this->belongsTo(Kontrak::class);
    }

    public function kontrakTagihan(): BelongsTo
    {
        return $this->belongsTo(KontrakTagihan::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }
}
