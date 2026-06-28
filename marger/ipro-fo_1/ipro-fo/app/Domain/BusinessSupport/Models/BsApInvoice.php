<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BsApInvoice extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_ap_invoices';
    protected $fillable = [
        'tenant_id', 'invoice_number', 'invoice_date', 'receive_date', 'due_date', 'term_days', 'supplier_id',
        'unit_id', 'customer_id',
        'category_id', 'supplier_invoice_no', 'tax_invoice_no', 'manual_no', 'trx_type', 'source_type', 'source_id',
        'expense_account_id', 'payable_account_id', 'description', 'berkas_notes', 'transfer_detail', 'kurs',
        'dpp', 'ppn_pct', 'ppn_amount', 'ppn_base', 'ppn_mode', 'pph_mode', 'pph_type', 'pph_pct', 'pph_base', 'pph_amount', 'pph_account_id',
        'total', 'net_payable', 'paid_amount', 'status', 'journal_id', 'created_by',
    ];
    protected $casts = [
        'invoice_date' => 'date', 'receive_date' => 'date', 'due_date' => 'date', 'term_days' => 'integer',
        'kurs' => 'decimal:4',
        'dpp' => 'decimal:2', 'ppn_pct' => 'decimal:2', 'ppn_amount' => 'decimal:2', 'ppn_base' => 'decimal:2',
        'pph_pct' => 'decimal:2', 'pph_base' => 'decimal:2', 'pph_amount' => 'decimal:2',
        'total' => 'decimal:2', 'net_payable' => 'decimal:2', 'paid_amount' => 'decimal:2',
    ];

    public const TRX_TYPES = ['normal' => 'Normal', 'denda' => 'Realisasi Denda', 'saldo_awal' => 'Saldo Awal'];

    public function supplier(): BelongsTo { return $this->belongsTo(BsSupplier::class, 'supplier_id'); }
    public function category(): BelongsTo { return $this->belongsTo(BsApCategory::class, 'category_id'); }
    public function unit(): BelongsTo { return $this->belongsTo(\App\Domain\Master\Unit\Models\Unit::class, 'unit_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(\App\Domain\Master\Customer\Models\Customer::class, 'customer_id'); }
    public function payments(): HasMany { return $this->hasMany(BsApPayment::class, 'ap_invoice_id'); }
    public function lines(): HasMany { return $this->hasMany(BsApInvoiceLine::class, 'ap_invoice_id'); }
    public function schedules(): HasMany { return $this->hasMany(BsApInvoiceSchedule::class, 'ap_invoice_id'); }
    public function invoiceBerkas(): HasMany { return $this->hasMany(BsApInvoiceBerkas::class, 'ap_invoice_id'); }

    /** Yang dibayar ke supplier = net_payable (total − PPh dipungut); fallback ke total untuk data lama. */
    public function getPayableAmountAttribute(): float
    {
        $net = (float) $this->net_payable;

        return $net > 0 ? $net : (float) $this->total;
    }

    public function getOutstandingAttribute(): float { return $this->payable_amount - (float) $this->paid_amount; }
}
