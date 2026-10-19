<?php

namespace App\Modules\FO\Launching\Jobs;

use App\Modules\FO\Komunikasi\Services\KirimPesanTemplate;
use App\Modules\FO\Komunikasi\Services\NilaiPesan;
use App\Modules\FO\Komunikasi\Support\JenisPesan;
use App\Modules\FO\Launching\Mail\NupConfirmationMail;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Controllers\ClosingPrintController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * Pesan NUP ke customer (WA + email), isi dari Pengaturan → Template Pesan:
 *  - belum approve → TAGIHAN UTJ (link bayar);
 *  - sudah approve (urutan terbit) → KONFIRMASI NUP + PDF SPRS.
 * Setiap kiriman tercatat di Monitoring Pesan.
 */
class KirimKonfirmasiNupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Satu percobaan: tiap kanal sudah dicatat sendiri-sendiri; mengulang job hanya
    // mengirim ganda kanal yang sudah berhasil. Yang gagal dikirim ulang dari Monitoring.
    public $tries = 1;

    /**
     * @param  string|null  $kanal  'WA' / 'EMAIL' (kirim ulang dari Monitoring), null = keduanya
     */
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

        $approved = $r->urutan !== null;
        $sprsUrl = $approved ? URL::signedRoute('fo.cetak.sprs', ['registration' => $r->id]) : null;
        $nilai = NilaiPesan::dariNup($r, $sprsUrl);
        $ref = [
            'jenis' => $approved ? JenisPesan::KONFIRMASI_NUP : JenisPesan::TAGIHAN_UTJ,
            'ref_type' => 'nup',
            'ref_id' => $r->id,
            'ref_no' => $r->no_nup ?: ('NUP #'.$r->id),
            'kontrak_id' => null,
            'penerima_nama' => $r->nama_pendaftar,
            'percobaan' => $this->percobaan,
            'kirim_ulang_dari' => $this->ulangDari,
        ];

        // Header dokumen WA hanya untuk konfirmasi (SPRS). URL tanpa query string —
        // Qontak/Meta menolak URL dokumen ber-'?','&','=' dengan 422.
        $dokumen = $approved ? [
            'url' => route('fo.cetak.sprs-pdf', ['registration' => $r->id, 'hash' => ClosingPrintController::sprsPdfHash($r->id)]),
            'nama' => ClosingPrintController::namaBerkasSprs($r).'.pdf',
            'pdf' => fn () => ClosingPrintController::pdfSprsTersimpan($r),
        ] : null;

        $kirim->kirim(
            ref: $ref,
            nilai: $nilai,
            lokasiId: $r->unit?->lokasi_id,
            nomor: $r->phone ?: $r->customer?->mobile,
            email: $r->email ?: $r->customer?->email,
            // Cadangan bila Template Pesan belum diatur: ID .env & urutan parameter lama
            // (konfirmasi tepat 6 variabel — Qontak menolak 422 bila jumlahnya beda).
            waIdBawaan: (string) config($approved ? 'services.qontak.template_konfirmasi' : 'services.qontak.template_utj'),
            waKunciBawaan: $approved
                ? ['nama', 'nama_acara', 'no_nup', 'no_hp', 'nik', 'sales']
                : ['nama', 'nama_acara', 'nominal_utj', 'link_bayar'],
            dokumen: $dokumen,
            mailBawaan: fn () => new NupConfirmationMail($r, $sprsUrl),
            kanal: $this->kanal,
        );
    }
}
