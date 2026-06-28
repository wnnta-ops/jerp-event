<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BsArInvoiceLine extends Model
{
    public $timestamps = false;
    protected $table = 'bs_ar_invoice_lines';
    protected $fillable = ['ar_invoice_id', 'description', 'qty', 'unit_price', 'amount'];
    protected $casts = ['qty' => 'decimal:2', 'unit_price' => 'decimal:2', 'amount' => 'decimal:2'];

    public function invoice(): BelongsTo { return $this->belongsTo(BsArInvoice::class, 'ar_invoice_id'); }
}
