<?php

namespace App\Modules\FO\Komisi\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use App\Services\Numerator\NumeratorService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Setup Tarif / Tipe Biaya (PPH, BPHTB, dst). Dipakai sebagai komponen
 * "biaya-biaya" pemotong pada Skema Komisi.
 */
class TipeTarif extends TenantAwareModel
{
    use SoftDeletes;

    protected $table = 'fo_tipe_tarif';

    protected $fillable = [
        'tenant_id', 'project_id', 'kode', 'nama',
        'nilai_biaya', 'satuan', 'dikenakan', 'nilai_pengurang', 'is_active',
    ];

    protected $casts = [
        'nilai_biaya' => 'decimal:4',
        'nilai_pengurang' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        parent::booted();
        static::creating(function (self $m) {
            if (empty($m->kode)) {
                $m->kode = app(NumeratorService::class)->next('TIPETARIF');
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Label tampilan: "PPH (2.5%)" atau "BPHTB (Rp 5.000)". */
    public function getLabelAttribute(): string
    {
        $val = $this->satuan === 'PERSEN'
            ? rtrim(rtrim(number_format((float) $this->nilai_biaya, 2), '0'), '.').'%'
            : 'Rp '.number_format((float) $this->nilai_biaya, 0, ',', '.');

        return $this->nama.' ('.$val.')';
    }
}
