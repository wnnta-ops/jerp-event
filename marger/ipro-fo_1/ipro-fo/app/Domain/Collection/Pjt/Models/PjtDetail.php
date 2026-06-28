<?php

declare(strict_types=1);

namespace App\Domain\Collection\Pjt\Models;

use App\Domain\Sales\Booking\Models\PaymentSchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PjtDetail extends Model
{
    protected $fillable = ['pjt_header_id', 'payment_schedule_id', 'amount'];
    protected $casts    = ['amount' => 'decimal:2'];

    /** #120: jadwal angsuran/DP yang jatuh tempo — sumber unit, keterangan & tanggal jatuh tempo. */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PaymentSchedule::class, 'payment_schedule_id');
    }
}
