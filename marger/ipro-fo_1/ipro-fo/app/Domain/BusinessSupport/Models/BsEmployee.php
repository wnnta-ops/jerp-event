<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BsEmployee extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_employees';

    protected $fillable = [
        'tenant_id', 'nik', 'name', 'position', 'cost_center_id', 'join_date',
        'base_salary', 'allowance', 'bank_name', 'bank_account', 'npwp', 'is_active', 'created_by',
        'atasan_id', // atasan langsung — rekeningnya bisa dipilih pada Pengajuan Bon Sementara
    ];

    protected $casts = [
        'join_date' => 'date', 'base_salary' => 'decimal:2', 'allowance' => 'decimal:2', 'is_active' => 'boolean',
    ];

    /** Departemen UTAMA. */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(BsCostCenter::class, 'cost_center_id');
    }

    /** Departemen TAMBAHAN (di luar yang utama) — lihat migrasi bs_employee_cost_centers. */
    public function extraCostCenters(): BelongsToMany
    {
        return $this->belongsToMany(BsCostCenter::class, 'bs_employee_cost_centers', 'employee_id', 'cost_center_id')
            ->withTimestamps();
    }

    /**
     * Semua departemen karyawan ini, UTAMA lebih dulu.
     *
     * @return list<int>
     */
    public function costCenterIds(): array
    {
        $ids = $this->cost_center_id !== null ? [(int) $this->cost_center_id] : [];
        foreach ($this->extraCostCenters()->orderBy('bs_employee_cost_centers.id')->pluck('bs_cost_centers.id') as $id) {
            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }

    public function getGrossAttribute(): float
    {
        return (float) $this->base_salary + (float) $this->allowance;
    }
}
