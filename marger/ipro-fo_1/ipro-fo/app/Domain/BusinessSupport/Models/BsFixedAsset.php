<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BsFixedAsset extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_fixed_assets';
    protected $fillable = [
        'tenant_id', 'asset_number', 'name', 'category', 'acquisition_date', 'acquisition_cost',
        'residual_value', 'useful_life_months', 'method', 'asset_account_id', 'accum_account_id',
        'expense_account_id', 'accumulated_depreciation', 'last_depreciation_date', 'status',
        'acquire_journal_id', 'created_by',
        'disposed_date', 'disposal_type', 'disposal_proceeds', 'disposal_note', 'disposal_journal_id',
    ];
    protected $casts = [
        'acquisition_date' => 'date', 'last_depreciation_date' => 'date',
        'acquisition_cost' => 'decimal:2', 'residual_value' => 'decimal:2',
        'accumulated_depreciation' => 'decimal:2', 'useful_life_months' => 'integer',
        'disposed_date' => 'date', 'disposal_proceeds' => 'decimal:2',
    ];

    public const CATEGORIES = [
        'bangunan'   => 'Bangunan',
        'kendaraan'  => 'Kendaraan',
        'inventaris' => 'Inventaris Kantor',
        'tanah'      => 'Tanah (tidak disusutkan)',
    ];

    public function entries(): HasMany { return $this->hasMany(BsDepreciationEntry::class, 'fixed_asset_id'); }
    public function assetAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'asset_account_id'); }

    /** Dasar penyusutan = harga perolehan − nilai residu. */
    public function getDepreciableBaseAttribute(): float { return max(0.0, (float) $this->acquisition_cost - (float) $this->residual_value); }

    /** Penyusutan per bulan (garis lurus). */
    public function getMonthlyDepreciationAttribute(): float
    {
        if ($this->category === 'tanah' || (int) $this->useful_life_months <= 0) {
            return 0.0;
        }

        return round($this->depreciable_base / (int) $this->useful_life_months, 2);
    }

    public function getBookValueAttribute(): float { return (float) $this->acquisition_cost - (float) $this->accumulated_depreciation; }
}
