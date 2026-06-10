<?php

namespace App\Modules\FO\Adminjual\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use App\Services\Numerator\NumeratorService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Gimmick extends TenantAwareModel
{
    protected $table = 'fo_gimmicks';

    protected static function booted(): void
    {
        parent::booted();
        static::creating(function (self $m) {
            if (empty($m->code)) {
                $m->code = app(NumeratorService::class)->next('GIMMICK');
            }
        });
    }

    protected $fillable = [
        'tenant_id', 'project_id', 'tipe_gimmick_id', 'code', 'name', 'satuan', 'jumlah',
        'nilai', 'syarat_persen_pelunasan', 'berlaku_mulai', 'berlaku_sampai',
        'is_active', 'keterangan',
    ];

    protected $casts = [
        'jumlah' => 'integer',
        'nilai' => 'decimal:2',
        'syarat_persen_pelunasan' => 'decimal:4',
        'berlaku_mulai' => 'date',
        'berlaku_sampai' => 'date',
        'is_active' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tipeGimmick(): BelongsTo
    {
        return $this->belongsTo(TipeGimmick::class, 'tipe_gimmick_id');
    }
}
