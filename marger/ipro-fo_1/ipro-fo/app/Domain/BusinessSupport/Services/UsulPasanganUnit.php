<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

/**
 * MENGUSULKAN pasangan lokasi MUK ↔ unit kita dari blok & nomor rumahnya.
 *
 * Yang dipulangkan di sini TIDAK PERNAH tersimpan dengan sendirinya. Seluruhnya melewati
 * layar dan ditekan orang — karena pasangan inilah yang menentukan biaya SPK satu rumah
 * menempel ke rumah yang mana, dan salah pasang berarti rumah A dibayari pekerjaan rumah B
 * tanpa galat dan tanpa ada yang tahu.
 *
 * ── DUA PENULISAN YANG SAMA-SAMA BENAR ──
 *
 * MUK menulis rumah sebagai nama: "CENDANA JRKC2 VI No. 15" — blok dan nomornya ada di
 * dalam nama, kolom bloknya sendiri dikirim kosong (diperiksa 1 Okt 2026). Kita menulisnya
 * sebagai kode: "JRK3-004-XIX-17" — dua potongan terakhir adalah blok dan nomor. Keduanya
 * disempitkan ke satu kunci yang sama, lalu dicocokkan.
 *
 * ── YANG MENDUA TIDAK DIUSULKAN SAMA SEKALI ──
 *
 * Bila satu kunci dimiliki lebih dari satu unit, atau diperebutkan lebih dari satu lokasi,
 * TIDAK ADA yang diusulkan untuk kunci itu. Memilih salah satunya akan benar separuh waktu,
 * dan separuh lainnya memindahkan biaya ke rumah yang salah dengan rupa yang meyakinkan.
 * Operator lebih baik melihat kolomnya kosong dan memutuskan sendiri.
 */
class UsulPasanganUnit
{
    /**
     * Kunci pencocokan dari kode unit — dua potongan terakhir, mis. "JRK3-004-XIX-17" → "XIX|17".
     *
     * Potongan terakhir, bukan posisi tetap: kode tahap boleh ada ("JRK3-004-XIX-17") atau
     * tidak ("C-A-01"), dan memakai indeks tetap akan salah pada salah satunya.
     */
    public function kunciUnit(?string $unitCode): ?string
    {
        $bagian = array_values(array_filter(explode('-', (string) $unitCode), fn ($p) => trim($p) !== ''));

        if (count($bagian) < 2) {
            return null;
        }

        return $this->kunci($bagian[count($bagian) - 2], $bagian[count($bagian) - 1]);
    }

    /** Kunci pencocokan dari blok & nomor rumah — lihat BsMukLokasi::tebakBlokNomor(). */
    public function kunciLokasi(?string $blok, ?string $noRumah): ?string
    {
        return $this->kunci($blok, $noRumah);
    }

    /**
     * Usulan pasangan: lokasi_kode → unit_id.
     *
     * @param  array<int, array{lokasi_kode: string, blok: ?string, no_rumah: ?string}>  $lokasi
     * @param  array<int, array{id: int, unit_code: ?string}>  $unit
     * @return array<string, int>
     */
    public function pasangkan(array $lokasi, array $unit): array
    {
        // Kunci yang dimiliki lebih dari satu unit dibuang lebih dulu. Itu bisa terjadi
        // ketika penyaring project mencakup dua tahap yang bernomor rumah sama.
        $perKunci = [];
        foreach ($unit as $u) {
            $k = $this->kunciUnit($u['unit_code'] ?? null);
            if ($k !== null) {
                $perKunci[$k][] = (int) $u['id'];
            }
        }

        $usul = [];
        $rebutan = [];
        foreach ($lokasi as $l) {
            $k = $this->kunciLokasi($l['blok'] ?? null, $l['no_rumah'] ?? null);
            if ($k === null || count($perKunci[$k] ?? []) !== 1) {
                continue;
            }

            // Kunci yang sama disebut dua lokasi: keduanya dilepas, bukan yang pertama
            // dimenangkan. Urutan baris bukan bukti mana yang benar.
            if (isset($usul[$k])) {
                $rebutan[$k] = true;

                continue;
            }

            $usul[$k] = ['lokasi_kode' => (string) $l['lokasi_kode'], 'unit_id' => $perKunci[$k][0]];
        }

        $hasil = [];
        foreach ($usul as $k => $u) {
            if (! isset($rebutan[$k])) {
                $hasil[$u['lokasi_kode']] = $u['unit_id'];
            }
        }

        return $hasil;
    }

    /**
     * Blok & nomor disempitkan ke "BLOK|NOMOR".
     *
     * Huruf dibesarkan dan pemisah dibuang ("No. 15" vs "15"), dan NOL DI DEPAN dihapus:
     * kami menulis "05" sementara MUK menulis "5", dan tanpa ini separuh rumah tidak pernah
     * terusulkan. Akhiran huruf dipertahankan — "15A" adalah rumah lain dari "15".
     */
    private function kunci(?string $blok, ?string $noRumah): ?string
    {
        $b = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim((string) $blok)));
        $n = preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim((string) $noRumah)));

        if ($b === '' || $n === '') {
            return null;
        }

        $n = preg_replace('/^0+(?=\d)/', '', $n);

        return $b.'|'.$n;
    }
}
