<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * #98 — Baris SIP: 1 tagihan AP / bon sementara yang akan dibayar (snapshot tujuan & nominal).
 */
class BsSipLine extends Model
{
    public $timestamps = false;

    protected $table = 'bs_sip_lines';
    protected $fillable = [
        'sip_id', 'cash_account_id', 'sourceable_type', 'sourceable_id', 'payee_type', 'payee_name',
        'dest_bank_name', 'dest_account_no', 'dest_account_name', 'description', 'amount',
    ];
    protected $casts = ['amount' => 'decimal:2'];

    public function sip(): BelongsTo { return $this->belongsTo(BsSip::class, 'sip_id'); }

    public function cashAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'cash_account_id'); }

    public function sourceable(): MorphTo { return $this->morphTo(); }
}
