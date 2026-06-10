<?php

namespace App\Modules\FO\Booked\Services;

use App\Modules\FO\FinanceAR\Models\Tts;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Reservasi;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Modules\FO\MarketingSales\Services\KontrakGeneratorService;
use App\Services\Numerator\NumeratorServiceInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Verifikasi Booked Manual — tim cek mutasi bank lalu APPROVE. Approval langsung
 * membentuk Reservasi (unit dipilih) + Kontrak tahap SPRS (unit sudah pasti,
 * tak lewat launching). Lanjut alur SPRS → SPR seperti biasa.
 */
class BookedVerificationService
{
    public function __construct(private readonly NumeratorServiceInterface $numerator) {}

    public function approve(NupRegistration $reg, int $unitId, ?float $bookingFee, ?string $note, ?int $skemaId = null): NupRegistration
    {
        if ($reg->verif_status === 'APPROVED') {
            throw new RuntimeException('Pendaftaran sudah di-approve.');
        }

        return DB::transaction(function () use ($reg, $unitId, $bookingFee, $note, $skemaId) {
            $unit = Unit::lockForUpdate()->findOrFail($unitId);
            if ($unit->status === 'TERJUAL') {
                throw new RuntimeException('Unit '.$unit->no_unit.' sudah terjual.');
            }

            $harga = (float) $unit->harga_jual;
            $caraBayar = $reg->cara_bayar ?: 'KPR';

            $reservasi = Reservasi::create([
                'tenant_id' => $reg->tenant_id,
                'no_reservasi' => $this->numerator->next('RSV'),
                'customer_id' => $reg->customer_id,
                'unit_id' => $unit->id,
                'tgl_reservasi' => now()->toDateString(),
                'tgl_kadaluarsa' => now()->addDays(7)->toDateString(),
                'harga_jual' => $harga,
                'booking_fee' => $bookingFee ?? 0,
                'status' => 'AKTIF',
                'keterangan' => 'Booked manual',
            ]);

            $kontrak = Kontrak::create([
                'tenant_id' => $reg->tenant_id,
                'no_kontrak' => $this->numerator->next($caraBayar === 'KPR' ? 'KPR' : 'KONTRAK'),
                'reservasi_id' => $reservasi->id,
                'customer_id' => $reg->customer_id,
                'unit_id' => $unit->id,
                'skema_id' => $skemaId,
                'agent_id' => $reg->agent_id,
                'tgl_kontrak' => now()->toDateString(),
                'harga_jual' => $harga,
                'diskon' => 0,
                'ppn' => 0,
                'total_kontrak' => $harga,
                'cara_bayar' => $caraBayar,
                'status' => 'AKTIF',
                'tahap' => 'SPRS',
                'keterangan' => 'Booked manual '.$reg->nama_pendaftar.($note ? ' — '.$note : ''),
            ]);

            $unit->update(['status' => 'TERJUAL']);
            $reservasi->update(['status' => 'NAIK_KONTRAK']);

            // Bentuk otomatis tagihan/komisi/gimmick (tagihan butuh skema; bisa
            // di-regenerate di tab Skema Bayar bila skema di-set belakangan).
            app(KontrakGeneratorService::class)->all($kontrak);

            // TTS untuk booking fee (transfer manual) → feed ke "Buka Kuitansi"
            // (TtsToBkmService) → BKM (posting GL) → cetak Kwitansi.
            $ttsId = null;
            if (($bookingFee ?? 0) > 0) {
                $tts = Tts::create([
                    'tenant_id' => $reg->tenant_id,
                    'no_tts' => $this->numerator->next('TTS'),
                    'tgl_tts' => now()->toDateString(),
                    'tgl_terima' => now()->toDateString(),
                    'customer_id' => $reg->customer_id,
                    'kontrak_id' => $kontrak->id,
                    'jenis_pembayaran' => 'BOOKING',
                    'amount' => $bookingFee,
                    'cara_bayar' => 'TRANSFER',
                    'status' => 'KONFIRMASI',
                    'skip_gl_posting' => false,   // transfer manual → posting GL saat Buka Kuitansi
                    'keterangan' => 'Booking fee Booked manual '.$reg->nama_pendaftar,
                ]);
                $ttsId = $tts->id;
            }

            $reg->update([
                'verif_status' => 'APPROVED',
                'status' => 'PILIH_UNIT',
                'unit_id' => $unit->id,
                'reservasi_id' => $reservasi->id,
                'tts_id' => $ttsId,
                'booking_fee_paid' => $bookingFee ?? 0,
                'verified_by' => Auth::id(),
                'verified_at' => now(),
                'is_manual_approve' => true,
                'verif_note' => $note,
            ]);

            return $reg->fresh();
        });
    }

    public function reject(NupRegistration $reg, string $note): NupRegistration
    {
        $reg->update(['verif_status' => 'REJECTED', 'verif_note' => $note, 'verified_by' => Auth::id(), 'verified_at' => now()]);

        return $reg->fresh();
    }
}
