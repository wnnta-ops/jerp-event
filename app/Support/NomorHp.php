<?php

namespace App\Support;

/**
 * Nomor HP Indonesia — satu tempat untuk membakukan, memeriksa, dan menampilkannya.
 *
 * Form publik NUP & Booked menampilkan awalan "+62" di sebelah isian, tetapi awalan itu
 * HIASAN: tidak pernah ikut tersimpan, dan isiannya teks bebas (min:8, max:32, tanpa cek
 * angka). Hasilnya nomor tersimpan dalam berbagai bentuk — 08…, 62…, +62…, bahkan
 * "+628517513991787662" yang panjangnya mustahil.
 *
 * Aturan pembakuannya sendiri sudah ada, tetapi terkubur sebagai method privat di
 * QontakWhatsAppGateway dan hanya dipakai SAAT MENGIRIM. Jadi nomor rusak baru ketahuan
 * ketika pesan gagal terkirim — setelah orangnya pulang. Di sini aturan itu dipakai di
 * hulu: saat diketik, bukan saat dipakai.
 *
 * Bentuk baku: 62 + nomor tanpa nol, mis. "628123456789". Sama dengan yang diminta gateway
 * WhatsApp, dan sesuai janji awalan "+62" di layar.
 */
class NomorHp
{
    /** Nomor HP Indonesia: 62 + '8' + 8..11 digit (total 11..14 digit). */
    private const POLA_BAKU = '/^628\d{8,11}$/';

    /**
     * Bakukan ke bentuk 62… — menerima 08…, 8…, 62…, +62…, berspasi, atau berstrip.
     * Mengembalikan null bila tidak ada angka sama sekali.
     */
    public static function baku(mixed $v): ?string
    {
        $angka = preg_replace('/\D+/', '', (string) ($v ?? '')) ?? '';
        if ($angka === '') {
            return null;
        }

        if (str_starts_with($angka, '0')) {
            return '62'.ltrim(substr($angka, 1), '0');
        }
        if (str_starts_with($angka, '62')) {
            return $angka;
        }
        if (str_starts_with($angka, '8')) {
            return '62'.$angka;
        }

        // Bukan pola Indonesia yang dikenal — dikembalikan apa adanya (angka saja) supaya
        // pemeriksaan di bawah yang menolaknya, bukan diam-diam diubah jadi nomor lain.
        return $angka;
    }

    /** Benar bila nomor sudah berbentuk HP Indonesia yang masuk akal. */
    public static function sah(mixed $v): bool
    {
        $baku = self::baku($v);

        return $baku !== null && preg_match(self::POLA_BAKU, $baku) === 1;
    }

    /**
     * Bagian SETELAH "+62" untuk isian yang sudah menampilkan awalan itu — "8123456789".
     * Tanpa ini, orang yang mengetik "08123456789" menghasilkan tampilan "+62 08123456789".
     */
    public static function tanpaAwalan(mixed $v): string
    {
        $baku = self::baku($v);

        return $baku !== null && str_starts_with($baku, '62') ? substr($baku, 2) : (string) ($baku ?? '');
    }

    /** Bentuk enak dibaca: "+62 812-3456-7890". Nomor tak sah dikembalikan apa adanya. */
    public static function tampil(mixed $v, string $kosong = '—'): string
    {
        $asli = trim((string) ($v ?? ''));
        if ($asli === '') {
            return $kosong;
        }
        if (! self::sah($asli)) {
            return $asli;
        }

        $sisa = self::tanpaAwalan($asli);          // mis. 8123456789
        $potong = array_filter([
            substr($sisa, 0, 3),
            substr($sisa, 3, 4),
            substr($sisa, 7),
        ], fn ($x) => $x !== '' && $x !== false);

        return '+62 '.implode('-', $potong);
    }
}
