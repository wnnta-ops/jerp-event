<?php

namespace App\Modules\FO\Komisi\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Komisi extends TenantAwareModel
{
    protected $table = 'fo_komisi';

    protected $fillable = [
        'tenant_id', 'no_komisi', 'tgl_komisi',
        'agent_id', 'kontrak_id',
        'dpp', 'persen_komisi', 'amount',
        'pph_persen', 'pph_amount', 'amount_net',
        'status', 'tgl_bayar', 'bkk_ref_id', 'keterangan',
    ];

    protected $casts = [
        'tgl_komisi' => 'date',
        'tgl_bayar' => 'date',
        'dpp' => 'decimal:2',
        'persen_komisi' => 'decimal:2',
        'amount' => 'decimal:2',
        'pph_persen' => 'decimal:2',
        'pph_amount' => 'decimal:2',
        'amount_net' => 'decimal:2',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function kontrak(): BelongsTo
    {
        return $this->belongsTo(Kontrak::class);
    }
}
