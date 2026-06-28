<?php

declare(strict_types=1);

namespace App\Domain\Collection\Followup\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Followup extends Model
{
    use HasFactory, HasTenant;

    protected $fillable = [
        'tenant_id', 'customer_id', 'sales_person_id', 'channel',
        'contacted_at', 'result', 'notes', 'next_action_at', 'status',
    ];

    protected $casts = [
        'contacted_at'   => 'datetime',
        'next_action_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Master\Customer\Models\Customer::class);
    }
}
