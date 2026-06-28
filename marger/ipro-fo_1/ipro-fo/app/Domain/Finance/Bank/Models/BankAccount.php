<?php

declare(strict_types=1);

namespace App\Domain\Finance\Bank\Models;

use App\Domain\Foundation\Tenant\Models\Company;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Master Rekening Bank perusahaan (eProperty: Finance & AR → Setup Rekening).
 * Termasuk penanda kolam Virtual Account (is_virtual_pool).
 */
class BankAccount extends Model
{
    use HasTenant;

    protected $table = 'bank_accounts';

    protected $fillable = [
        'tenant_id', 'company_id', 'account_name', 'account_number', 'bank_name', 'branch',
        'is_virtual_pool', 'is_active',
    ];

    protected $casts = [
        'is_virtual_pool' => 'boolean',
        'is_active'       => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
