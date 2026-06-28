<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BsApKuitansiLine extends Model
{
    public $timestamps = false;
    protected $table = 'bs_ap_kuitansi_lines';
    protected $fillable = ['kuitansi_id', 'ap_invoice_id', 'amount', 'payment_id'];
    protected $casts = ['amount' => 'decimal:2'];

    public function invoice(): BelongsTo { return $this->belongsTo(BsApInvoice::class, 'ap_invoice_id'); }
}
