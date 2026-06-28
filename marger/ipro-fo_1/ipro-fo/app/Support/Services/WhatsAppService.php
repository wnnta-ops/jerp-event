<?php

declare(strict_types=1);

namespace App\Support\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pengiriman pesan WhatsApp.
 *
 * Mode:
 *  - REAL: aktif bila WA_BUSINESS_API_TOKEN + WA_BUSINESS_PHONE_ID terisi
 *    → memanggil Meta WhatsApp Business Cloud API (POST {base}/{phone_id}/messages).
 *  - SIMULASI: bila kredensial kosong → pesan dicatat ke log dan dikembalikan
 *    tautan wa.me agar admin bisa mengirim manual.
 */
final class WhatsAppService
{
    public function enabled(): bool
    {
        return filled(config('services.whatsapp.token')) && filled(config('services.whatsapp.phone_id'));
    }

    /** Normalisasi nomor ke format internasional tanpa tanda (mis. 08123 → 628123). */
    public function normalize(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone) ?? '';

        if ($d === '') {
            return '';
        }
        if (str_starts_with($d, '620')) {
            return '62'.ltrim(substr($d, 2), '0');
        }
        if (str_starts_with($d, '0')) {
            return '62'.substr($d, 1);
        }
        if (! str_starts_with($d, '62')) {
            return '62'.$d;
        }

        return $d;
    }

    public function waMeLink(string $phone, string $message): string
    {
        return 'https://wa.me/'.$this->normalize($phone).'?text='.rawurlencode($message);
    }

    /**
     * Kirim pesan teks WhatsApp.
     *
     * @return array{provider:string,status:string,to:string,wa_link:string,error?:mixed}
     */
    public function send(string $phone, string $message): array
    {
        $to   = $this->normalize($phone);
        $link = 'https://wa.me/'.$to.'?text='.rawurlencode($message);

        if ($to === '') {
            return ['provider' => 'none', 'status' => 'skipped', 'to' => '', 'wa_link' => $link];
        }

        if (! $this->enabled()) {
            Log::info("[WA simulasi → {$to}] {$message}");

            return ['provider' => 'simulation', 'status' => 'simulated', 'to' => $to, 'wa_link' => $link];
        }

        try {
            $resp = Http::withToken((string) config('services.whatsapp.token'))
                ->acceptJson()
                ->timeout(20)
                ->post(
                    rtrim((string) config('services.whatsapp.base_url'), '/').'/'.config('services.whatsapp.phone_id').'/messages',
                    [
                        'messaging_product' => 'whatsapp',
                        'to'                => $to,
                        'type'              => 'text',
                        'text'              => ['preview_url' => true, 'body' => $message],
                    ],
                );

            if ($resp->failed()) {
                Log::error('WhatsApp gagal', ['status' => $resp->status(), 'body' => $resp->json()]);

                return ['provider' => 'wa_business', 'status' => 'failed', 'to' => $to, 'wa_link' => $link, 'error' => $resp->status()];
            }

            return ['provider' => 'wa_business', 'status' => 'sent', 'to' => $to, 'wa_link' => $link];
        } catch (Throwable $e) {
            report($e);

            return ['provider' => 'wa_business', 'status' => 'failed', 'to' => $to, 'wa_link' => $link, 'error' => $e->getMessage()];
        }
    }
}
