<?php

namespace App\Modules\FO\Adminjual\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Services\Numerator\NumeratorService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tipe Gimmick — paket gimmick per project/cluster/periode (mis. CLUSTER 3.3,
 * REGULER, PRIORITAS). Membawahi item Gimmick (Pendaftaran Gimmick).
 */
class TipeGimmick extends TenantAwareModel
{
    use SoftDeletes;

    protected $table = 'fo_tipe_gimmick';

    protected $fillable = [
        'tenant_id', 'kode', 'nama', 'project_id', 'lokasi_id', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        parent::booted();
        static::creating(function (self $m) {
            if (empty($m->kode)) {
                $m->kode = app(NumeratorService::class)->next('TIPEGIMMICK');
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Cluster paket — Closing/SPR hanya menawarkan paket cluster unit. Null = semua cluster. */
    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class);
    }

    public function gimmicks(): HasMany
    {
        return $this->hasMany(Gimmick::class);
    }
}
