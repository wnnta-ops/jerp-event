<?php

declare(strict_types=1);

namespace App\Domain\Legal\Sertifikat\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

class SertifikatDocument extends Model
{
    use HasTenant;

    protected $fillable = ['tenant_id', 'unit_id', 'owner_customer_id', 'type', 'serial_number', 'issued_at', 'file_path'];
    protected $casts    = ['issued_at' => 'date'];
}
