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
     * @param  string|null  $documentUrl  URL PDF publik utk header dokumen — template WAJIB dibuat dengan Header: Document.
     * @param  string|null  $documentFilename  Nama file yang tampil di WA (mis. "SPRS-NUP-00001.pdf").
     * @return bool true bila terkirim (atau di-skip dengan aman saat tidak terkonfigurasi).
     */
    public function sendTemplate(string $toNumber, string $toName, string $templateId, array $bodyParams = [], ?string $documentUrl = null, ?string $documentFilename = null): bool;
}
