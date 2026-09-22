<?php

declare(strict_types=1);

namespace App\Modules\BO\IproBridge\Support;

use Illuminate\Http\Request;

/**
 * Tanda tangan permintaan modul Legal (app/legal, Next.js) → API FO.
 *
 * Legal berjalan sebagai container tersendiri tanpa sesi Laravel, jadi setiap
 * panggilan ditandatangani HMAC dengan kunci bersama (config ipro.legal_bridge_secret).
 * Pesan yang ditandatangani:
 *
 *     <timestamp> \n <path> \n <sha256 hex dari body mentah>
 *
 * Tiga bagian itu dipilih dengan alasan masing-masing:
 *  - timestamp → menutup pengulangan (replay) permintaan lama; umur maks 5 menit.
 *  - path      → tanda tangan untuk /ajb tidak bisa dipindah ke /ajb/lepas.
 *  - hash body → isi permintaan tidak bisa diubah di tengah jalan.
 *
 * QUERY STRING SENGAJA TIDAK IKUT: semua endpoint jembatan ini POST + body JSON,
 * supaya tidak ada perbedaan penulisan persen-encoding antara Node, nginx, dan
 * PHP yang bisa membuat tanda tangan sah ditolak.
 */
final class LegalBridgeSignature
{
    /** Umur maksimum sebuah permintaan (detik). */
    public const UMUR_MAKS = 300;

    public const HEADER_TS = 'X-Legal-Timestamp';

    public const HEADER_SIG = 'X-Legal-Signature';

    /** Kunci bersama, atau null bila belum dikonfigurasi (endpoint fail-closed). */
    public static function kunci(): ?string
    {
        $k = config('ipro.legal_bridge_secret');

        return is_string($k) && $k !== '' ? $k : null;
    }

    /** Pesan kanonik — HARUS sama persis dengan sisi Legal (lib/jerp-bridge.ts). */
    public static function pesan(string $timestamp, string $path, string $body): string
    {
        return $timestamp."\n".$path."\n".hash('sha256', $body);
    }

    public static function tandai(string $kunci, string $timestamp, string $path, string $body): string
    {
        return hash_hmac('sha256', self::pesan($timestamp, $path, $body), $kunci);
    }

    /**
     * Periksa sebuah permintaan masuk.
     *
     * @return string|null null bila sah; selain itu alasan penolakan (untuk log/pesan).
     */
    public static function periksa(Request $request): ?string
    {
        $kunci = self::kunci();
        if ($kunci === null) {
            return 'Jembatan Legal belum dikonfigurasi (secret kosong).';
        }

        $ts = (string) $request->header(self::HEADER_TS, '');
        $sig = (string) $request->header(self::HEADER_SIG, '');
        if ($ts === '' || $sig === '') {
            return 'Tanda tangan permintaan tidak lengkap.';
        }
        if (! ctype_digit($ts)) {
            return 'Stempel waktu tidak valid.';
        }
        if (abs(time() - (int) $ts) > self::UMUR_MAKS) {
            return 'Permintaan kedaluwarsa.';
        }

        // getPathInfo() = path TANPA query & tanpa base path — persis yang
        // dibentuk sisi Legal. getRequestUri() akan ikut membawa query string.
        $harap = self::tandai($kunci, $ts, $request->getPathInfo(), $request->getContent());

        return hash_equals($harap, $sig) ? null : 'Tanda tangan tidak cocok.';
    }
}
