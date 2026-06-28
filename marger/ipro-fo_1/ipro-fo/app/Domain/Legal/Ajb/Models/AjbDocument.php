<?php

declare(strict_types=1);

namespace App\Domain\Legal\Ajb\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AjbDocument extends Model
{
    use HasTenant;

    protected $fillable = ['tenant_id', 'booking_id', 'ajb_number', 'signed_at', 'notary_name', 'file_path', 'status'];
    protected $casts    = ['signed_at' => 'date'];

    public function booking(): BelongsTo { return $this->belongsTo(\App\Domain\Sales\Booking\Models\Booking::class); }
}
