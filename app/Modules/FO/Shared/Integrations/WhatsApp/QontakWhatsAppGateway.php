<?php

namespace App\Modules\FO\Shared\Integrations\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mekari Qontak WhatsApp gateway — Broadcast WhatsApp Direct API.
 *
 * @see https://docs.qontak.com/docs/omnichannel-hub/ (Send Interactive/Template Message)
 *
 * Auth: Bearer access token. Pesan dikirim per nomor via template yang sudah
 * di-approve WhatsApp. Parameter body dipetakan berurutan ({{1}}, {{2}}, …).
 */
class QontakWhatsAppGateway implements WhatsAppGatewayInterface
{
    public function __construct(
        private readonly string $accessToken,
        private readonly string $channelIntegrationId,
        private readonly string $baseUrl = 'https://service-chat.qontak.com/api/open/v1',
        private readonly string $languageCode = 'id',
    ) {}

    public function sendTemplate(string $toNumber, string $toName, string $templateId, array $bodyParams = [], ?string $documentUrl = null, ?string $documentFilename = null): bool
    {
        $to = $this->normalizeNumber($toNumber);
        // Dulu di sini hanya `return false` — WA tak terkirim TANPA jejak apa pun,
        // sehingga penyebabnya (env template/channel kosong) mustahil dilacak.
        // Sekarang alasannya dicatat eksplisit.
        $kurang = [];
        if ($to === '') {
            $kurang[] = 'nomor tujuan kosong/tidak valid ('.$toNumber.')';
        }
        if ($templateId === '') {
            $kurang[] = 'message_template_id kosong — cek QONTAK_TEMPLATE_UTJ / QONTAK_TEMPLATE_NUP_APPROVAL di .env';
        }
        if ($this->channelIntegrationId === '') {
            $kurang[] = 'channel_integration_id kosong — cek QONTAK_CHANNEL_INTEGRATION_ID di .env';
        }
        if ($kurang !== []) {
            Log::channel('single')->warning('[Qontak WA] pesan DILEWATI', ['sebab' => $kurang]);

            return false;
        }

        $params = [];
        foreach (array_values($bodyParams) as $i => $value) {
            $key = (string) ($i + 1);
            // 'value' = NAMA variabel internal Qontak — WAJIB 2-16 char
            // (angka/huruf kecil/underscore). '1' saja ditolak 422 → pakai 'p1'.
            $params[] = ['key' => $key, 'value' => 'p'.$key, 'value_text' => (string) $value];
        }

        $parameters = ['body' => $params];
        // Header dokumen (PDF) — hanya utk template yang dibuat dengan Header: Document.
        // URL harus publik (Meta/Qontak mengunduhnya saat mengirim).
        if ($documentUrl !== null && $documentUrl !== '') {
            $parameters['header'] = [
                'format' => 'DOCUMENT',
                'params' => [
                    ['key' => 'url', 'value' => $documentUrl],
                    ['key' => 'filename', 'value' => $documentFilename ?: 'dokumen.pdf'],
                ],
            ];
        }

        $response = Http::withToken($this->accessToken)
            ->acceptJson()
            ->post($this->baseUrl.'/broadcasts/whatsapp/direct', [
                'to_number' => $to,
                'to_name' => $toName !== '' ? $toName : $to,
                'message_template_id' => $templateId,
                'channel_integration_id' => $this->channelIntegrationId,
                'language' => ['code' => $this->languageCode],
                'parameters' => $parameters,
            ]);

        if ($response->failed()) {
            Log::channel('single')->error('[Qontak WA] gagal kirim', [
                'to' => $to,
                'template' => $templateId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Normalkan nomor ke format internasional tanpa "+": 08… → 628…, +62… → 62….
     */
    private function normalizeNumber(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($digits, '0')) {
            return '62'.substr($digits, 1);
        }
        if (str_starts_with($digits, '62')) {
            return $digits;
        }
        if (str_starts_with($digits, '8')) {
            return '62'.$digits;
        }

        return $digits;
    }
}
