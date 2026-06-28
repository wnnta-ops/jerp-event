<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BsArKuitansiLine extends Model
{
    public $timestamps = false;
    protected $table = 'bs_ar_kuitansi_lines';
    protected $fillable = ['kuitansi_id', 'ar_invoice_id', 'amount', 'payment_id'];
    protected $casts = ['amount' => 'decimal:2'];

    public function invoice(): BelongsTo { return $this->belongsTo(BsArInvoice::class, 'ar_invoice_id'); }
}
