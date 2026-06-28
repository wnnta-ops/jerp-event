<?php

declare(strict_types=1);

namespace App\Domain\Foundation\Tenant\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['code', 'name', 'domain', 'settings', 'activated_at'];

    protected $casts = [
        'settings'     => 'array',
        'activated_at' => 'datetime',
    ];
}
