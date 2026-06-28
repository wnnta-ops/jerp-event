<?php

declare(strict_types=1);

namespace App\Domain\Sales\Booking\Models;

use Illuminate\Database\Eloquent\Model;

class BookingStatusHistory extends Model
{
    public $timestamps = false;

    protected $fillable = ['booking_id', 'from_status', 'to_status', 'changed_by', 'notes', 'changed_at'];

    protected $casts = ['changed_at' => 'datetime'];
}
