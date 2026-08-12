<?php

namespace App\Modules\FO\Launching\Jobs;

use App\Modules\FO\Launching\Mail\NupConfirmationMail;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\Shared\Integrations\WhatsApp\WhatsAppGatewayInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Sends NUP confirmation via email and (stub) WhatsApp.
 * Email = built-in. WhatsApp = TODO when provider chosen (Wablas / Twilio / Vonage).
 */
class KirimKonfirmasiNupJob implements ShouldQueue
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
        $registration = NupRegistration::withoutGlobalScopes()
            ->with(['customer', 'event', 'projectPref', 'agent'])
            ->find($this->registrationId);

        if (! $registration) {
            return;
        }

        // Link SPRS (signed, publik) — hanya bila sudah APPROVED (urutan/no_nup terbit).
        // Dilampirkan PDF di email + dikirim sbg link di WA.
        $sprsUrl = $registration->urutan !== null
            ? URL::signedRoute('fo.cetak.sprs', ['registration' => $registration->id])
            : null;

        // Email (saat approved → lampirkan SPRS PDF + tautan).
        if ($email = $registration->email ?: $registration->customer?->email) {
            Mail::to($email)->send(new NupConfirmationMail($registration, $sprsUrl));
        }

        // WhatsApp via Mekari Qontak (template-based; di-skip aman bila belum dikonfigurasi).
        if ($phone = $registration->phone ?: $registration->customer?->mobile) {
            $this->dispatchWhatsApp($phone, $registration, $sprsUrl);
        }
    }

    private function dispatchWhatsApp(string $phone, NupRegistration $r, ?string $sprsUrl = null): void
    {
        $gateway = app(WhatsAppGatewayInterface::class);
        $eventName = $r->event?->name ?? '-';
        $tglLaunching = optional($r->event?->tgl_launching)->translatedFormat('d M Y') ?? '-';

        if ($r->urutan !== null) {
            // Approved → template konfirmasi (6 variabel) + LAMPIRAN PDF SPRS
            // sebagai header dokumen (template Qontak WAJIB dibuat dengan
            // Header: Document). Urutan parameter HARUS PERSIS sama dgn template:
            //   {{1}} Nama · {{2}} Nama acara · {{3}} No urut NUP
            //   {{4}} No telepon · {{5}} NIK · {{6}} Sales/Agen
            // ({{1}} & {{2}} muncul dua kali di badan template — tetap satu parameter.)
            // JANGAN kirim 7 parameter (dulu ada "Project" terpisah): Qontak menolak
            // seluruh pesan dgn 422 "Message Template has 6 variable".
            $sales = $r->agent?->sales_label ?: ($r->ref_sales_name ?: '-');
            $nik = $r->nik ?: ($r->customer?->nik ?: '-');
            // {{2}} dipakai pada "Acara Pilih Unit {{2}}" → nama EVENT; nama project
            // hanya dipakai bila event tak punya nama.
            $namaAcara = $eventName !== '-' ? $eventName : ($r->projectPref?->name ?? '-');

            // URL PDF publik TANPA query string (token HMAC di path) — Qontak/Meta
            // menolak URL dokumen ber-query ('?','&','=') dgn 422. Butuh Chromium
            // di server (INSTALL_CHROMIUM=true).
            $sprsPdfUrl = route('fo.cetak.sprs-pdf', [
                'registration' => $r->id,
                'hash' => \App\Modules\FO\MarketingSales\Controllers\ClosingPrintController::sprsPdfHash($r->id),
            ]);

            $gateway->sendTemplate(
                toNumber: $phone,
                toName: (string) $r->nama_pendaftar,
                templateId: (string) config('services.qontak.template_konfirmasi'),
                bodyParams: [
                    (string) $r->nama_pendaftar,  // {{1}} Nama
                    $namaAcara,                   // {{2}} Nama acara / pilih unit
                    (string) $r->no_nup,          // {{3}} No urut NUP
                    $phone,                       // {{4}} No telepon
                    (string) $nik,                // {{5}} NIK
                    (string) $sales,              // {{6}} Sales/Agen
                ],
                documentUrl: $sprsPdfUrl,
                documentFilename: 'SPRS-'.($r->no_nup ?: $r->id).'.pdf',
            );

            return;
        }

        // Menunggu pembayaran UTJ → template tagihan + link bayar.
        $gateway->sendTemplate(
            toNumber: $phone,
            toName: (string) $r->nama_pendaftar,
            templateId: (string) config('services.qontak.template_utj'),
            bodyParams: [
                (string) $r->nama_pendaftar,
                $eventName,
                'Rp '.number_format((float) $r->utj_amount, 0, ',', '.'),
                (string) ($r->xendit_invoice_url ?? '-'),
            ],
        );
    }
}
