<?php

declare(strict_types=1);

namespace App\Domain\Finance\Tts\Models;

use Illuminate\Database\Eloquent\Model;

class TtsGiro extends Model
{
    protected $table = 'tts_giros';

    protected $fillable = [
        'tts_receipt_id', 'giro_number', 'issuing_bank', 'giro_date', 'due_date',
        'status', 'cleared_at', 'rejected_at', 'reject_reason', 'replaced_by',
    ];

    protected $casts = [
        'giro_date'   => 'date',
        'due_date'    => 'date',
        'cleared_at'  => 'date',
        'rejected_at' => 'date',
    ];
}
