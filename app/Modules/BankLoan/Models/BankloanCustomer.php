<?php

namespace App\Modules\BankLoan\Models;

use App\Models\Concerns\TenantAwareModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Customer KPR — di-mirror dari FO (fo_customers) via sync. `code` = natural key
 * (no_customer FO) untuk idempotensi.
 */
class BankloanCustomer extends TenantAwareModel
{
    use SoftDeletes;

    protected $table = 'bankloan_customers';

    protected $fillable = [
        'tenant_id', 'code', 'name', 'nik', 'birth_place', 'birth_date',
        'email', 'phone_number', 'address', 'occupation_id', 'income_id',
    ];

    protected $casts = ['birth_date' => 'date'];

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class);
    }

    public function income(): BelongsTo
    {
        return $this->belongsTo(Income::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'customer_id');
    }
}
