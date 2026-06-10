<?php

namespace App\Modules\FO\Komisi\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use App\Modules\FO\Adminjual\Models\SalesType;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Modules\FO\MarketingSales\Models\Tahap;
use App\Services\Numerator\NumeratorService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Skema Komisi — aturan rumus komisi per (project, tipe sales, lokasi/tahap).
 * Punya baris rumus per level (Kantor/Koordinator/Sales) + daftar biaya
 * (TipeTarif) yang dipotong dari basis perhitungan.
 */
class SkemaKomisi extends TenantAwareModel
{
    use SoftDeletes;

    protected $table = 'fo_skema_komisi';

    protected $fillable = [
        'tenant_id', 'kode', 'nama', 'periode_dari', 'periode_sampai',
        'project_id', 'sales_type_id', 'lokasi_id', 'tahap_id',
        'rumus', 'dasar_hitung', 'is_active',
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
                $m->kode = app(NumeratorService::class)->next('SKEMAKOMISI');
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
        return $this->hasMany(SkemaKomisiLine::class)->orderBy('urutan');
    }

    public function biaya(): HasMany
    {
        return $this->hasMany(SkemaKomisiBiaya::class);
    }
}
