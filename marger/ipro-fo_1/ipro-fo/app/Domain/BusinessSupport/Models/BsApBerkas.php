<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

class BsApBerkas extends Model
{
    use HasTenant;

    protected $table = 'bs_ap_berkas';
    protected $fillable = ['tenant_id', 'code', 'name', 'is_mandatory', 'is_active'];
    protected $casts = ['is_mandatory' => 'boolean', 'is_active' => 'boolean'];
}
