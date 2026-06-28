<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use Illuminate\Database\Eloquent\Model;

class BsApInvoiceSchedule extends Model
{
    public $timestamps = false;
    protected $table = 'bs_ap_invoice_schedules';
    protected $fillable = ['ap_invoice_id', 'installment_no', 'due_date', 'amount', 'paid_amount', 'status'];
    protected $casts = ['due_date' => 'date', 'amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'installment_no' => 'integer'];
}
