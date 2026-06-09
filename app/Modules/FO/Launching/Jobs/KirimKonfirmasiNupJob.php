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
            ->with(['customer', 'event', 'projectPref'])
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
            // No NUP & urutan sudah terbit (approved) → template konfirmasi.
            // Param ke-6 = link SPRS (tambahkan {{6}} di template Qontak untuk
            // mengirim dokumen SPRS ke customer; dibungkus aman bila template lama).
            $gateway->sendTemplate(
                toNumber: $phone,
                toName: (string) $r->nama_pendaftar,
                templateId: (string) config('services.qontak.template_konfirmasi'),
                bodyParams: [
                    (string) $r->nama_pendaftar,
                    (string) $r->no_nup,
                    (string) $r->urutan,
                    $eventName,
                    $tglLaunching,
                    (string) ($sprsUrl ?? '-'),
                ],
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
