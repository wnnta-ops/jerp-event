<?php

declare(strict_types=1);

namespace App\Domain\Master\Customer\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Master sumber data customer (marketing channel).
 * Contoh: Pameran, Online, Referral, Walk-In, dll.
 *
 * @property int    $id
 * @property int    $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool   $is_active
 * @property int    $display_order
 */
class DataSource extends Model
{
    use HasFactory, HasTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'code', 'name', 'description',
        'is_active', 'display_order',
    ];

    protected $casts = [
        'is_active'     => 'boolean',
        'display_order' => 'integer',
    ];

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('display_order')->orderBy('name');
    }
}
