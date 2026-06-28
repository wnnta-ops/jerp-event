<?php

declare(strict_types=1);

namespace App\Domain\Legal\Imb\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

class ImbDocument extends Model
{
    use HasTenant;

    protected $fillable = ['tenant_id', 'unit_id', 'imb_number', 'issued_at', 'file_path'];
    protected $casts    = ['issued_at' => 'date'];
}
