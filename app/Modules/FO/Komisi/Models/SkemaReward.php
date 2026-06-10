<?php

namespace App\Modules\FO\Komisi\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use App\Modules\FO\Adminjual\Models\SalesType;
use App\Services\Numerator\NumeratorService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Setup Skema Reward (Closing Fee) — aturan reward per (project, tipe sales),
 * dengan baris rumus reward per level berdasarkan rentang target.
 */
class SkemaReward extends TenantAwareModel
{
    use SoftDeletes;

    protected $table = 'fo_skema_reward';

    protected $fillable = [
        'tenant_id', 'kode', 'nama', 'periode_dari', 'periode_sampai',
        'project_id', 'sales_type_id', 'rumus_closing_fee', 'is_active',
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
                $m->kode = app(NumeratorService::class)->next('SKEMAREWARD');
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

    public function lines(): HasMany
    {
        return $this->hasMany(SkemaRewardLine::class)->orderBy('urutan');
    }
}
