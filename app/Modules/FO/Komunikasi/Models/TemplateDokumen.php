<?php

namespace App\Modules\FO\Komunikasi\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\User;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Modules\FO\MarketingSales\Models\Tahap;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Teks dokumen SPR/SPRS ber-VERSI. Versi yang sudah berlaku tidak diubah lagi — perubahan
 * dibuat sebagai versi baru dengan tanggal berlaku, sehingga dokumen lama tetap tercetak
 * dengan teks yang ditandatangani customer waktu itu.
 */
class TemplateDokumen extends TenantAwareModel
{
    protected $table = 'fo_template_dokumen';

    protected $fillable = [
        'tenant_id', 'dokumen', 'lokasi_id', 'tahap_id', 'versi', 'berlaku_mulai', 'isi', 'catatan', 'created_by',
    ];

    protected $casts = [
        'berlaku_mulai' => 'date',
        'isi' => 'array',
        'versi' => 'integer',
    ];

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class, 'lokasi_id');
    }

    public function tahap(): BelongsTo
    {
        return $this->belongsTo(Tahap::class, 'tahap_id');
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Sudah berlaku (tanggal berlaku ≤ hari ini). */
    public function sudahBerlaku(): bool
    {
        return $this->berlaku_mulai !== null && $this->berlaku_mulai->lte(today());
    }

    /**
     * Versi yang berlaku untuk dokumen bertanggal $tanggal di cluster $lokasiId / tahap $tahapId.
     * Yang paling khusus dipakai lebih dulu: cluster + tahap → cluster (semua tahap) → semua
     * cluster. NULL = pakai teks bawaan aplikasi.
     */
    public static function berlakuUntuk(string $dokumen, ?int $lokasiId, $tanggal, ?int $tahapId = null): ?self
    {
        $tgl = Carbon::parse($tanggal ?? today())->toDateString();
        $q = static::query()->where('dokumen', $dokumen)->whereDate('berlaku_mulai', '<=', $tgl)
            ->orderByDesc('berlaku_mulai')->orderByDesc('versi');

        if ($lokasiId) {
            if ($tahapId) {
                $khusus = (clone $q)->where('lokasi_id', $lokasiId)->where('tahap_id', $tahapId)->first();
                if ($khusus) {
                    return $khusus;
                }
            }
            $khusus = (clone $q)->where('lokasi_id', $lokasiId)->whereNull('tahap_id')->first();
            if ($khusus) {
                return $khusus;
            }
        }

        return $q->whereNull('lokasi_id')->first();
    }

    /** Versi berikutnya — dihitung per dokumen + cluster + tahap. */
    public static function versiBerikutnya(string $dokumen, ?int $lokasiId, ?int $tahapId = null): int
    {
        return (int) static::withTrashed()->where('dokumen', $dokumen)
            ->when($lokasiId, fn ($q) => $q->where('lokasi_id', $lokasiId), fn ($q) => $q->whereNull('lokasi_id'))
            ->when($tahapId, fn ($q) => $q->where('tahap_id', $tahapId), fn ($q) => $q->whereNull('tahap_id'))
            ->max('versi') + 1;
    }
}
