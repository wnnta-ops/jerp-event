<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Klien API sistem Komplain/Garansi — unit (beserta data BAST) dan komplain.
 *
 * Baca-saja. Kunci dikirim lewat header X-API-KEY, tidak pernah lewat URL.
 *
 * User-Agent DIISI eksplisit: server mereka memulangkan 403 untuk User-Agent bawaan klien
 * HTTP tertentu (Python-urllib kena saat pengujian). Guzzle punya User-Agent sendiri, tapi
 * menyebut diri secara jelas membuat log mereka lebih mudah dibaca bila nanti perlu
 * ditelusuri bersama.
 */
class KomplainApi
{
    /** Batas laju mereka: 120 permintaan/menit. */
    private const JEDA_HALAMAN_DETIK = 1;

    public function aktif(): bool
    {
        return (bool) config('services.komplain.aktif')
            && trim((string) config('services.komplain.key')) !== '';
    }

    /** @return array{ok:bool, pesan:string} */
    public function ping(): array
    {
        try {
            $this->ambil('/units', ['per_page' => 1]);

            return ['ok' => true, 'pesan' => 'Sambungan ke sistem Komplain berhasil.'];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'pesan' => $e->getMessage()];
        }
    }

    /**
     * Seluruh unit. $sejak (ISO8601) membatasi ke yang berubah saja — dipakai penarikan
     * berkala supaya tidak menyeret 1464 unit tiap kali.
     *
     * @return array<int, array<string, mixed>>
     */
    public function unit(?string $sejak = null): array
    {
        $keluar = [];
        $halaman = 1;

        do {
            $q = ['per_page' => 100, 'page' => $halaman];
            if ($sejak !== null && $sejak !== '') {
                $q['updated_since'] = $sejak;
            }

            $d = $this->ambil('/units', $q, true);
            $keluar = array_merge($keluar, $d['data'] ?? []);
            $terakhir = (int) (data_get($d, 'meta.pagination.last_page') ?? 1);

            // Batas 60 halaman = 6.000 unit. Penjaga agar meta yang keliru dari sisi mereka
            // tidak membuat penarikan berputar selamanya.
            if ($halaman >= $terakhir || $halaman >= 60) {
                break;
            }

            $halaman++;
            sleep(self::JEDA_HALAMAN_DETIK);
        } while (true);

        return $keluar;
    }

    /**
     * Stempel waktu server mereka dari jawaban terakhir — dipakai sebagai titik mulai
     * penarikan berikutnya, sesuai anjuran dokumen mereka. Memakai jam SERVER KITA berisiko
     * melewatkan perubahan bila kedua jam berbeda beberapa detik.
     */
    public function waktuServer(): ?string
    {
        try {
            $d = $this->ambil('/units', ['per_page' => 1], true);

            return data_get($d, 'meta.server_time');
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function ambil(string $path, array $query = [], bool $utuh = false): array
    {
        if (! $this->aktif()) {
            throw new RuntimeException(
                'Integrasi sistem Komplain belum aktif. Isi CM_API_KEY di .env server, lalu jalankan ulang.'
            );
        }

        try {
            $r = Http::withHeaders([
                'X-API-KEY' => (string) config('services.komplain.key'),
                'Accept' => 'application/json',
                'User-Agent' => 'jerp-bo/1.0 (integrasi BAST)',
            ])->timeout((int) config('services.komplain.timeout', 20))
                ->get(rtrim((string) config('services.komplain.url'), '/').$path, $query);
        } catch (Throwable $e) {
            throw new RuntimeException('Tidak bisa menghubungi sistem Komplain: '.$e->getMessage());
        }

        if ($r->successful()) {
            $json = $r->json();

            return $utuh ? (array) $json : (array) ($json['data'] ?? []);
        }

        throw new RuntimeException($this->pesanGalat($r->status()));
    }

    private function pesanGalat(int $status): string
    {
        return match ($status) {
            401 => 'Kunci API sistem Komplain ditolak. Periksa CM_API_KEY di .env server.',
            403 => 'Permintaan ditolak sistem Komplain (403). Kabari tim mereka.',
            422 => 'Parameter ditolak sistem Komplain.',
            429 => 'Terlalu sering memanggil (batas 120/menit). Tunggu sebentar lalu ulangi.',
            503 => 'Kunci belum dipasang di server mereka. Kabari tim Komplain.',
            default => 'Sistem Komplain menjawab galat '.$status.'.',
        };
    }
}
