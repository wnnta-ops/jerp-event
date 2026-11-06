<?php

namespace App\Modules\FO\Approval\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Kirim notifikasi ke APK Android lewat Firebase Cloud Messaging (HTTP v1). Kredensial = file
 * service account Firebase (config push.fcm.*); token OAuth-nya dibuat dari JWT dan di-cache.
 */
class FcmPengirim
{
    public function siap(): bool
    {
        return $this->akun() !== null;
    }

    /**
     * @param  array{title:string, body:string, url?:string, tag?:string}  $pesan
     * @return bool false bila token perangkat sudah tidak berlaku (baris boleh dihapus)
     */
    public function kirim(string $token, array $pesan): bool
    {
        $akun = $this->akun() ?? throw new RuntimeException('FCM belum dikonfigurasi.');
        $res = Http::withToken($this->aksesToken($akun))->timeout(10)
            ->post("https://fcm.googleapis.com/v1/projects/{$akun['project_id']}/messages:send", ['message' => [
                'token' => $token,
                'notification' => ['title' => $pesan['title'], 'body' => $pesan['body']],
                'data' => array_map('strval', array_filter(['url' => $pesan['url'] ?? null, 'tag' => $pesan['tag'] ?? null])),
                'android' => ['priority' => 'high', 'notification' => array_filter(['tag' => $pesan['tag'] ?? null])],
            ]]);

        if ($res->successful()) {
            return true;
        }
        $status = $res->json('error.status');
        if ($res->status() === 404 || $status === 'UNREGISTERED' || ($status === 'INVALID_ARGUMENT' && str_contains((string) $res->body(), 'registration token'))) {
            return false;
        }
        Log::warning('FCM gagal: '.$res->status().' '.$status);

        return true;
    }

    /** @return array{project_id:string, client_email:string, private_key:string, token_uri:string}|null */
    private function akun(): ?array
    {
        $b64 = config('push.fcm.credentials_b64');
        $path = config('push.fcm.credentials');
        $json = filled($b64) ? base64_decode((string) $b64, true) : (filled($path) && is_file($path) ? file_get_contents($path) : null);
        $a = $json ? json_decode((string) $json, true) : null;

        return is_array($a) && isset($a['project_id'], $a['client_email'], $a['private_key'])
            ? $a + ['token_uri' => 'https://oauth2.googleapis.com/token'] : null;
    }

    private function aksesToken(array $akun): string
    {
        return Cache::remember('fcm-token:'.md5($akun['client_email']), now()->addMinutes(50), function () use ($akun) {
            $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $kini = time();
            $isi = $b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64((string) json_encode([
                'iss' => $akun['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $akun['token_uri'], 'iat' => $kini, 'exp' => $kini + 3600,
            ]));
            openssl_sign($isi, $ttd, $akun['private_key'], OPENSSL_ALGO_SHA256) || throw new RuntimeException('Kunci FCM tidak valid.');
            $res = Http::asForm()->timeout(10)->post($akun['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $isi.'.'.$b64($ttd),
            ])->throw();

            return (string) $res->json('access_token');
        });
    }
}
