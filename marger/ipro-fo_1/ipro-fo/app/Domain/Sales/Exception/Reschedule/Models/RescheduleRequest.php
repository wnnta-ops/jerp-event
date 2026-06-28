<?php

declare(strict_types=1);

namespace App\Domain\Sales\Exception\Reschedule\Models;

use Illuminate\Database\Eloquent\Model;

class RescheduleRequest extends Model
{
    protected $fillable = ['booking_id', 'proposed_schedule', 'admin_fee', 'approval_id', 'status'];
    protected $casts    = ['proposed_schedule' => 'array', 'admin_fee' => 'decimal:2'];
}
