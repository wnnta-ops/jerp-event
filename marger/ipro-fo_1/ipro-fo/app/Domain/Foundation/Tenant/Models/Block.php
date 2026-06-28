<?php

declare(strict_types=1);

namespace App\Domain\Foundation\Tenant\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Block extends Model
{
    use HasFactory, HasTenant, SoftDeletes;

    protected $fillable = ['tenant_id', 'cluster_id', 'code'];

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }
}
