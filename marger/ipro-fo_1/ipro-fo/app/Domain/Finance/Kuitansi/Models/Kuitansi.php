<?php

declare(strict_types=1);

namespace App\Domain\Finance\Kuitansi\Models;

use App\Support\Traits\HasAuditTrail;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Kuitansi extends Model
{
    use HasAuditTrail, HasFactory, HasTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'kuitansi_number', 'tts_receipt_id', 'customer_id',
        'issued_date', 'amount', 'terbilang', 'signed_by', 'status',
    ];

    protected $casts = [
        'issued_date' => 'date',
        'amount'      => 'decimal:2',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(KuitansiItem::class);
    }

    public function ttsReceipt(): BelongsTo
    {
        // Model Tts memetakan ke tabel tts_receipts; FK = tts_receipt_id.
        return $this->belongsTo(\App\Domain\Finance\Tts\Models\Tts::class, 'tts_receipt_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Master\Customer\Models\Customer::class);
    }
}
