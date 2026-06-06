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

    public function sendTemplate(string $toNumber, string $toName, string $templateId, array $bodyParams = []): bool
    {
        $to = $this->normalizeNumber($toNumber);
        if ($to === '' || $templateId === '' || $this->channelIntegrationId === '') {
            return false;
        }

        $params = [];
        foreach (array_values($bodyParams) as $i => $value) {
            $key = (string) ($i + 1);
            $params[] = ['key' => $key, 'value' => $key, 'value_text' => (string) $value];
        }

        $response = Http::withToken($this->accessToken)
            ->acceptJson()
            ->post($this->baseUrl.'/broadcasts/whatsapp/direct', [
                'to_number' => $to,
                'to_name' => $toName !== '' ? $toName : $to,
                'message_template_id' => $templateId,
                'channel_integration_id' => $this->channelIntegrationId,
                'language' => ['code' => $this->languageCode],
                'parameters' => ['body' => $params],
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
