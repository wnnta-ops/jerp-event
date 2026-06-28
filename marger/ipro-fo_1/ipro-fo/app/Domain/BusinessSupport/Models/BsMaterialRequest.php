<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BsMaterialRequest extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_material_requests';
    protected $fillable = [
        'tenant_id', 'project_id', 'cost_center_id', 'mr_number', 'request_date', 'needed_date',
        'department', 'status', 'notes', 'created_by', 'approved_at', 'approved_by',
    ];
    protected $casts = ['request_date' => 'date', 'needed_date' => 'date', 'approved_at' => 'datetime'];

    public function lines(): HasMany { return $this->hasMany(BsMaterialRequestLine::class, 'material_request_id'); }
    public function costCenter(): BelongsTo { return $this->belongsTo(BsCostCenter::class, 'cost_center_id'); }
}
