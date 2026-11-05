<?php

namespace App\Modules\FO\Approval\Services;

use App\Modules\FO\Approval\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Kirim satu notifikasi Web Push ke satu perangkat (kunci VAPID dari config/push.php).
 * Dipisah dari PushApprover supaya bisa dipalsukan di test.
 */
class PushPengirim
{
    private ?WebPush $webPush = null;

    public function siap(): bool
    {
        return filled(config('push.vapid.public_key')) && filled(config('push.vapid.private_key'));
    }

    /**
     * @param  array{title:string, body:string, url?:string, tag?:string}  $pesan
     * @return bool false bila langganan sudah kedaluwarsa / dicabut perangkat (baris boleh dihapus)
     */
    public function kirim(PushSubscription $s, array $pesan): bool
    {
        $laporan = $this->webPush()->sendOneNotification(
            Subscription::create(['endpoint' => $s->endpoint, 'keys' => ['p256dh' => $s->p256dh, 'auth' => $s->auth]]),
            (string) json_encode($pesan),
            ['TTL' => 3600, 'urgency' => 'high'],
        );

        if ($laporan->isSuccess()) {
            return true;
        }
        if ($laporan->isSubscriptionExpired()) {
            return false;
        }
        Log::warning('Push gagal: '.$laporan->getReason(), ['subscription' => $s->id]);

        return true;
    }

    private function webPush(): WebPush
    {
        return $this->webPush ??= new WebPush(['VAPID' => [
            'subject' => (string) config('push.vapid.subject'),
            'publicKey' => (string) config('push.vapid.public_key'),
            'privateKey' => (string) config('push.vapid.private_key'),
        ]]);
    }
}
