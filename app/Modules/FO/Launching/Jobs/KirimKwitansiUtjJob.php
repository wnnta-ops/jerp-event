<?php

namespace App\Modules\FO\Launching\Jobs;

use App\Modules\FO\Komunikasi\Services\KirimPesanTemplate;
use App\Modules\FO\Komunikasi\Services\NilaiPesan;
use App\Modules\FO\Komunikasi\Support\JenisPesan;
use App\Modules\FO\Launching\Mail\KwitansiUtjMail;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Controllers\ClosingPrintController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Kwitansi UTJ ke customer — WA + email (isi dari Template Pesan · Kwitansi UTJ).
 * Dipicu saat pembayaran Xendit berhasil (markPaid) dan saat UTJ manual di-approve.
 * Pemicu wajib cek LogPesan::sudahTerkirim() agar tak terkirim dua kali.
 */
class KirimKwitansiUtjJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public function __construct(
        public readonly int $registrationId,
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
        $r = NupRegistration::withoutGlobalScopes()
            ->with(['customer', 'event', 'projectPref', 'agent', 'unit.lokasi'])
            ->find($this->registrationId);

        if (! $r) {
            return;
        }

        $kirim->kirim(
            ref: [
                'jenis' => JenisPesan::KWITANSI_UTJ,
                'ref_type' => 'nup',
                'ref_id' => $r->id,
                'ref_no' => ClosingPrintController::namaBerkasKwitansiUtj($r),
                'kontrak_id' => null,
                'penerima_nama' => $r->nama_pendaftar,
                'percobaan' => $this->percobaan,
                'kirim_ulang_dari' => $this->ulangDari,
            ],
            nilai: NilaiPesan::dariNup($r),
            lokasiId: $r->unit?->lokasi_id,
            nomor: $r->phone ?: $r->customer?->mobile,
            email: $r->email ?: $r->customer?->email,
            // {{3}} = nama acara, bukan nomor NUP: pada tahap ini NUP belum tentu terbit.
            waIdBawaan: (string) config('services.qontak.template_utj_paid'),
            waKunciBawaan: ['nama', 'nominal_angka', 'nama_acara'],
            dokumen: [
                'url' => route('fo.cetak.kwitansi-utj-pdf', ['registration' => $r->id, 'hash' => ClosingPrintController::kwitansiUtjPdfHash($r->id)]),
                'nama' => ClosingPrintController::namaBerkasKwitansiUtj($r).'.pdf',
                'pdf' => fn () => ClosingPrintController::pdfKwitansiUtj($r),
            ],
            mailBawaan: fn () => new KwitansiUtjMail($r),
            kanal: $this->kanal,
        );
    }
}
