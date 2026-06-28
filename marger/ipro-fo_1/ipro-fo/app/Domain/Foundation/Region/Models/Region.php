<?php

declare(strict_types=1);

namespace App\Domain\Foundation\Region\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Wilayah administratif (level 1..4).
 */
class Region extends Model
{
    public $timestamps = false;

    protected $fillable = ['code', 'parent_code', 'level', 'name'];

    protected $casts = ['level' => 'integer'];
}
