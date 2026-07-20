<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Sales\Booking\Models\Booking;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BsArInvoice extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_ar_invoices';
    protected $fillable = [
        'tenant_id', 'invoice_number', 'invoice_date', 'receive_date', 'due_date', 'term_days', 'customer_id',
        'booking_id', 'booked_revenue_id',
        'category_id', 'customer_ref_no', 'tax_invoice_no', 'manual_no', 'trx_type',
        'income_account_id', 'receivable_account_id', 'description', 'transfer_detail', 'kurs',
        'dpp', 'ppn_pct', 'ppn_amount', 'ppn_mode', 'pph_mode', 'pph_pct', 'pph_amount', 'pph_account_id',
        'total', 'net_receivable', 'paid_amount', 'status', 'journal_id', 'created_by',
    ];
    protected $casts = [
        'invoice_date' => 'date', 'receive_date' => 'date', 'due_date' => 'date', 'term_days' => 'integer',
        'kurs' => 'decimal:4',
        'dpp' => 'decimal:2', 'ppn_pct' => 'decimal:2', 'ppn_amount' => 'decimal:2',
        'pph_pct' => 'decimal:2', 'pph_amount' => 'decimal:2',
        'total' => 'decimal:2', 'net_receivable' => 'decimal:2', 'paid_amount' => 'decimal:2',
    ];

    public const TRX_TYPES = ['normal' => 'Normal', 'denda' => 'Realisasi Denda', 'saldo_awal' => 'Saldo Awal'];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class, 'customer_id'); }
    /** SPR yang ditagih invoice ini (opsional — invoice denda/saldo awal tidak punya SPR). */
    public function booking(): BelongsTo { return $this->belongsTo(Booking::class, 'booking_id'); }
    /** Booked Revenue asal invoice ini (invoice kini selalu lahir dari booking; NULL utk data lama). */
    public function bookedRevenue(): BelongsTo { return $this->belongsTo(BsBookedRevenue::class, 'booked_revenue_id'); }
    public function category(): BelongsTo { return $this->belongsTo(BsArCategory::class, 'category_id'); }
    public function payments(): HasMany { return $this->hasMany(BsArPayment::class, 'ar_invoice_id'); }
    public function lines(): HasMany { return $this->hasMany(BsArInvoiceLine::class, 'ar_invoice_id'); }
    public function schedules(): HasMany { return $this->hasMany(BsArInvoiceSchedule::class, 'ar_invoice_id'); }

    /** Yang ditagih ke customer = net_receivable (total − PPh dipungut pelanggan); fallback ke total untuk data lama. */
    public function getReceivableAmountAttribute(): float
    {
        $net = (float) $this->net_receivable;

        return $net > 0 ? $net : (float) $this->total;
    }

    public function getOutstandingAttribute(): float { return $this->receivable_amount - (float) $this->paid_amount; }
}
