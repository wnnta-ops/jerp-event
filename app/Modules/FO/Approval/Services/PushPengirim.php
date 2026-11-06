<?php

namespace App\Modules\FO\Approval\Services;

use App\Modules\FO\Approval\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Kirim satu notifikasi ke satu perangkat: Web Push (PWA, kunci VAPID) atau FCM (APK Android,
 * endpoint "fcm:<token>"). Dipisah dari PushApprover supaya bisa dipalsukan di test.
 */
class PushPengirim
{
    private ?WebPush $webPush = null;

    public function __construct(private FcmPengirim $fcm) {}

    /** Ada saluran yang siap: Web Push (VAPID) atau FCM. */
    public function siap(): bool
    {
        return $this->siapWeb() || $this->fcm->siap();
    }

    public function siapWeb(): bool
    {
        return filled(config('push.vapid.public_key')) && filled(config('push.vapid.private_key'));
    }

    public function siapUntuk(PushSubscription $s): bool
    {
        return $s->fcm() ? $this->fcm->siap() : $this->siapWeb();
    }

    /**
     * @param  array{title:string, body:string, url?:string, tag?:string}  $pesan
     * @return bool false bila langganan sudah kedaluwarsa / dicabut perangkat (baris boleh dihapus)
     */
    public function kirim(PushSubscription $s, array $pesan): bool
    {
        if (! $this->siapUntuk($s)) {
            return true; // salurannya belum dikonfigurasi di server — langganan tetap disimpan
        }
        if ($s->fcm()) {
            return $this->fcm->kirim($s->fcm(), $pesan);
        }
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
