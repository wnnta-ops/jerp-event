<?php

namespace App\Modules\FO\Adminjual\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Modules\FO\MarketingSales\Models\Tahap;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Skema extends TenantAwareModel
{
    protected $table = 'fo_skema';

    protected $fillable = [
        'tenant_id', 'project_id', 'lokasi_id', 'tahap_id', 'code', 'name',
        'cara_bayar', 'dp_persen', 'ang_persen', 'bf_nominal', 'subsidi_persen',
        'tenor_bulan', 'jumlah_termin',
        'present_value', 'diskon_persen', 'bf_dipotong_di', 'tampil_siteplan',
        'keterangan', 'is_active',
    ];

    protected $casts = [
        'dp_persen' => 'decimal:2',
        'ang_persen' => 'decimal:2',
        'bf_nominal' => 'decimal:2',
        'subsidi_persen' => 'decimal:2',
        'present_value' => 'decimal:2',
        'diskon_persen' => 'decimal:2',
        'tampil_siteplan' => 'boolean',
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

    public function lines(): HasMany
    {
        return $this->hasMany(SkemaLine::class, 'skema_id')->orderBy('urutan');
    }
}
