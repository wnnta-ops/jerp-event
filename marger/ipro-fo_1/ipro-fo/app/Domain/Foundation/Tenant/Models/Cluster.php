<?php

declare(strict_types=1);

namespace App\Domain\Foundation\Tenant\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cluster extends Model
{
    use HasFactory, HasTenant, SoftDeletes;

    protected $fillable = ['tenant_id', 'project_id', 'code', 'name'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class);
    }
}
