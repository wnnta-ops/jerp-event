<?php

namespace App\Modules\FO\Launching\Services;

use App\Modules\FO\Adminjual\Models\Skema;
use App\Modules\FO\FinanceAR\Models\Tts;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Reservasi;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Modules\FO\MarketingSales\Models\UnitPricelist;
use App\Modules\FO\MarketingSales\Services\KontrakGeneratorService;
use App\Modules\FO\MarketingSales\Services\PaketGimmickService;
use App\Modules\FO\MarketingSales\Services\TambahDpService;
use App\Services\Numerator\NumeratorServiceInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Closing NUP — naik kontrak dari NUP yang sudah Pilih Unit (punya Reservasi AKTIF).
 *
 * Membuat:
 *  1. Kontrak LANGSUNG tahap SPR (final — TIDAK lewat SPRS; jalur Booked tetap SPRS)
 *     → unit TERJUAL, reservasi NAIK_KONTRAK, Faktur Penjualan terbit.
 *  2. TTS (Tanda Terima Sementara) untuk UTJ yang sudah dibayar → siap "Buka Kuitansi".
 *
 * UTJ yang sudah dibayar via Xendit SUDAH diposting ke jurnal (markPaid), maka TTS-nya
 * ditandai skip_gl_posting agar "Buka Kuitansi" (TTS→BKM) tidak dobel-posting.
 */
class ClosingNupService
{
    public function __construct(
        private readonly NumeratorServiceInterface $numerator,
    ) {}

    /**
     * @param  array{skema_id:int,agent_id?:int|null,cara_bayar?:string,tgl_kontrak?:string,gimmick_ids?:array<int|string>,tambah_dp?:array<int,array{nominal:float,jatuh_tempo?:string|null}>}  $data
     * @return array{kontrak:Kontrak,tts:Tts,gimmick:int,tambah_dp:int}
     */
    public function close(NupRegistration $reg, array $data): array
    {
        if (! $reg->reservasi_id) {
            throw new RuntimeException('NUP '.$reg->no_nup.' belum pilih unit (tidak ada reservasi).');
        }

        $reservasi = Reservasi::find($reg->reservasi_id);
        if (! $reservasi) {
            throw new RuntimeException('Reservasi tidak ditemukan.');
        }
        if ($reservasi->status === 'NAIK_KONTRAK' || $reservasi->kontrak()->exists()) {
            throw new RuntimeException('Reservasi '.$reservasi->no_reservasi.' sudah naik kontrak.');
        }

        $skema = Skema::find($data['skema_id']);
        if (! $skema) {
            throw new RuntimeException('Skema bayar tidak ditemukan.');
        }

        $caraBayar = $data['cara_bayar'] ?? 'KPR';            // KPR | CASH_BERTAHAP | CASH
        $harga = $this->resolveHarga($reservasi->unit_id, $skema->id, (float) $reservasi->harga_jual);
        $dpPersen = (float) $skema->dp_persen;
        $dpAmount = round($harga * $dpPersen / 100, 2);
        // UTJ yang via Xendit sudah diposting ke jurnal saat markPaid.
        $utjPosted = $reg->xendit_status === 'PAID';
        $utj = (float) ($reg->booking_fee_paid ?: $reg->utj_amount);

        return DB::transaction(function () use ($reg, $reservasi, $skema, $data, $caraBayar, $harga, $dpPersen, $dpAmount, $utj, $utjPosted) {
            $unit = Unit::lockForUpdate()->find($reservasi->unit_id);
            if (! $unit) {
                throw new RuntimeException('Unit tidak ditemukan.');
            }

            $kontrak = Kontrak::create([
                'tenant_id' => $reg->tenant_id,
                'no_kontrak' => $this->numerator->next('SPR'), // semua kontrak: prefix SPR-{YYYY}-{seq}
                'reservasi_id' => $reservasi->id,
                'customer_id' => $reservasi->customer_id,
                'unit_id' => $unit->id,
                'skema_id' => $skema->id,
                // Bawa Sales/Agent dari NUP (pendaftaran) bila closing tak menyetel —
                // agar tampil di SPR & terhubung ke Komisi/BLT.
                'agent_id' => $data['agent_id'] ?? $reg->agent_id,
                // Sales FREE-TEXT (nama + telp) dari NUP → tercetak ke customer di SPRS.
                'ref_sales_name' => $reg->ref_sales_name,
                'ref_sales_phone' => $reg->ref_sales_phone,
                'tgl_kontrak' => $data['tgl_kontrak'] ?? now()->toDateString(),
                'harga_jual' => $harga,
                'diskon' => 0,
                'ppn' => 0,
                'total_kontrak' => $harga,
                'cara_bayar' => $caraBayar,
                'tenor_bulan' => $skema->tenor_bulan,
                'dp_persen' => $dpPersen,
                'dp_amount' => $dpAmount,
                'status' => 'AKTIF',
                // Closing NUP LANGSUNG SPR (final), tidak transit di SPRS — kontrak
                // tampil di menu SPR (Final), bukan SPRS (permintaan).
                'tahap' => 'SPR',
                'keterangan' => 'Closing NUP '.$reg->no_nup.' — skema '.$skema->code,
            ]);

            $unit->update(['status' => 'TERJUAL']);
            $reservasi->update(['status' => 'NAIK_KONTRAK']);

            // SPR terbentuk → bentuk otomatis Jadwal Tagihan (skema bayar),
            // Jadwal Komisi (skema komisi), & Gimmick (master).
            app(KontrakGeneratorService::class)->all($kontrak);

            // Gimmick paket yang dicentang operator → DIBERIKAN (tercetak di SPR).
            $gimmick = app(PaketGimmickService::class)->berikan($kontrak, $data['gimmick_ids'] ?? []);

            // Tambah DP (KPR) — sama dengan tombol Tambah DP di Edit SPR.
            $tambahDp = 0;
            if (strtoupper($caraBayar) === 'KPR') {
                foreach ($data['tambah_dp'] ?? [] as $row) {
                    $nominal = (float) ($row['nominal'] ?? 0);
                    if ($nominal > 0) {
                        app(TambahDpService::class)->tambah($kontrak, $nominal, $row['jatuh_tempo'] ?? null);
                        $tambahDp++;
                    }
                }
            }

            // Tahap SPR (final) → Faktur Penjualan terbit — selaras promoteToSpr /
            // tombol "Simpan sebagai SPR" (idempotent: skip bila sudah ada).
            \App\Modules\FO\FinanceAR\Services\FakturPenjualanService::ensureForKontrak($kontrak);

            // TTS untuk UTJ yang sudah dibayar → siap dibuka jadi kwitansi.
            $tts = Tts::create([
                'tenant_id' => $reg->tenant_id,
                'no_tts' => $this->numerator->next('TTS'),
                'tgl_tts' => now()->toDateString(),
                'tgl_terima' => $reg->xendit_paid_at?->toDateString() ?? now()->toDateString(),
                'customer_id' => $reservasi->customer_id,
                'kontrak_id' => $kontrak->id,
                'jenis_pembayaran' => 'BOOKING',
                'amount' => $utj,
                'cara_bayar' => $utjPosted ? 'XENDIT' : 'TRANSFER',
                'no_referensi' => $reg->xendit_external_id ?: $reg->no_nup,
                'status' => 'KONFIRMASI',
                'skip_gl_posting' => $utjPosted,
                'keterangan' => 'UTJ closing NUP '.$reg->no_nup,
            ]);

            $reg->update(['tts_id' => $tts->id]);

            return ['kontrak' => $kontrak->refresh(), 'tts' => $tts->refresh(), 'gimmick' => $gimmick, 'tambah_dp' => $tambahDp];
        });
    }

    /** Harga jual sesuai skema (price list per unit×skema); fallback ke harga reservasi. */
    private function resolveHarga(int $unitId, int $skemaId, float $fallback): float
    {
        $pl = UnitPricelist::query()
            ->where('unit_id', $unitId)
            ->where('skema_id', $skemaId)
            ->first();

        $harga = $pl ? (float) $pl->price_list : 0.0;

        return $harga > 0 ? $harga : $fallback;
    }
}
