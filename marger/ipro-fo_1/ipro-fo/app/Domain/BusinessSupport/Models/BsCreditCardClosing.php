<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot bulanan sebuah kartu debit/VA yang sudah di-closing.
 *
 * `sisa_saldo` di sini adalah SALDO AWAL bulan berikutnya. Selama bulan sebelumnya belum punya
 * baris di tabel ini, saldo awal bulan berjalan adalah nol — lihat CreditCardClosingService.
 */
class BsCreditCardClosing extends Model
{
    use HasTenant;

    protected $table = 'bs_credit_card_closings';

    protected $fillable = [
        'tenant_id', 'credit_card_id', 'period_year', 'period_month',
        'saldo_awal', 'penambahan', 'pemakaian', 'sisa_saldo', 'note', 'closed_by', 'closed_at',
    ];

    protected $casts = [
        'period_year'  => 'integer',
        'period_month' => 'integer',
        'saldo_awal'   => 'decimal:2',
        'penambahan'   => 'decimal:2',
        'pemakaian'    => 'decimal:2',
        'sisa_saldo'   => 'decimal:2',
        'closed_at'    => 'datetime',
    ];

    public function card(): BelongsTo { return $this->belongsTo(BsCreditCard::class, 'credit_card_id'); }
}
