<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permintaan pada sebuah kartu kredit/VA — penambahan saldo, pengurangan saldo, atau penetapan
 * masa berlaku. Semuanya menunggu persetujuan sebelum berpengaruh pada kartunya.
 */
class BsCreditCardRequest extends Model
{
    use HasTenant;

    protected $table = 'bs_credit_card_requests';

    protected $fillable = [
        'tenant_id', 'credit_card_id', 'type', 'amount', 'expired_date', 'status',
        'source_type', 'source_id', 'reference', 'note', 'requested_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'expired_date' => 'date:Y-m-d',
        'approved_at'  => 'datetime',
    ];

    public const TYPES = [
        'topup'  => 'Penambahan Saldo',
        'reduce' => 'Pengurangan Saldo',
        'expiry' => 'Masa Berlaku (Expired)',
    ];

    public const STATUSES = [
        'pending'  => 'Menunggu Persetujuan',
        'approved' => 'Disetujui',
        'rejected' => 'Ditolak',
    ];

    public function card(): BelongsTo { return $this->belongsTo(BsCreditCard::class, 'credit_card_id'); }
}
