<?php

namespace App\Modules\FO\Adminjual\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use App\Modules\FO\MarketingSales\Models\UnitType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceList extends TenantAwareModel
{
    protected $table = 'fo_price_lists';

    protected $fillable = [
        'tenant_id', 'project_id', 'unit_type_id', 'code',
        'berlaku_mulai', 'berlaku_sampai',
        'harga_dasar', 'harga_jual', 'ppn_persen',
        'is_active', 'keterangan',
    ];

    protected $casts = [
        'berlaku_mulai' => 'date',
        'berlaku_sampai' => 'date',
        'harga_dasar' => 'decimal:2',
        'harga_jual' => 'decimal:2',
        'ppn_persen' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function unitType(): BelongsTo
    {
        return $this->belongsTo(UnitType::class);
    }
}
