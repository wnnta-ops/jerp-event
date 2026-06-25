<?php

namespace App\Modules\FO\MarketingSales\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Blok area siteplan — penanda area belum dibuka (mis. "TAHAP 2"): polygon hitam
 * + teks bebas, TIDAK terikat unit. Bisa digeser/diubah ukuran di Polygon Editor;
 * tampil read-only menutup area di /siteplan, /siteplanlive, Pilih Unit.
 */
class SiteplanBlock extends TenantAwareModel
{
    protected $table = 'fo_siteplan_blocks';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'lokasi_id',
        'coords',
        'label',
        'color',
        'opacity',
        'created_by',
    ];

    protected $casts = [
        'coords' => 'array',
        'opacity' => 'decimal:2',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class);
    }
}
