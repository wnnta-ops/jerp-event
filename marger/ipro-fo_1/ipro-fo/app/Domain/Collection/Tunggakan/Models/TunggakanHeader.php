<?php

declare(strict_types=1);

namespace App\Domain\Collection\Tunggakan\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TunggakanHeader extends Model
{
    use HasTenant;

    protected $fillable = [
        'tenant_id', 'customer_id', 'transaction_type', 'category',
        'term_overdue_count', 'total_amount', 'status', 'first_overdue_at',
    ];

    protected $casts = [
        'first_overdue_at' => 'date',
        'total_amount'     => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Master\Customer\Models\Customer::class);
    }
}
