<?php

namespace App\Modules\FO\Approval\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
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

    /**
     * Isi satu dokumen approval (PDF/foto berkas, lembar memo, lampiran budget) apa adanya.
     *
     * @return array{status:int, isi:string, tipe:string, nama:?string}
     */
    public function dokumen(string $email, int $id, string $kunci): array
    {
        $res = $this->minta('GET', $email, self::PREFIX."/{$id}/dokumen/{$kunci}", null, '', 60);
        if (! is_array($res)) {
            return ['status' => $res->status(), 'isi' => $res->body(), 'tipe' => (string) ($res->header('Content-Type') ?: 'application/octet-stream'),
                'nama' => preg_match('/filename="([^"]+)"/', (string) $res->header('Content-Disposition'), $x) ? $x[1] : null];
        }

        return ['status' => $res['status'], 'isi' => (string) json_encode($res['body']), 'tipe' => 'application/json', 'nama' => null];
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function kirim(string $method, string $email, string $fullPath, ?array $data = null, string $query = ''): array
    {
        $res = $this->minta($method, $email, $fullPath, $data, $query);
        if (is_array($res)) {
            return $res;
        }
        $json = $res->json();

        return [
            'status' => $res->status(),
            'body' => is_array($json) ? $json : ['message' => 'Respons BO tidak dikenali ('.$res->status().').'],
        ];
    }

    /**
     * Kirim permintaan bertanda tangan; array = gagal sebelum ada respons BO (status + pesan).
     *
     * @return Response|array{status:int, body:array<string,mixed>}
     */
    private function minta(string $method, string $email, string $fullPath, ?array $data = null, string $query = '', int $timeout = 20)
    {
        $secret = (string) config('ipro.sso_secret', '');
        if ($secret === '') {
            return ['status' => 503, 'body' => ['message' => 'Koneksi ke BO belum dikonfigurasi (SSO_SHARED_SECRET).']];
        }

        $body = $data === null ? '' : json_encode($data);
        $ts = (string) now()->timestamp;
        $nonce = (string) Str::uuid();

        try {
            return Http::timeout($timeout)
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
    }
}
