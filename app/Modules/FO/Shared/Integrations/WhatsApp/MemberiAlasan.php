<?php

namespace App\Modules\FO\Shared\Integrations\WhatsApp;

/** Gateway yang bisa menjelaskan kenapa pengiriman terakhirnya gagal/dilewati (Monitoring Pesan). */
interface MemberiAlasan
{
    public function alasanTerakhir(): ?string;
}
