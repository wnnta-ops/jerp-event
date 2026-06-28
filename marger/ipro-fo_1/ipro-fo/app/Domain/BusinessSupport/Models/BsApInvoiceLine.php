<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use Illuminate\Database\Eloquent\Model;

class BsApInvoiceLine extends Model
{
    public $timestamps = false;
    protected $table = 'bs_ap_invoice_lines';
    protected $fillable = ['ap_invoice_id', 'description', 'qty', 'unit_price', 'amount'];
    protected $casts = ['qty' => 'decimal:2', 'unit_price' => 'decimal:2', 'amount' => 'decimal:2'];
}
