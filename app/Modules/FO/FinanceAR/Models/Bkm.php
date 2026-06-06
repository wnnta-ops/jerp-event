<?php

namespace App\Modules\FO\FinanceAR\Models;

use App\Models\Bank;
use App\Models\Concerns\TenantAwareModel;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Kontrak;
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
        'bank_id',
        'amount',
        'jenis_pembayaran',
        'akun_kas',
        'no_referensi',
        'status',
        'keterangan',
    ];

    protected $casts = [
        'tgl_bkm' => 'date',
        'amount' => 'decimal:2',
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

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }
}
