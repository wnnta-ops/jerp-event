<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Models\User;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Link berkas supplier — dibuat di langkah Detil Tagihan Registrasi Invoice Hutang ("Generate Link").
 * Supplier mengunggah dokumen lewat link tanpa login; hasilnya jadi Upload Berkas milik pembuat
 * invoice dan langsung diajukan ke TTD Dept Head. Nomor invoice dipesan saat link dibuat.
 *
 * @property string $token
 * @property string $invoice_number
 * @property string $status menunggu | terisi | dipakai | batal
 * @property array<string,mixed>|null $data
 */
class BsApBerkasLink extends Model
{
    use HasTenant;

    public const MENUNGGU = 'menunggu';
    public const TERISI = 'terisi';
    public const DIPAKAI = 'dipakai';
    public const BATAL = 'batal';

    protected $table = 'bs_ap_berkas_links';

    protected $fillable = ['tenant_id', 'token', 'user_id', 'supplier_id', 'invoice_number', 'data', 'step', 'berkas_upload_id',
        'status', 'filled_at', 'riwayat', 'seen_at', 'ap_invoice_id'];

    protected $casts = ['data' => 'array', 'riwayat' => 'array', 'step' => 'integer', 'filled_at' => 'datetime', 'seen_at' => 'datetime'];

    protected $hidden = ['token'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(BsSupplier::class, 'supplier_id'); }
    public function berkas(): BelongsTo { return $this->belongsTo(BsApBerkasUpload::class, 'berkas_upload_id'); }

    /** Masih menunggu / sudah terisi tapi invoicenya belum disimpan. */
    public function aktif(): bool
    {
        return in_array($this->status, [self::MENUNGGU, self::TERISI], true);
    }

    public function url(): string
    {
        return route('berkas-supplier.show', $this->token);
    }
}
