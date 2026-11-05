<?php

namespace App\Modules\FO\Approval\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Klien server-ke-server ke BO Approval Inbox (`/api/v1/fo-bridge/approval-inbox`) & Dashboard atas nama
 * user FO, untuk app approver (PWA). Ditandatangani HMAC-SHA256 dengan secret SSO
 * (config ipro.sso_secret) — pasangannya middleware VerifyFoBridge di BO, format kanonik harus sama.
 * Semua aturan (siapa boleh lihat/approve, efek approval) tetap di BO.
 */
class BoInboxBridge
{
    public const PREFIX = '/api/v1/fo-bridge/approval-inbox';

    public const DASHBOARD = '/api/v1/fo-bridge/dashboard';

    /** @return array{status:int, body:array<string,mixed>} */
    public function get(string $email, string $path = ''): array
    {
        return $this->kirim('GET', $email, self::PREFIX.$path);
    }

    /**
     * Dashboard BO sesuai izin dashboard.{grup}.view user (periode opsional, Y-m-d).
     *
     * @return array{status:int, body:array<string,mixed>}
     */
    public function dashboard(string $email, array $periode = []): array
    {
        $q = http_build_query(array_filter($periode));

        return $this->kirim('GET', $email, self::DASHBOARD, null, $q);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    public function post(string $email, string $path, array $data): array
    {
        return $this->kirim('POST', $email, self::PREFIX.$path, $data);
    }

    public static function tandaTangan(string $secret, string $ts, string $nonce, string $method, string $path, string $email, string $body): string
    {
        return hash_hmac('sha256', implode("\n", [$ts, $nonce, strtoupper($method), $path, $email, hash('sha256', $body)]), $secret);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function kirim(string $method, string $email, string $fullPath, ?array $data = null, string $query = ''): array
    {
        $secret = (string) config('ipro.sso_secret', '');
        if ($secret === '') {
            return ['status' => 503, 'body' => ['message' => 'Koneksi ke BO belum dikonfigurasi (SSO_SHARED_SECRET).']];
        }

        $body = $data === null ? '' : json_encode($data);
        $ts = (string) now()->timestamp;
        $nonce = (string) Str::uuid();

        try {
            $res = Http::timeout(20)
                ->acceptJson()
                ->withHeaders([
                    'X-Bridge-Email' => $email,
                    'X-Bridge-Ts' => $ts,
                    'X-Bridge-Nonce' => $nonce,
                    'X-Bridge-Sig' => self::tandaTangan($secret, $ts, $nonce, $method, $fullPath, $email, $body),
                ])
                ->withBody($body, 'application/json')
                ->send($method, config('ipro.bo_url').$fullPath.($query !== '' ? '?'.$query : ''));
        } catch (ConnectionException) {
            return ['status' => 503, 'body' => ['message' => 'Server BO tidak bisa dihubungi. Coba lagi sebentar lagi.']];
        }

        $json = $res->json();

        return [
            'status' => $res->status(),
            'body' => is_array($json) ? $json : ['message' => 'Respons BO tidak dikenali ('.$res->status().').'],
        ];
    }
}
