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

    /**
     * Supplier masih boleh mengunggah lewat link: link aktif DAN berkasnya belum selesai di-TTD
     * sampai tahap terakhir chain TTD Berkas. Sesudah itu link nonaktif bagi supplier — pembuat
     * invoice masih bisa menambah file dari wizard (ApBerkasService::tambahFileSesudahTtd).
     */
    public function menerimaUnggahan(): bool
    {
        return $this->aktif() && ! $this->ttdSelesai();
    }

    /** Berkas link sudah disetujui penuh di chain TTD Berkas. */
    public function ttdSelesai(): bool
    {
        if (! $this->berkas_upload_id) {
            return false;
        }
        $status = $this->relationLoaded('berkas') ? $this->berkas?->status
            : BsApBerkasUpload::query()->whereKey($this->berkas_upload_id)->value('status');

        return in_array($status, [BsApBerkasUpload::STATUS_SIAP_INVOICE, BsApBerkasUpload::STATUS_SEBAGIAN,
            BsApBerkasUpload::STATUS_DIINVOICE, BsApBerkasUpload::STATUS_DITUTUP], true);
    }

    /**
     * Riwayat unggahan (lama → baru). Link yang terisi sebelum riwayat dicatat (kolom riwayat
     * kosong) tetap menampilkan unggahan terakhirnya dari filled_at.
     *
     * @return list<array<string,mixed>>
     */
    public function riwayatUnggahan(): array
    {
        $riwayat = $this->riwayat ?? [];
        if ($riwayat === [] && $this->filled_at && $this->berkas_upload_id) {
            $riwayat[] = ['at' => $this->filled_at->toIso8601String(), 'berkas_id' => $this->berkas_upload_id,
                'berkas_number' => BsApBerkasUpload::query()->whereKey($this->berkas_upload_id)->value('berkas_number'),
                'no_invoice' => $this->data['supplier_invoice_no'] ?? null,
                'file' => BsApBerkasUploadFile::query()->where('berkas_upload_id', $this->berkas_upload_id)->count(),
                'catatan' => null];
        }

        return array_values($riwayat);
    }

    public function url(): string
    {
        return route('berkas-supplier.show', $this->token);
    }
}
