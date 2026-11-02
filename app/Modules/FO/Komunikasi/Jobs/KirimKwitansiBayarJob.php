<?php

namespace App\Modules\FO\Komunikasi\Jobs;

use App\Modules\FO\FinanceAR\Models\Bkm;
use App\Modules\FO\Komunikasi\Mail\PesanTemplateMail;
use App\Modules\FO\Komunikasi\Models\TemplatePesan;
use App\Modules\FO\Komunikasi\Services\KirimPesanTemplate;
use App\Modules\FO\Komunikasi\Services\NilaiPesan;
use App\Modules\FO\Komunikasi\Support\JenisPesan;
use App\Modules\FO\MarketingSales\Controllers\ClosingPrintController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Kwitansi pembayaran (BKM: DP / angsuran / pelunasan) ke customer — WA + email.
 * Hanya jalan bila Template Pesan "Kwitansi Pembayaran" aktif untuk cluster unitnya;
 * tanpa template, tidak ada yang dikirim (fitur baru, bukan pengganti kiriman lama).
 */
class KirimKwitansiBayarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public function __construct(
        public readonly int $bkmId,
        public readonly ?string $kanal = null,
        public readonly ?int $ulangDari = null,
        public readonly int $percobaan = 1,
    ) {}

    public function viaQueue(): string
    {
        return 'email';
    }

    public function handle(KirimPesanTemplate $kirim): void
    {
        $b = Bkm::withoutGlobalScopes()->with(['customer', 'kontrak.unit.lokasi', 'kontrak.tagihan'])->find($this->bkmId);
        if (! $b) {
            return;
        }

        $lokasiId = $b->kontrak?->unit?->lokasi_id;
        if (! TemplatePesan::untuk(JenisPesan::KWITANSI_BAYAR, $lokasiId)) {
            return;
        }

        $nilai = NilaiPesan::dariBkm($b);

        $kirim->kirim(
            ref: [
                'jenis' => JenisPesan::KWITANSI_BAYAR,
                'ref_type' => 'bkm',
                'ref_id' => $b->id,
                'ref_no' => $b->no_bkm,
                'kontrak_id' => $b->kontrak_id,
                'penerima_nama' => $b->customer?->full_name,
                'percobaan' => $this->percobaan,
                'kirim_ulang_dari' => $this->ulangDari,
            ],
            nilai: $nilai,
            lokasiId: $lokasiId,
            nomor: $b->customer?->mobile ?: $b->customer?->phone,
            email: $b->customer?->email,
            waIdBawaan: JenisPesan::waIdBawaan(JenisPesan::KWITANSI_BAYAR),
            waKunciBawaan: JenisPesan::bawaan(JenisPesan::KWITANSI_BAYAR)['wa_kunci'],
            dokumen: [
                'url' => route('fo.cetak.kwitansi-bkm-pdf', ['bkm' => $b->id, 'hash' => ClosingPrintController::kwitansiPdfHash($b->id)]),
                'nama' => ClosingPrintController::namaBerkasKwitansi($b).'.pdf',
                'pdf' => fn () => ClosingPrintController::pdfKwitansi($b),
            ],
            mailBawaan: fn () => new PesanTemplateMail(
                JenisPesan::isi(JenisPesan::bawaan(JenisPesan::KWITANSI_BAYAR)['email_subjek'], $nilai),
                JenisPesan::isi(JenisPesan::bawaan(JenisPesan::KWITANSI_BAYAR)['email_isi'], $nilai, true),
                fn () => [ClosingPrintController::pdfKwitansi($b), ClosingPrintController::namaBerkasKwitansi($b).'.pdf'],
            ),
            kanal: $this->kanal,
        );
    }
}
