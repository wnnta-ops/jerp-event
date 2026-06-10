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
 * Termin Komisi — aturan pencairan komisi bertahap (per level) berdasarkan
 * syarat: % terbayar (Lunas/BF/DP/Angsuran) dan/atau dokumen (PPJB/AJB/AKAD).
 */
class TerminKomisi extends TenantAwareModel
{
    use SoftDeletes;

    protected $table = 'fo_termin_komisi';

    protected $fillable = [
        'tenant_id', 'kode', 'nama', 'project_id', 'sales_type_id',
        'skema_komisi_id', 'cara_bayar', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        parent::booted();
        static::creating(function (self $m) {
            if (empty($m->kode)) {
                $m->kode = app(NumeratorService::class)->next('TERMINKOMISI');
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

    public function skema(): BelongsTo
    {
        return $this->belongsTo(SkemaKomisi::class, 'skema_komisi_id');
    }

    public function kondisi(): HasMany
    {
        return $this->hasMany(TerminKomisiKondisi::class)->orderBy('urutan');
    }
}
