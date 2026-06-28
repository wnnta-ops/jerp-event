<?php

declare(strict_types=1);

namespace App\Domain\Sales\Nup\Actions;

use App\Domain\Sales\Nup\Mail\NupPaymentLinkMail;
use App\Domain\Sales\Nup\Models\NupRegistration;
use App\Support\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Kirim instruksi pembayaran NUP via EMAIL + WHATSAPP ke customer.
 *
 * Dipanggil otomatis saat pendaftaran berhasil (publik) dan saat admin menekan
 * "Kirim Link". Best-effort: kegagalan kirim TIDAK menggagalkan pendaftaran.
 * Hasil dicatat di nup_registrations.form_data['payment_instructions'].
 */
final class SendNupPaymentInstructions
{
    public function __construct(private readonly WhatsAppService $wa) {}

    /**
     * @return array{email:?string, whatsapp:?string, wa_link:?string, pay_link:?string}
     */
    public function execute(NupRegistration $reg): array
    {
        $reg->loadMissing(['scheme', 'customer']);
        $customer = $reg->customer;
        $payLink  = $this->payLink($reg);
        $amount   = number_format((float) ($reg->scheme?->nup_amount ?? 0), 0, ',', '.');

        $emailStatus = null;
        $waStatus    = null;
        $waLink      = null;

        // ── Email ──
        if (filled($customer?->email)) {
            try {
                Mail::to($customer->email)->send(new NupPaymentLinkMail($reg, $payLink));
                $emailStatus = 'sent';
            } catch (Throwable $e) {
                report($e);
                $emailStatus = 'failed';
            }
        }

        // ── WhatsApp ──
        if (filled($customer?->phone)) {
            $msg = "Halo {$customer->full_name}, terima kasih telah mendaftar NUP Java Residence (PT. Mitra Usaha Propertindo).\n\n"
                ."No. NUP : {$reg->nup_number}\n"
                ."Antrian : #{$reg->queue_no}\n"
                ."Total   : Rp {$amount}\n\n"
                .($payLink
                    ? "Silakan selesaikan pembayaran melalui tautan berikut:\n{$payLink}"
                    : 'Pendaftaran Anda sudah tercatat. Tim kami akan segera menghubungi Anda untuk proses pembayaran NUP.');

            $res      = $this->wa->send((string) $customer->phone, $msg);
            $waStatus = $res['status'];
            $waLink   = $res['wa_link'] ?? null;
        }

        // ── Catat ke form_data ──
        try {
            $fd = $reg->form_data ?? [];
            $fd['payment_instructions'] = [
                'sent_at'  => now()->toDateTimeString(),
                'email'    => $emailStatus,
                'whatsapp' => $waStatus,
                'wa_link'  => $waLink,
                'pay_link' => $payLink,
            ];
            $reg->update(['form_data' => $fd]);
        } catch (Throwable $e) {
            report($e);
        }

        Log::info("Instruksi pembayaran NUP {$reg->nup_number} → email={$emailStatus} wa={$waStatus}");

        return ['email' => $emailStatus, 'whatsapp' => $waStatus, 'wa_link' => $waLink, 'pay_link' => $payLink];
    }

    /** Link bayar publik (invoice Xendit). Mode simulasi belum punya link publik. */
    private function payLink(NupRegistration $reg): ?string
    {
        if ($reg->payment_provider === 'xendit' && filled($reg->payment_url)) {
            return $reg->payment_url;
        }

        return null;
    }
}
