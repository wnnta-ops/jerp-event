<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Models;

use App\Domain\Foundation\Tenant\Models\Project;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Master Kategori Retensi (mis. DAJAM, TANAH MATANG, PONDASI, TOPPING OFF, BAST + AJB).
 */
class RetentionCategory extends Model
{
    use HasTenant, SoftDeletes;

    protected $fillable = ['tenant_id', 'project_id', 'code', 'name', 'percentage', 'is_dajam', 'keterangan', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active'  => 'boolean',
        'is_dajam'   => 'boolean',
        'percentage' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function terms(): HasMany
    {
        return $this->hasMany(BankRetentionTerm::class);
    }
}
