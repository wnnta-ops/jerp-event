<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BsArPayment extends Model
{
    protected $table = 'bs_ar_payments';
    protected $fillable = ['ar_invoice_id', 'payment_date', 'cash_account_id', 'amount', 'reference', 'journal_id', 'created_by'];
    protected $casts = ['payment_date' => 'date', 'amount' => 'decimal:2'];

    public function invoice(): BelongsTo { return $this->belongsTo(BsArInvoice::class, 'ar_invoice_id'); }
}
