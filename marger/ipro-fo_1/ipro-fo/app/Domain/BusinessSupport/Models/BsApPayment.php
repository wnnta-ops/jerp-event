<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BsApPayment extends Model
{
    protected $table = 'bs_ap_payments';
    protected $fillable = ['ap_invoice_id', 'payment_date', 'cash_account_id', 'amount', 'reference', 'journal_id', 'created_by'];
    protected $casts = ['payment_date' => 'date', 'amount' => 'decimal:2'];

    public function invoice(): BelongsTo { return $this->belongsTo(BsApInvoice::class, 'ap_invoice_id'); }

    /**
     * Akun lawan pembayaran. HATI-HATI: tidak selalu kas/bank — untuk pelunasan lewat penyerahan
     * BG/Cek isinya akun "BG-Cek Beredar" (kewajiban); kas baru keluar saat warkat dicairkan.
     */
    public function cashAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'cash_account_id'); }
}
