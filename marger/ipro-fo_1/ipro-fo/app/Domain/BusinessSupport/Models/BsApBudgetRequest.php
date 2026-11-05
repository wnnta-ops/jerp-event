<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Models\User;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pengajuan Budget Invoice — dibuat di langkah Struktur Registrasi Invoice Hutang saat baris jurnal
 * melewati sisa budget dan user memilih Alokasi / Koreksi. Invoice tertahan sebagai draft (data
 * wizard di sini, nomor invoice dipesan) sampai approval-nya (chain Invoice Hutang) disetujui penuh —
 * alokasi & koreksinya ikut diputuskan bersama. Lihat PengajuanBudgetInvoice & BudgetIkutInvoice.
 *
 * @property string $status menunggu | disetujui | ditolak | dipakai | batal
 * @property array<string,mixed> $data
 */
class BsApBudgetRequest extends Model
{
    use HasTenant;

    public const MENUNGGU = 'menunggu';
    public const DISETUJUI = 'disetujui';
    public const DITOLAK = 'ditolak';
    public const DIPAKAI = 'dipakai';
    public const BATAL = 'batal';

    /** source_type alokasi & koreksi yang lahir dari pengajuan ini. */
    public const SOURCE = 'ap_budget_request';

    protected $table = 'bs_ap_budget_requests';

    /** document_type approval pengajuan (chain-nya mengikuti Invoice Hutang). */
    public const DOCUMENT_TYPE = 'ap_budget_request';

    protected $fillable = ['tenant_id', 'user_id', 'jenis', 'invoice_id', 'link_id', 'invoice_number', 'data', 'status', 'approval_id', 'catatan',
        'ap_invoice_id', 'diajukan_at', 'decided_at', 'seen_at'];

    protected $casts = ['data' => 'array', 'diajukan_at' => 'datetime', 'decided_at' => 'datetime', 'seen_at' => 'datetime'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function approval(): BelongsTo { return $this->belongsTo(\App\Domain\Approval\Models\Approval::class); }

    public function alokasi(): HasMany
    {
        return $this->hasMany(BsBudgetAllocation::class, 'source_id')->where('source_type', self::SOURCE);
    }

    public function koreksi(): HasMany
    {
        return $this->hasMany(BsBudgetCorrection::class, 'source_id')->where('source_type', self::SOURCE);
    }

    /** Masih bisa jadi invoice / diajukan ulang (belum dipakai atau dibatalkan). */
    public function terbuka(): bool
    {
        return in_array($this->status, [self::MENUNGGU, self::DISETUJUI, self::DITOLAK], true);
    }
}
