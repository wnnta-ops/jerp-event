<?php

namespace App\Modules\FO\FinanceAR\Models;

use App\Models\Bank;
use App\Models\Concerns\TenantAwareModel;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tts extends TenantAwareModel
{
    protected $table = 'fo_tts';

    protected $fillable = [
        'tenant_id',
        'no_tts',
        'tgl_tts',
        'tgl_terima',
        'customer_id',
        'kontrak_id',
        'jenis_pembayaran',
        'amount',
        'cara_bayar',
        'bank_id',
        'no_referensi',
        'tgl_efektif',
        'status',
        'skip_gl_posting',
        'bkm_id',
        'keterangan',
    ];

    protected $casts = [
        'tgl_tts' => 'date',
        'tgl_terima' => 'date',
        'tgl_efektif' => 'date',
        'amount' => 'decimal:2',
        'skip_gl_posting' => 'boolean',
    ];

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

    public function bkm(): BelongsTo
    {
        return $this->belongsTo(Bkm::class);
    }
}
