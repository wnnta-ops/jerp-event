<?php

namespace App\Modules\FO\MarketingSales\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Services\Numerator\NumeratorService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Pindah Unit — ganti unit kontrak. Setiap record = satu peristiwa pindah (history). */
class PindahUnit extends TenantAwareModel
{
    use SoftDeletes;

    protected $table = 'fo_pindah_unit';

    protected $fillable = [
        'tenant_id', 'no_pindah', 'kontrak_id', 'customer_id', 'unit_lama_id', 'unit_baru_id',
        'tgl_pindah', 'biaya_admin', 'status', 'keterangan',
    ];

    protected $casts = [
        'tgl_pindah' => 'date',
        'biaya_admin' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        parent::booted();
        static::creating(function (self $m) {
            if (empty($m->no_pindah)) {
                $m->no_pindah = app(NumeratorService::class)->next('PU');
            }
        });
    }

    public function kontrak(): BelongsTo
    {
        return $this->belongsTo(Kontrak::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function unitLama(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_lama_id');
    }

    public function unitBaru(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_baru_id');
    }
}
