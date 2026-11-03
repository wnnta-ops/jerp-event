<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BsApKuitansiLine extends Model
{
    public $timestamps = false;
    protected $table = 'bs_ap_kuitansi_lines';
    // um_amount = bagian `amount` yang ditutup uang muka Memo Urgent (kuitansi memo um_kuitansi_id), bukan kas.
    protected $fillable = ['kuitansi_id', 'ap_invoice_id', 'amount', 'um_amount', 'um_kuitansi_id', 'payment_id'];
    protected $casts = ['amount' => 'decimal:2', 'um_amount' => 'decimal:2'];

    public function invoice(): BelongsTo { return $this->belongsTo(BsApInvoice::class, 'ap_invoice_id'); }
}
