<?php

declare(strict_types=1);

namespace App\Domain\Legal\Ajb\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AjbDocument extends Model
{
    use HasTenant;

    /** Akta diketik di Back Office sendiri ("Input Massal AJB"). */
    public const SUMBER_BO = 'bo';

    /** Akta diarsipkan di modul Legal lalu ditautkan ke No. SPR (Final). */
    public const SUMBER_LEGAL = 'legal';

    protected $fillable = [
        'tenant_id', 'booking_id', 'ajb_number', 'signed_at', 'amount', 'notary_name',
        'file_path', 'status', 'source', 'legal_doc_id', 'legal_synced_at',
    ];

    protected $casts = [
        'signed_at'       => 'date',
        'amount'          => 'decimal:2',
        'legal_synced_at' => 'datetime',
    ];

    public function booking(): BelongsTo { return $this->belongsTo(\App\Domain\Sales\Booking\Models\Booking::class); }

    /** Akta ini berasal dari direktori modul Legal (ada berkas aslinya di sana). */
    public function dariLegal(): bool
    {
        return $this->source === self::SUMBER_LEGAL;
    }
}
