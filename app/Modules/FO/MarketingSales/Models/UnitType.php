<?php

namespace App\Modules\FO\MarketingSales\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UnitType extends TenantAwareModel
{
    protected $table = 'fo_unit_types';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'code',
        'name',
        'luas_tanah',
        'luas_bangunan',
        'jumlah_kamar',
        'jumlah_kmandi',
        'harga_dasar',
        'is_active',
    ];

    protected $casts = [
        'luas_tanah' => 'decimal:2',
        'luas_bangunan' => 'decimal:2',
        'harga_dasar' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }
}
