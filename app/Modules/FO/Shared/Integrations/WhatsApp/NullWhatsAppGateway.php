<?php

namespace App\Modules\FO\Shared\Integrations\WhatsApp;

use Illuminate\Support\Facades\Log;

/**
 * Fallback gateway saat kredensial Qontak belum dikonfigurasi. Tidak mengirim
 * apa pun — hanya mencatat ke log agar alur tetap jalan (mis. di dev/test).
 */
class NullWhatsAppGateway implements WhatsAppGatewayInterface
{
    public function sendTemplate(string $toNumber, string $toName, string $templateId, array $bodyParams = [], ?string $documentUrl = null, ?string $documentFilename = null): bool
    {
        Log::channel('single')->info('[WA stub] template tidak dikirim (Qontak belum dikonfigurasi)', [
            'to' => $toNumber,
            'name' => $toName,
            'template' => $templateId,
            'params' => $bodyParams,
            'document' => $documentUrl ? ($documentFilename.' <'.$documentUrl.'>') : null,
        ]);

        return false;
    }
}
