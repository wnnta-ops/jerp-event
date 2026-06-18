<?php

namespace App\Modules\FO\MarketingSales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FO\FinanceAR\Models\Bkm;
use App\Modules\FO\FinanceAR\Models\Fp;
use App\Modules\FO\FinanceAR\Models\Tts;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Support\Terbilang;
use Illuminate\Http\Response;
use Spatie\Browsershot\Browsershot;

/**
 * Cetak dokumen closing: SPR (Surat Pemesanan Rumah), Kwitansi (dari BKM),
 * dan Jadwal Pembayaran (dari baris skema). HTML printable; ?pdf=1 → Browsershot.
 */
class ClosingPrintController extends Controller
{
    public function spr(Kontrak $kontrak): mixed
    {
        $kontrak->load(['customer', 'unit.unitType', 'unit.lokasi', 'skema.lines', 'agent', 'reservasi', 'gimmicks', 'tagihan']);
        $nup = NupRegistration::query()->where('reservasi_id', $kontrak->reservasi_id)->orderByDesc('id')->first();

        return $this->render('pdf.fo.spr', ['kontrak' => $kontrak, 'nup' => $nup], 'SPR-'.$kontrak->no_kontrak);
    }

    /**
     * SPRS (Surat Pemesanan Rumah Sementara) — dari NupRegistration. Dipakai utk
     * dilihat/diunduh customer (link dikirim via WA) & dilampirkan di email saat
     * verifikasi data di-APPROVE. Bila sudah ada kontrak (reservasi naik kontrak),
     * data unit/harga/pembayaran ikut ditarik dari kontrak.
     */
    public function sprs(NupRegistration $registration): mixed
    {
        $registration->load(['customer', 'agent']);
        $kontrak = $registration->reservasi_id
            ? Kontrak::with(['customer', 'unit.unitType', 'unit.lokasi', 'skema.lines', 'agent', 'reservasi', 'gimmicks'])
                ->where('reservasi_id', $registration->reservasi_id)->orderByDesc('id')->first()
            : null;

        return $this->render('pdf.fo.sprs', $this->sprsViewData($registration, $kontrak),
            'SPRS-'.($registration->no_nup ?: $registration->id));
    }

    /**
     * Cetak SPRS dari Kontrak (tahap SPRS) — dipakai tombol "Cetak SPRS" di daftar SPRS.
     * Cari NupRegistration terkait via reservasi; bila tak ada (SPRS dibuat manual),
     * bentuk objek registrasi sementara dari kontrak agar template tetap lengkap.
     */
    public function sprsFromKontrak(Kontrak $kontrak): mixed
    {
        $kontrak->load(['customer', 'unit.unitType', 'unit.lokasi', 'skema.lines', 'agent', 'reservasi', 'gimmicks']);
        $reg = NupRegistration::query()->where('reservasi_id', $kontrak->reservasi_id)->orderByDesc('id')->first();
        if ($reg) {
            $reg->load(['customer', 'agent']);
        } else {
            $reg = $this->regDariKontrak($kontrak);
        }

        return $this->render('pdf.fo.sprs', $this->sprsViewData($reg, $kontrak), 'SPRS-'.$kontrak->no_kontrak);
    }

    /**
     * @param  array<string,mixed>  ...
     * @return array<string,mixed>
     */
    private function sprsViewData(NupRegistration $reg, ?Kontrak $kontrak): array
    {
        $tts = $kontrak
            ? Tts::query()->where('kontrak_id', $kontrak->id)
                ->where('jenis_pembayaran', 'BOOKING')->orderBy('id')->first()
            : null;

        return ['reg' => $reg, 'kontrak' => $kontrak, 'tts' => $tts];
    }

    /** Objek NupRegistration sementara (tak disimpan) dari Kontrak untuk render SPRS. */
    private function regDariKontrak(Kontrak $kontrak): NupRegistration
    {
        $reg = new NupRegistration;
        $reg->customer_id = $kontrak->customer_id;
        $reg->nama_pendaftar = $kontrak->customer?->full_name;
        $reg->no_nup = $kontrak->no_kontrak;
        $reg->nik = $kontrak->customer?->nik;
        $reg->phone = $kontrak->customer?->mobile;
        $reg->tipe_nup = $kontrak->unit?->unitType?->name ?: 'RUMAH';
        $reg->cara_bayar = $kontrak->cara_bayar;
        $reg->agent_id = $kontrak->agent_id;
        $reg->keterangan = $kontrak->keterangan;
        $reg->setRelation('customer', $kontrak->customer);
        $reg->setRelation('agent', $kontrak->agent);

        return $reg;
    }

    public function kwitansi(Bkm $bkm): mixed
    {
        $bkm->load(['customer', 'kontrak.unit', 'tts']);

        return $this->render('pdf.fo.kwitansi', [
            'bkm' => $bkm,
            'terbilang' => Terbilang::rupiah((float) $bkm->amount),
        ], 'KWITANSI-'.$bkm->no_bkm);
    }

    public function jadwal(Kontrak $kontrak): mixed
    {
        $kontrak->load(['customer', 'unit', 'skema.lines']);
        $harga = (float) $kontrak->harga_jual;

        $rows = [];
        foreach ($kontrak->skema?->lines ?? [] as $line) {
            $amount = $line->basis === 'NOMINAL'
                ? (float) $line->nominal
                : round($harga * (float) $line->persen / 100, 2);
            $jatuh = $kontrak->tgl_kontrak
                ? $kontrak->tgl_kontrak->copy()->addDays((int) $line->jatuh_tempo_hari)
                : null;
            $rows[] = [
                'nama' => $line->nama_termin,
                'basis' => $line->basis,
                'persen' => $line->persen,
                'amount' => $amount,
                'jatuh' => $jatuh,
            ];
        }

        return $this->render('pdf.fo.jadwal', ['kontrak' => $kontrak, 'rows' => $rows], 'JADWAL-'.$kontrak->no_kontrak);
    }

    public function fakturPenjualan(Fp $fp): mixed
    {
        $fp->load(['kontrak.customer', 'kontrak.unit.unitType', 'kontrak.unit.lokasi', 'kontrak.agent', 'kontrak.skema', 'kontrak.tagihan']);

        return $this->render('pdf.fo.faktur-penjualan', ['fp' => $fp], 'FAKTUR-'.$fp->no_fp);
    }

    /** @param  array<string,mixed>  $data */
    private function render(string $view, array $data, string $filename): mixed
    {
        if (request()->boolean('pdf') && class_exists(Browsershot::class)) {
            $html = view($view, $data)->render();
            $pdf = Browsershot::html($html)->format('A4')->pdf();

            return new Response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$filename.'.pdf"',
            ]);
        }

        return view($view, $data);
    }
}
