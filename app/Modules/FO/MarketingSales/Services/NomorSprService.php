<?php

namespace App\Modules\FO\MarketingSales\Services;

use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\Survei\Services\WebhookSurvei;
use App\Services\Numerator\NumeratorService;
use Illuminate\Support\Facades\DB;

/**
 * Nomor kontrak SPRS ↔ SPR.
 *
 * SPRS memakai penomoran SPRS sendiri (000001/SPRS/JR/XI/2026); nomor SPR baru diambil saat
 * kontrak naik ke SPR. Dengan begitu SPRS yang batal tidak memakan nomor SPR dan urutan SPR
 * tetap rapat. Nomor SPRS lama disimpan di `no_kontrak_sementara`.
 *
 * BO mengenali kontrak lewat nomornya (bookings.booking_number), jadi booking BO ikut diganti
 * nomornya — tanpa itu sinkron berikutnya membuat booking kedua dengan nomor baru.
 */
class NomorSprService
{
    public function __construct(private readonly NumeratorService $numerator) {}

    /**
     * Naikkan kontrak ke tahap SPR. Nomor SPR baru hanya diterbitkan bila nomornya belum
     * bernomor SPR (SPRS lama yang terlanjur memakai nomor SPR tetap memakai nomornya).
     * Mengembalikan nomor SPR-nya.
     */
    public function naikkan(Kontrak $k): string
    {
        return DB::transaction(function () use ($k) {
            $k->refresh();
            if ($k->tahap === 'SPR') {
                return (string) $k->no_kontrak;
            }

            $lama = (string) $k->no_kontrak;
            if ($lama !== '' && $this->numerator->cocokFormat('SPR', $lama)) {
                $k->update(['tahap' => 'SPR']);

                return $lama;
            }

            $this->gantiNomor($k, $this->numerator->next('SPR'), ['tahap' => 'SPR']);

            return (string) $k->no_kontrak;
        });
    }

    /** Ganti nomor kontrak (nomor lama → no_kontrak_sementara) + booking BO & notifikasi survei. */
    public function gantiNomor(Kontrak $k, string $baru, array $ubahLain = []): void
    {
        $lama = (string) $k->no_kontrak;

        // Didaftarkan SEBELUM update: sinkron booking (Kontrak::saved → job afterCommit) mencari
        // booking BO lewat nomor BARU, jadi penggantian nomornya harus berjalan lebih dulu.
        DB::afterCommit(fn () => $this->gantiDiBo($lama, $baru));

        $k->forceFill($ubahLain + ['no_kontrak' => $baru, 'no_kontrak_sementara' => $lama])->save();

        if ($lama !== '') {
            WebhookSurvei::antre('spr.deleted', ['no_spr' => $lama], now());
        }
    }

    private function gantiDiBo(string $lama, string $baru): void
    {
        if ($lama === '' || ! config('ipro.sync_enabled', true)) {
            return;
        }

        try {
            DB::connection((string) config('ipro.connection', 'ipro_bo'))->table('bookings')
                ->where('tenant_id', (int) config('ipro.tenant_id', 1))
                ->where('booking_number', $lama)
                ->update(['booking_number' => $baru, 'notes' => 'SPR '.$baru]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
