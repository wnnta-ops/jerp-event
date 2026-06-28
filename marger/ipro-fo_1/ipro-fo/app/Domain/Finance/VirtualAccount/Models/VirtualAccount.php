<?php

declare(strict_types=1);

namespace App\Domain\Finance\VirtualAccount\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VirtualAccount extends Model
{
    use HasTenant;

    protected $fillable = ['tenant_id', 'customer_id', 'bank_partner_id', 'va_number', 'status'];

    public function customer(): BelongsTo { return $this->belongsTo(\App\Domain\Master\Customer\Models\Customer::class); }
    public function bank(): BelongsTo     { return $this->belongsTo(\App\Domain\BankLoan\Models\BankPartner::class, 'bank_partner_id'); }
    public function transactions(): HasMany { return $this->hasMany(VaTransaction::class); }
}
