<?php

declare(strict_types=1);

namespace App\Domain\Sales\Nup\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Launching extends Model
{
    use HasTenant, SoftDeletes;

    protected $fillable = ['tenant_id', 'project_id', 'name', 'launching_at', 'rules', 'status'];

    protected $casts = [
        'launching_at' => 'datetime',
        'rules'        => 'array',
    ];
}
