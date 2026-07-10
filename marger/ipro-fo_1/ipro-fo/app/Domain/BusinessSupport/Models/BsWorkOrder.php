<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BsWorkOrder extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_work_orders';
    protected $fillable = [
        'tenant_id', 'project_id', 'spk_number', 'no_manual', 'spk_date', 'est_start', 'est_end',
        'supplier_id', 'cost_center_id', 'construction_account_id',
        'title', 'jenis', 'releaser', 'pic_project', 'is_pkp',
        'contract_value', 'nominal_profit', 'addendum_value', 'retention_pct', 'advance_pct', 'ppn_pct', 'progress_value', 'material_used', 'progress_mutated', 'retention_held',
        'status', 'retention_released_at', 'cancelled_at', 'cancel_reason', 'notes', 'print_payload', 'created_by',
    ];
    protected $casts = [
        'spk_date' => 'date', 'est_start' => 'date', 'est_end' => 'date', 'retention_released_at' => 'date', 'cancelled_at' => 'date',
        'is_pkp' => 'boolean',
        'contract_value' => 'decimal:2', 'nominal_profit' => 'decimal:2', 'addendum_value' => 'decimal:2', 'retention_pct' => 'decimal:2', 'advance_pct' => 'decimal:2', 'ppn_pct' => 'decimal:2',
        'progress_value' => 'decimal:2', 'material_used' => 'decimal:2', 'progress_mutated' => 'decimal:2', 'retention_held' => 'decimal:2',
        'print_payload' => 'array',
    ];

    public const JENIS = ['bangunan' => 'Bangunan', 'sarana_prasarana' => 'Sarana Prasarana', 'lain_lain' => 'Lain-lain'];

    public function project(): BelongsTo { return $this->belongsTo(BsProject::class, 'project_id'); }
    public function supplier(): BelongsTo { return $this->belongsTo(BsSupplier::class, 'supplier_id'); }
    public function costCenter(): BelongsTo { return $this->belongsTo(BsCostCenter::class, 'cost_center_id'); }
    public function constructionAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'construction_account_id'); }
    public function progress(): HasMany { return $this->hasMany(BsWorkOrderProgress::class, 'work_order_id'); }
    public function addendums(): HasMany { return $this->hasMany(BsWorkOrderAddendum::class, 'work_order_id'); }
    public function materials(): HasMany { return $this->hasMany(BsWorkOrderMaterial::class, 'work_order_id'); }
    public function materialReturns(): HasMany { return $this->hasMany(BsWorkOrderMaterialReturn::class, 'work_order_id'); }
    public function progressMutations(): HasMany { return $this->hasMany(BsWorkOrderProgressMutation::class, 'work_order_id'); }
    public function items(): HasMany { return $this->hasMany(BsWorkOrderItem::class, 'work_order_id'); }
    public function terms(): HasMany { return $this->hasMany(BsWorkOrderTerm::class, 'work_order_id'); }
    public function units(): HasMany { return $this->hasMany(BsWorkOrderUnit::class, 'work_order_id'); }

    public function getRemainingValueAttribute(): float { return (float) $this->contract_value - (float) $this->progress_value; }
}
