<?php

namespace App\Modules\FO\Komisi\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Modules\FO\Adminjual\Models\SalesLevel;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Services\Numerator\NumeratorService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** BLT hasil generate (per level: Sales/Koordinator/Kantor). NETT = NILAI BLT − PPh. */
class Blt extends TenantAwareModel
{
    use SoftDeletes;

    protected $table = 'fo_blt';

    protected $fillable = [
        'tenant_id', 'no_blt', 'tgl_blt', 'kontrak_id', 'agent_id', 'sales_level_id',
        'level_type', 'nama_user', 'nilai_blt', 'pph_jenis', 'pph_persen', 'pph_amount',
        'ppn_amount', 'nett', 'status', 'tgl_bayar', 'rek_bank', 'rek_number', 'rek_holder', 'keterangan',
    ];

    protected $casts = [
        'tgl_blt' => 'date',
        'tgl_bayar' => 'date',
        'nilai_blt' => 'decimal:2',
        'pph_persen' => 'decimal:4',
        'pph_amount' => 'decimal:2',
        'ppn_amount' => 'decimal:2',
        'nett' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        parent::booted();
        static::creating(function (self $m) {
            if (empty($m->no_blt)) {
                $m->no_blt = app(NumeratorService::class)->next('BLT');
            }
        });
    }

    public function kontrak(): BelongsTo
    {
        return $this->belongsTo(Kontrak::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\FO\Komisi\Models\Agent::class);
    }

    public function salesLevel(): BelongsTo
    {
        return $this->belongsTo(SalesLevel::class, 'sales_level_id');
    }
}
