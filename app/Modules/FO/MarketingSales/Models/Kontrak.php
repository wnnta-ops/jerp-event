<?php

namespace App\Modules\FO\MarketingSales\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Modules\FO\Adminjual\Models\Skema;
use App\Modules\FO\Komisi\Models\Agent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Kontrak extends TenantAwareModel
{
    protected $table = 'fo_kontrak';

    protected $fillable = [
        'tenant_id',
        'no_kontrak',
        'reservasi_id',
        'customer_id',
        'unit_id',
        'skema_id',
        'agent_id',
        'tgl_kontrak',
        'harga_jual',
        'diskon',
        'ppn',
        'total_kontrak',
        'cara_bayar',
        'tenor_bulan',
        'dp_persen',
        'dp_amount',
        'status',
        'keterangan',
    ];

    protected $casts = [
        'tgl_kontrak' => 'date',
        'harga_jual' => 'decimal:2',
        'diskon' => 'decimal:2',
        'ppn' => 'decimal:2',
        'total_kontrak' => 'decimal:2',
        'dp_persen' => 'decimal:2',
        'dp_amount' => 'decimal:2',
    ];

    public function reservasi(): BelongsTo
    {
        return $this->belongsTo(Reservasi::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function skema(): BelongsTo
    {
        return $this->belongsTo(Skema::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
