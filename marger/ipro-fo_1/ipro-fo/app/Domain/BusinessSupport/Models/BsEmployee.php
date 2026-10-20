<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(BsCostCenter::class, 'cost_center_id');
    }

    public function getGrossAttribute(): float
    {
        return (float) $this->base_salary + (float) $this->allowance;
    }
}
