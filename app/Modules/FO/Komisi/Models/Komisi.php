<?php

namespace App\Modules\FO\Komisi\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Services\Numerator\NumeratorService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Komisi extends TenantAwareModel
{
    protected $table = 'fo_komisi';

    protected $fillable = [
        'tenant_id', 'no_komisi', 'tgl_komisi',
        'agent_id', 'kontrak_id',
        'dpp', 'persen_komisi', 'amount',
        'pph_persen', 'pph_amount', 'ppn_amount', 'amount_net',
        'status', 'tgl_bayar', 'rek_bank', 'rek_number', 'rek_holder', 'bkk_ref_id', 'keterangan',
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

    protected static function booted(): void
    {
        parent::booted();
        static::creating(function (self $m) {
            if (empty($m->no_komisi)) {
                $m->no_komisi = app(NumeratorService::class)->next('KOMISI');
            }
        });
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function kontrak(): BelongsTo
    {
        return $this->belongsTo(Kontrak::class);
    }
}
