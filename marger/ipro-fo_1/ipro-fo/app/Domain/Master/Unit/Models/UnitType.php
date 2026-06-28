<?php

declare(strict_types=1);

namespace App\Domain\Master\Unit\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UnitType extends Model
{
    use HasFactory, HasTenant, SoftDeletes;

    protected $fillable = ['tenant_id', 'project_id', 'code', 'name', 'lt', 'lb', 'bedrooms', 'bathrooms'];
}
