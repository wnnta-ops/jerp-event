<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Klien API MUK ERP — kontrak owner, progres per lokasi, dan COP Owner.
 *
 * API-nya BACA-SAJA (hanya GET); tidak ada satu pun jalur tulis ke sisi mereka.
 *
 * Kuncinya dikirim lewat header, TIDAK PERNAH lewat URL — URL tercatat di log akses,
 * riwayat peramban, dan header Referer. Dokumen MUK menegaskan hal yang sama.
 *
 * Galat dipetakan ke pesan berbahasa Indonesia yang menyebut APA yang harus dilakukan,
 * karena yang membacanya orang Finance, bukan pengembang. Kode HTTP mentah seperti "403"
 * tidak memberi tahu siapa pun bahwa yang perlu dihubungi adalah tim MUK.
 */
class MukApi
{
    /** Batas laju MUK: 120 permintaan/menit. Penarikan massal diberi jeda agar tak kena 429. */
    private const JEDA_HALAMAN_DETIK = 1;

    public function aktif(): bool
    {
        return (bool) config('services.muk.aktif') && trim((string) config('services.muk.key')) !== '';
    }

    /** Cek kunci & sambungan. @return array{ok:bool, pesan:string} */
    public function ping(): array
    {
        try {
            $d = $this->ambil('/ping');

            return ['ok' => (bool) ($d['ok'] ?? false), 'pesan' => 'Sambungan ke MUK berhasil.'];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'pesan' => $e->getMessage()];
        }
    }

    /**
     * Seluruh kontrak owner. $sejak (ISO) membatasi ke yang berubah saja — dipakai penarikan
     * berkala supaya tidak menyeret 101 kontrak tiap kali.
     *
     * @return array<int, array<string, mixed>>
     */
    public function kontrak(?string $sejak = null): array
    {
        $keluar = [];
        $halaman = 1;

        do {
            $q = ['per_halaman' => 200, 'halaman' => $halaman];
            if ($sejak !== null && $sejak !== '') {
                $q['diperbarui_sejak'] = $sejak;
            }

            $d = $this->ambil('/contracts', $q, true);
            $keluar = array_merge($keluar, $d['data'] ?? []);
            $terakhir = (int) ($d['meta']['halaman_terakhir'] ?? 1);

            // Batas 50 halaman = 10.000 kontrak. Bukan angka keramat, hanya penjaga supaya
            // meta yang keliru dari sisi mereka tidak membuat perintah ini berputar selamanya.
            if ($halaman >= $terakhir || $halaman >= 50) {
                break;
            }

            $halaman++;
            sleep(self::JEDA_HALAMAN_DETIK);
        } while (true);

        return $keluar;
    }

    /**
     * COP Owner (tagihan) satu kontrak.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tagihan(int $mukId, ?string $sdTanggal = null): array
    {
        $q = $sdTanggal !== null && $sdTanggal !== '' ? ['sd_tanggal' => $sdTanggal] : [];
        $d = $this->ambil('/contracts/'.$mukId.'/bills', $q);

        return $d['tagihan'] ?? [];
    }

    /**
     * Daftar lokasi (unit) sebuah kontrak.
     *
     * Terpisah dari progres() karena endpoint-nya memang berbeda, dan sempat berbeda nasib:
     * 19 Sep 2026 keduanya memulangkan lokasi tanpa identitas, lalu 1 Okt 2026 keduanya
     * dibetulkan tim MUK. Sejak itu lokasi_id, kode, nama, tipe_rumah, dan ukuran terisi —
     * sementara blok dan no_rumah MASIH kosong, dan hanya terbaca dari namanya.
     *
     * @return array<string, mixed>
     */
    public function lokasi(int $mukId): array
    {
        return $this->ambil('/contracts/'.$mukId.'/locations');
    }

    /**
     * Progres per lokasi.
     *
     * RIWAYAT: 19 Sep 2026 endpoint ini memulangkan opname 0 dan lokasi tanpa identitas pada
     * 15 kontrak yang di /contracts jelas punya opname sampai Rp 11,8 M. Dilaporkan ke tim
     * MUK dan DIBETULKAN per 1 Okt 2026 — sejak itu opname, ditagih, belum_ditagih, retensi,
     * dan identitas lokasinya terisi. Yang masih kosong hanya kolom blok & no_rumah; isinya
     * ada di dalam `nama` ("CENDANA JRKC2 VI No. 15") dan diurai di BsMukLokasi.
     *
     * @return array<string, mixed>
     */
    public function progres(int $mukId, ?string $sdTanggal = null): array
    {
        $q = $sdTanggal !== null && $sdTanggal !== '' ? ['sd_tanggal' => $sdTanggal] : [];

        return $this->ambil('/contracts/'.$mukId.'/progress', $q);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function ambil(string $path, array $query = [], bool $utuh = false): array
    {
        if (! $this->aktif()) {
            throw new RuntimeException(
                'Integrasi MUK belum aktif. Isi MUK_API_KEY di .env server, lalu jalankan ulang.'
            );
        }

        try {
            $r = Http::withHeaders([
                'X-API-Key' => (string) config('services.muk.key'),
                'Accept' => 'application/json',
            ])->timeout((int) config('services.muk.timeout', 20))
                ->get(rtrim((string) config('services.muk.url'), '/').$path, $query);
        } catch (Throwable $e) {
            throw new RuntimeException('Tidak bisa menghubungi server MUK: '.$e->getMessage());
        }

        if ($r->successful()) {
            $json = $r->json();

            return $utuh ? (array) $json : (array) ($json['data'] ?? []);
        }

        throw new RuntimeException($this->pesanGalat($r->status(), (array) $r->json()));
    }

    /** @param array<string, mixed> $json */
    private function pesanGalat(int $status, array $json): string
    {
        $kode = (string) ($json['error']['code'] ?? '');
        $pesan = (string) ($json['error']['message'] ?? '');

        return match (true) {
            $status === 401 => 'Kunci API MUK ditolak. Periksa MUK_API_KEY di .env server.',
            $status === 403 && $kode === 'ip_not_allowed' => 'IP server ini di luar daftar yang diizinkan MUK. Kabari tim MUK.',
            $status === 404 => 'Kontrak tidak ditemukan di MUK.',
            $status === 422 => 'Parameter ditolak MUK'.($pesan !== '' ? ': '.$pesan : '.'),
            $status === 429 => 'Terlalu sering memanggil MUK (batas 120/menit). Tunggu sebentar lalu ulangi.',
            $status === 503 => 'Integrasi sedang dimatikan di sisi MUK. Kabari tim MUK.',
            default => 'MUK menjawab galat '.$status.($pesan !== '' ? ': '.$pesan : '.'),
        };
    }
}
