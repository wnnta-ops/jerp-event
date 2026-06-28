<?php

declare(strict_types=1);

namespace App\Domain\Sales\Exception\NameChange\Models;

use Illuminate\Database\Eloquent\Model;

class NameChangeRequest extends Model
{
    protected $fillable = ['booking_id', 'old_customer_id', 'new_customer_id', 'requested_at', 'admin_fee', 'approval_id', 'status'];
    protected $casts    = ['requested_at' => 'date', 'admin_fee' => 'decimal:2'];
}
