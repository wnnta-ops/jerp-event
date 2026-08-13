<?php

namespace App\Modules\FO\Launching\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Modules\FO\MarketingSales\Models\Tahap;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Setup Bank — master bank KPR per Cluster (Lokasi) + Tahap. Sumber dropdown
 * "Bank Pilihan" di form pendaftaran NUP publik; dropdown hanya muncul (dan
 * wajib) saat Cara Bayar = KPR. Lokasi/Tahap kosong = berlaku se-project.
 */
class Bank extends TenantAwareModel
{
    protected $table = 'fo_banks';

    protected $fillable = [
        'tenant_id', 'project_id', 'lokasi_id', 'tahap_id',
        'nama', 'urutan', 'keterangan', 'is_active',
    ];

    protected $casts = [
        'urutan' => 'integer',
        'is_active' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class);
    }

    public function tahap(): BelongsTo
    {
        return $this->belongsTo(Tahap::class);
    }
}
