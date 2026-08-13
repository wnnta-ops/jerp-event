<?php

namespace App\Modules\FO\Launching\Jobs;

use App\Modules\FO\Launching\Mail\KwitansiUtjMail;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Controllers\ClosingPrintController;
use App\Modules\FO\Shared\Integrations\WhatsApp\WhatsAppGatewayInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Kwitansi UTJ ke customer — WA + email, dipicu SEKALI saat pembayaran Xendit
 * berhasil (VerifikasiNupService::markPaid). Sebelum ini, customer tidak menerima
 * kabar apa pun antara pembayaran dan persetujuan verifikator.
 *
 * Berbeda dari KirimKonfirmasiNupJob yang jalan saat pendaftaran (tagihan UTJ)
 * dan saat approve (nomor NUP terbit + SPRS).
 */
class KirimKwitansiUtjJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = 30;

    public function __construct(public readonly int $registrationId) {}

    public function viaQueue(): string
    {
        return 'email';
    }

    public function handle(): void
    {
        $r = NupRegistration::withoutGlobalScopes()
            ->with(['customer', 'event', 'projectPref'])
            ->find($this->registrationId);

        if (! $r) {
            return;
        }

        // Email dan WA DIPISAH try/catch: kegagalan satu kanal tidak boleh
        // membatalkan yang lain. Job ini dipicu dari markPaid() — kalau seluruh
        // job gagal dan di-retry, pembayaran bisa dikabari berulang.
        if ($email = $r->email ?: $r->customer?->email) {
            try {
                Mail::to($email)->send(new KwitansiUtjMail($r));
            } catch (\Throwable $e) {
                Log::warning('Kwitansi UTJ: email gagal untuk NUP #'.$r->id.' — '.$e->getMessage());
            }
        }

        if ($phone = $r->phone ?: $r->customer?->mobile) {
            try {
                $this->kirimWhatsApp($phone, $r);
            } catch (\Throwable $e) {
                Log::warning('Kwitansi UTJ: WA gagal untuk NUP #'.$r->id.' — '.$e->getMessage());
            }
        }
    }

    private function kirimWhatsApp(string $phone, NupRegistration $r): void
    {
        $templateId = (string) config('services.qontak.template_utj_paid');
        if ($templateId === '') {
            Log::info('Kwitansi UTJ: QONTAK_TEMPLATE_UTJ_PAID belum diisi — WA dilewati untuk NUP #'.$r->id);

            return;
        }

        // Nominal yang benar-benar dibayar bila Xendit melaporkannya.
        $nominal = number_format((float) ($r->xendit_paid_amount ?: ($r->booking_fee_paid ?: $r->utj_amount)), 0, ',', '.');

        // {{3}} = NAMA ACARA, bukan nomor NUP: pada tahap ini NUP belum terbit
        // (baru muncul setelah verifikasi disetujui).
        $acara = $r->event?->name ?: ($r->projectPref?->name ?: '-');

        // URL PDF TANPA query string (token HMAC di path) — Qontak/Meta menolak
        // URL dokumen ber-'?','&','=' dengan 422. Butuh Chromium di server.
        $pdfUrl = route('fo.cetak.kwitansi-utj-pdf', [
            'registration' => $r->id,
            'hash' => ClosingPrintController::kwitansiUtjPdfHash($r->id),
        ]);

        app(WhatsAppGatewayInterface::class)->sendTemplate(
            toNumber: $phone,
            toName: (string) $r->nama_pendaftar,
            templateId: $templateId,
            bodyParams: [
                (string) $r->nama_pendaftar,  // {{1}} Nama
                $nominal,                     // {{2}} Nominal UTJ (tanpa "Rp")
                $acara,                       // {{3}} Nama acara / project
            ],
            documentUrl: $pdfUrl,
            documentFilename: ClosingPrintController::namaBerkasKwitansiUtj($r).'.pdf',
        );
    }
}
