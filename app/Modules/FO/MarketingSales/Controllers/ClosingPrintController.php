<?php

namespace App\Modules\FO\MarketingSales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FO\FinanceAR\Models\Bkm;
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
        $kontrak->load(['customer', 'unit.unitType', 'unit.lokasi', 'skema.lines', 'agent', 'reservasi']);
        $nup = NupRegistration::query()->where('reservasi_id', $kontrak->reservasi_id)->orderByDesc('id')->first();

        return $this->render('pdf.fo.spr', ['kontrak' => $kontrak, 'nup' => $nup], 'SPR-'.$kontrak->no_kontrak);
    }

    /**
     * SPRS (Surat Pemesanan Rumah Sementara) — dari NupRegistration. Dipakai utk
     * dilihat/diunduh customer (link dikirim via WA) & dilampirkan di email saat
     * verifikasi data di-APPROVE.
     */
    public function sprs(NupRegistration $registration): mixed
    {
        $registration->load(['customer', 'agent']);

        return $this->render('pdf.fo.sprs', ['reg' => $registration], 'SPRS-'.($registration->no_nup ?: $registration->id));
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
