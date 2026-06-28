<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BsArInvoiceSchedule extends Model
{
    public $timestamps = false;
    protected $table = 'bs_ar_invoice_schedules';
    protected $fillable = ['ar_invoice_id', 'installment_no', 'due_date', 'amount', 'paid_amount', 'status'];
    protected $casts = ['due_date' => 'date', 'amount' => 'decimal:2', 'paid_amount' => 'decimal:2'];

    public function invoice(): BelongsTo { return $this->belongsTo(BsArInvoice::class, 'ar_invoice_id'); }
}
