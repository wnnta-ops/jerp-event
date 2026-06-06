<?php

namespace App\Modules\FO\Adminjual\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Gimmick extends TenantAwareModel
{
    protected $table = 'fo_gimmicks';

    protected $fillable = [
        'tenant_id', 'project_id', 'code', 'name', 'jenis',
        'nilai', 'berlaku_mulai', 'berlaku_sampai',
        'is_active', 'keterangan',
    ];

    protected $casts = [
        'nilai' => 'decimal:2',
        'berlaku_mulai' => 'date',
        'berlaku_sampai' => 'date',
        'is_active' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
