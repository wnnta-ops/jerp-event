<?php

namespace App\Modules\FO\Booked\Services;

use App\Modules\FO\FinanceAR\Models\Tts;
use App\Modules\FO\FinanceAR\Services\TtsToBkmService;
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
 * Verifikasi Booked Manual — alur 2 langkah (selaras permintaan):
 *   1) APPROVE: tim cek mutasi bank → setujui (catat booking fee), BELUM pilih unit.
 *   2) PILIH UNIT: operator pilih unit rumah → bentuk Reservasi + Kontrak tahap SPRS
 *      + TTS booking fee (→ Buka Kuitansi/BKM, tak kelewat). Lanjut SPRS → SPR.
 */
class BookedVerificationService
{
    public function __construct(private readonly NumeratorServiceInterface $numerator) {}

    /** Langkah 1 — setujui pendaftaran (cek mutasi bank). Belum pilih unit. */
    public function approve(NupRegistration $reg, ?float $bookingFee, ?string $note, ?int $transferAnonimId = null): NupRegistration
    {
        if ($reg->verif_status === 'APPROVED') {
            throw new RuntimeException('Pendaftaran sudah di-approve.');
        }
        if ($reg->verif_status === 'REJECTED') {
            throw new RuntimeException('Pendaftaran sudah ditolak.');
        }

        $reg->update([
            'verif_status' => 'APPROVED',
            'status' => 'CHECK_IN',                  // disetujui, menunggu Pilih Unit
            'booking_fee_paid' => $bookingFee ?? 0,
            'transfer_anonim_id' => $transferAnonimId,
            'verified_by' => Auth::id(),
            'verified_at' => now(),
            'is_manual_approve' => true,
            'verif_note' => $note,
        ]);

        // Transfer Anonim dipilih sebagai bukti booking → status otomatis SELESAI,
        // customer ditetapkan dari pendaftar booked (bila belum).
        if ($transferAnonimId && $tx = \App\Modules\FO\FinanceAR\Models\TransferAnonim::find($transferAnonimId)) {
            $tx->update([
                'status' => 'SELESAI',
                'customer_id' => $tx->customer_id ?: $reg->customer_id,
            ]);
        }

        return $reg->fresh();
    }

    /** Langkah 2 — pilih unit → bentuk Reservasi + Kontrak SPRS + TTS booking fee. */
    public function pilihUnit(NupRegistration $reg, int $unitId, ?int $skemaId = null): NupRegistration
    {
        if ($reg->verif_status !== 'APPROVED') {
            throw new RuntimeException('Pendaftaran belum di-approve.');
        }
        if ($reg->reservasi_id) {
            throw new RuntimeException('Unit sudah dipilih (kontrak sudah terbentuk).');
        }

        return DB::transaction(function () use ($reg, $unitId, $skemaId) {
            // Atomic lock + cek ketersediaan. Satu unit hanya boleh diambil sekali:
            // tolak unit yang sudah BOOKING/TERJUAL (dipegang pendaftar lain), bukan
            // hanya TERJUAL — cegah dobel-booking saat 2 verifikator pilih unit sama.
            $unit = Unit::lockForUpdate()->findOrFail($unitId);
            // Boleh TERSEDIA, atau HOLD yang memang di-hold untuk registrasi ini
            // (transfer cocok dalam masa hold → lanjut booked, hold dilepas di bawah).
            $heldForThis = $unit->status === 'HOLD' && (int) $unit->hold_registration_id === (int) $reg->id;
            if ($unit->status !== 'TERSEDIA' && ! $heldForThis) {
                throw new RuntimeException(
                    'Unit '.trim(($unit->blok ? $unit->blok.' / ' : '').$unit->no_unit).
                    ' sudah '.$unit->status.'. Pilih unit lain.'
                );
            }

            $harga = (float) $unit->harga_jual;
            $caraBayar = $reg->cara_bayar ?: 'KPR';
            $bookingFee = (float) ($reg->booking_fee_paid ?? 0);

            $reservasi = Reservasi::create([
                'tenant_id' => $reg->tenant_id,
                'no_reservasi' => $this->numerator->next('RSV'),
                'customer_id' => $reg->customer_id,
                'unit_id' => $unit->id,
                'tgl_reservasi' => now()->toDateString(),
                'tgl_kadaluarsa' => now()->addDays(7)->toDateString(),
                'harga_jual' => $harga,
                'booking_fee' => $bookingFee,
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
                'keterangan' => 'Booked manual '.$reg->nama_pendaftar,
            ]);

            // SPRS = booking sementara → unit BOOKING (bukan TERJUAL). Jadi TERJUAL
            // saat kontrak naik ke SPR (lihat EditKontrak "Simpan sebagai SPR").
            // Lepas field hold (kalau unit ini sebelumnya di-hold sales).
            $unit->update(['status' => 'BOOKING', 'hold_until' => null, 'hold_registration_id' => null, 'hold_by' => null]);
            $reservasi->update(['status' => 'NAIK_KONTRAK']);

            app(KontrakGeneratorService::class)->all($kontrak);

            // TTS booking fee (transfer manual) → langsung BKM/Kwitansi.
            $ttsId = null;
            if ($bookingFee > 0) {
                // Bank, No. Referensi (berita bank), & keterangan ikut dari Transfer Anonim
                // yang dicocokkan finance saat verifikasi.
                $transfer = $reg->transferAnonim;
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
                    'bank_id' => $transfer?->bank_id,
                    'no_referensi' => $transfer?->keterangan_bank,
                    'status' => 'KONFIRMASI',
                    'skip_gl_posting' => false,
                    'keterangan' => 'Booking fee Booked manual '.$reg->nama_pendaftar
                        .($transfer?->keterangan ? ' · '.$transfer->keterangan : ''),
                ]);
                // Uang booking fee SUDAH masuk (transfer anonim terverifikasi) → konversi
                // TTS ke BKM sekarang: Booking Fee jadi LUNAS + Kwitansi terbit + posting GL.
                TtsToBkmService::convert($tts);
                $ttsId = $tts->id;
            }

            $reg->update([
                'status' => 'PILIH_UNIT',
                'unit_id' => $unit->id,
                'reservasi_id' => $reservasi->id,
                'tts_id' => $ttsId,
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
