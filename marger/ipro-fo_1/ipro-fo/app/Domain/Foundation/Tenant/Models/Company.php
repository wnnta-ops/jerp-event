<?php

declare(strict_types=1);

namespace App\Domain\Foundation\Tenant\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, HasTenant, SoftDeletes;

    protected $fillable = ['tenant_id', 'code', 'name', 'npwp', 'phone', 'email', 'address'];

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
