<?php

namespace App\Modules\FO\Komisi\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use App\Modules\FO\Adminjual\Models\SalesType;
use App\Modules\FO\MarketingSales\Models\UnitType;
use App\Services\Numerator\NumeratorService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Skema BLT — aturan rumus BLT per (project, tipe sales, tipe unit) + baris per level. */
class SkemaBlt extends TenantAwareModel
{
    use SoftDeletes;

    protected $table = 'fo_skema_blt';

    protected $fillable = [
        'tenant_id', 'kode', 'nama', 'periode_dari', 'periode_sampai',
        'project_id', 'sales_type_id', 'unit_type_id', 'rumus', 'dasar_hitung', 'is_active',
    ];

    protected $casts = [
        'periode_dari' => 'date',
        'periode_sampai' => 'date',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        parent::booted();
        static::creating(function (self $m) {
            if (empty($m->kode)) {
                $m->kode = app(NumeratorService::class)->next('SKEMABLT');
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function salesType(): BelongsTo
    {
        return $this->belongsTo(SalesType::class);
    }

    public function unitType(): BelongsTo
    {
        return $this->belongsTo(UnitType::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SkemaBltLine::class)->orderBy('urutan');
    }
}
