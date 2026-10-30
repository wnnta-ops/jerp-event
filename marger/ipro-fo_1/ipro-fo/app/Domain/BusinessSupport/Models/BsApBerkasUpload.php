<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Domain\Approval\Models\Approval;
use App\Models\User;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Upload Berkas — berkas tagihan yang diunggah staf, ditandatangani Dept Head, lalu
 * dijadikan Invoice Hutang oleh tim AP. Lihat migrasi 2026_10_30_120000 untuk alurnya.
 *
 * @property string $status menunggu_ttd | siap_invoice | diinvoice | ditolak
 */
class BsApBerkasUpload extends Model
{
    use HasTenant, SoftDeletes;

    /** document_type approval TTD Dept Head. */
    public const DOCUMENT_TYPE = 'ap_berkas';

    public const STATUS_MENUNGGU_TTD = 'menunggu_ttd';

    public const STATUS_SIAP_INVOICE = 'siap_invoice';

    public const STATUS_DIINVOICE = 'diinvoice';

    public const STATUS_DITOLAK = 'ditolak';

    protected $table = 'bs_ap_berkas_uploads';

    protected $fillable = [
        'tenant_id', 'berkas_number', 'upload_date', 'supplier_id', 'supplier_invoice_no',
        'amount', 'description', 'dept_head_id', 'status', 'approval_id', 'ap_invoice_id', 'uploaded_by',
    ];

    protected $casts = [
        'upload_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function files(): HasMany
    {
        return $this->hasMany(BsApBerkasUploadFile::class, 'berkas_upload_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(BsSupplier::class, 'supplier_id');
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
