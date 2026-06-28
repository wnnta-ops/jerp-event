<?php

declare(strict_types=1);

namespace App\Domain\Commission\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommissionScheme extends Model
{
    use HasFactory, HasTenant, SoftDeletes;

    protected $fillable = ['tenant_id', 'project_id', 'code', 'name', 'is_active'];
    protected $casts    = ['is_active' => 'boolean'];

    public function terms(): HasMany { return $this->hasMany(CommissionSchemeTerm::class); }
}
