<?php

declare(strict_types=1);

namespace App\Domain\Master\Sales\Models;

use App\Models\User;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Master Sales / Marketing (eProperty: Setup Sales → Master Sales).
 */
class SalesPerson extends Model
{
    use HasFactory, HasTenant, SoftDeletes;

    protected $table = 'sales_persons';

    protected $fillable = [
        'tenant_id', 'user_id', 'code', 'name', 'type', 'level', 'phone', 'email',
        'manager_id', 'joined_at', 'is_active',
    ];

    protected $casts = [
        'joined_at' => 'date',
        'is_active' => 'boolean',
    ];

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
