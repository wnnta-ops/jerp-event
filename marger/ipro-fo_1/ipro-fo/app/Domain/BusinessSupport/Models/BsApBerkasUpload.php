<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Domain\Approval\Models\Approval;
use App\Models\User;
use App\Support\Traits\HasAuditTrail;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Upload Berkas — berkas tagihan yang diunggah staf, ditandatangani Dept Head, lalu
 * dijadikan Invoice Hutang (boleh beberapa) oleh tim AP. Siklus lengkap: lihat migrasi
 * 2026_10_31_100000. Perubahan isian tercatat otomatis di audit_logs (HasAuditTrail).
 *
 * @property string $status lihat STATUS_LABELS
 */
class BsApBerkasUpload extends Model
{
    use HasAuditTrail, HasTenant, SoftDeletes;

    /** document_type approval TTD Dept Head. */
    public const DOCUMENT_TYPE = 'ap_berkas';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_MENUNGGU_TTD = 'menunggu_ttd';

    public const STATUS_SIAP_INVOICE = 'siap_invoice';

    public const STATUS_SEBAGIAN = 'sebagian_diinvoice';

    public const STATUS_DIINVOICE = 'diinvoice';

    public const STATUS_DITOLAK = 'ditolak';

    public const STATUS_DIBATALKAN = 'dibatalkan';

    public const STATUS_DITUTUP = 'ditutup';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_MENUNGGU_TTD => 'Menunggu TTD',
        self::STATUS_SIAP_INVOICE => 'Siap Invoice',
        self::STATUS_SEBAGIAN => 'Sebagian Diinvoice',
        self::STATUS_DIINVOICE => 'Sudah Diinvoice',
        self::STATUS_DITOLAK => 'Ditolak',
        self::STATUS_DIBATALKAN => 'Dibatalkan',
        self::STATUS_DITUTUP => 'Ditutup',
    ];

    /**
     * Status berkas yang boleh dipilih di langkah Detil Tagihan Registrasi Invoice Hutang —
     * semua yang masih aktif. Invoicenya sendiri baru bisa DISIMPAN bila bisaDiinvoice().
     */
    public const STATUS_PILIHAN_INVOICE = [
        self::STATUS_DRAFT, self::STATUS_DITOLAK, self::STATUS_MENUNGGU_TTD,
        self::STATUS_SIAP_INVOICE, self::STATUS_SEBAGIAN,
    ];

    /** Jenis transaksi — sama dengan pembagian jenis Purchase Order. */
    public const JENIS_LABELS = [
        'material' => 'Material',
        'alat_kerja' => 'Alat Kerja',
        'biaya_proyek' => 'Biaya Proyek',
        'biaya_non_proyek' => 'Biaya Non-Proyek',
    ];

    protected $table = 'bs_ap_berkas_uploads';

    protected $fillable = [
        'tenant_id', 'berkas_number', 'upload_date', 'jenis', 'document_date', 'needed_date',
        'supplier_id', 'supplier_invoice_no', 'subtotal', 'discount', 'ppn_pct', 'tax_inclusive', 'ppn_amount',
        'amount', 'cost_center_id', 'expense_account_id', 'description', 'dept_head_id', 'status',
        'approval_id', 'ap_invoice_id', 'uploaded_by', 'submitted_at',
        'cancelled_at', 'cancelled_by', 'cancel_reason', 'closed_at', 'closed_by', 'close_reason',
    ];

    protected $casts = [
        'upload_date' => 'date',
        'document_date' => 'date',
        'needed_date' => 'date',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'ppn_pct' => 'decimal:2',
        'tax_inclusive' => 'boolean',
        'ppn_amount' => 'decimal:2',
        'amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /** Isian & file hanya boleh diubah sebelum diajukan atau setelah ditolak (revisi). */
    public function bisaDiubah(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_DITOLAK], true) && ! $this->trashed();
    }

    /** Boleh dijadikan (satu lagi) invoice. */
    public function bisaDiinvoice(): bool
    {
        return in_array($this->status, [self::STATUS_SIAP_INVOICE, self::STATUS_SEBAGIAN], true) && ! $this->trashed();
    }

    /** Nilai invoice yang sudah dibuat dari berkas ini (invoice batal tidak dihitung). */
    public function invoicedTotal(): float
    {
        return (float) $this->invoices()->where('status', '!=', BsApInvoice::STATUS_BATAL)->sum('total');
    }

    /** Sisa yang belum diinvoice; null bila berkas tanpa nominal. */
    public function sisa(): ?float
    {
        return $this->amount !== null ? max(0.0, round((float) $this->amount - $this->invoicedTotal(), 2)) : null;
    }

    public function files(): HasMany
    {
        return $this->hasMany(BsApBerkasUploadFile::class, 'berkas_upload_id');
    }

    /** Semua invoice hutang yang dibuat dari berkas ini. */
    public function invoices(): HasMany
    {
        return $this->hasMany(BsApInvoice::class, 'source_id')->where('source_type', 'berkas');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(BsSupplier::class, 'supplier_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(BsCostCenter::class, 'cost_center_id');
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(BsAccount::class, 'expense_account_id');
    }

    public function deptHead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dept_head_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BsApInvoice::class, 'ap_invoice_id');
    }
}
