<?php

declare(strict_types=1);

namespace App\Domain\Finance\Tts\Models;

use Illuminate\Database\Eloquent\Model;

class TtsAllocation extends Model
{
    protected $table = 'tts_allocations';

    protected $fillable = ['tts_receipt_id', 'payment_schedule_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];
}
