<?php

namespace App\Modules\FO\Shared\Integrations\WhatsApp;

interface WhatsAppGatewayInterface
{
    /**
     * Kirim pesan WhatsApp berbasis template.
     *
     * @param  string  $toNumber  Nomor tujuan (akan dinormalkan ke format 62…).
     * @param  string  $toName  Nama penerima.
     * @param  string  $templateId  ID message template yang sudah di-approve.
     * @param  array<int,string>  $bodyParams  Nilai parameter body template, sesuai urutan.
     * @return bool true bila terkirim (atau di-skip dengan aman saat tidak terkonfigurasi).
     */
    public function sendTemplate(string $toNumber, string $toName, string $templateId, array $bodyParams = []): bool;
}
