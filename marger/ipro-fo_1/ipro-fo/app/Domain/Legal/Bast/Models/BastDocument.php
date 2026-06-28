<?php

declare(strict_types=1);

namespace App\Domain\Legal\Bast\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BastDocument extends Model
{
    use HasTenant;

    protected $fillable = ['tenant_id', 'booking_id', 'bast_number', 'handed_over_at', 'checklist', 'file_path', 'status'];
    protected $casts    = ['handed_over_at' => 'date', 'checklist' => 'array'];

    public function booking(): BelongsTo { return $this->belongsTo(\App\Domain\Sales\Booking\Models\Booking::class); }
}
