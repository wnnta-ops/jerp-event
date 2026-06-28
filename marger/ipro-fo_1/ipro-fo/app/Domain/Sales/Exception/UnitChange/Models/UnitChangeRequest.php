<?php

declare(strict_types=1);

namespace App\Domain\Sales\Exception\UnitChange\Models;

use Illuminate\Database\Eloquent\Model;

class UnitChangeRequest extends Model
{
    protected $fillable = ['booking_id', 'old_unit_id', 'new_unit_id', 'price_diff', 'admin_fee', 'approval_id', 'status'];
    protected $casts    = ['price_diff' => 'decimal:2', 'admin_fee' => 'decimal:2'];
}
