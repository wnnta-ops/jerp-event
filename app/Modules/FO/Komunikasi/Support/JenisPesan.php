<?php

namespace App\Modules\FO\Komunikasi\Support;

/**
 * Jenis pesan yang teksnya diatur di Template Pesan, beserta kata kunci {…} yang tersedia.
 * Surat tagihan/SP TIDAK di sini — teksnya tetap di Collection → Setup SP (hanya tercatat
 * di Monitoring Pesan).
 */
final class JenisPesan
{
    public const TAGIHAN_UTJ = 'TAGIHAN_UTJ';

    public const KONFIRMASI_NUP = 'KONFIRMASI_NUP';

    public const KWITANSI_UTJ = 'KWITANSI_UTJ';

    public const KWITANSI_BAYAR = 'KWITANSI_BAYAR';

    /** @return array<string,string> */
    public static function pilihan(): array
    {
        return [
            self::TAGIHAN_UTJ => 'Tagihan UTJ (setelah daftar NUP)',
            self::KONFIRMASI_NUP => 'Konfirmasi NUP (NUP disetujui + SPRS)',
            self::KWITANSI_UTJ => 'Kwitansi UTJ (UTJ diterima)',
            self::KWITANSI_BAYAR => 'Kwitansi Pembayaran (DP / angsuran / pelunasan)',
        ];
    }

    /** Label untuk jenis apa pun, termasuk jenis Collection (SP1, REMINDER, …). */
    public static function label(?string $jenis): string
    {
        return self::pilihan()[$jenis] ?? match ($jenis) {
            'REMINDER' => 'Reminder Jatuh Tempo',
            'SP1' => 'Surat Peringatan 1',
            'SP2' => 'Surat Peringatan 2',
            'SP3' => 'Surat Peringatan 3',
            default => (string) $jenis,
        };
    }

    /** Kata kunci yang bisa dipakai di subjek/isi email & isian parameter WA. @return array<string,string> */
    public static function kataKunci(string $jenis): array
    {
        $umum = [
            'nama' => 'Nama customer',
            'no_hp' => 'No. HP customer',
            'email' => 'Email customer',
            'cluster' => 'Nama cluster',
            'nama_perusahaan' => 'Nama perusahaan',
            'tanggal' => 'Tanggal hari ini',
        ];

        $nup = [
            'no_nup' => 'Nomor NUP',
            'nama_acara' => 'Nama acara / event',
            'tgl_launching' => 'Tanggal launching',
            'nominal_utj' => 'Nominal UTJ (Rp …)',
            'nominal_angka' => 'Nominal UTJ tanpa "Rp"',
            'link_bayar' => 'Link pembayaran Xendit',
            'nik' => 'NIK',
            'sales' => 'Nama sales / agen',
            'link_sprs' => 'Link SPRS',
        ];

        $bayar = [
            'no_kwitansi' => 'Nomor kwitansi',
            'no_kontrak' => 'Nomor kontrak (SPR)',
            'unit' => 'Kode unit',
            'jenis_pembayaran' => 'Jenis pembayaran (DP/Angsuran/…)',
            'nominal' => 'Nominal (Rp …)',
            'nominal_angka' => 'Nominal tanpa "Rp"',
            'tgl_bayar' => 'Tanggal pembayaran',
            'sisa_tagihan' => 'Sisa tagihan kontrak (Rp …)',
        ];

        return $umum + ($jenis === self::KWITANSI_BAYAR ? $bayar : $nup);
    }

    /** Ganti {kunci} di $teks dengan nilainya. $html=true → nilai di-escape (teks sendiri tidak). */
    public static function isi(?string $teks, array $nilai, bool $html = false): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($nilai, $html) {
            if (! array_key_exists($m[1], $nilai)) {
                return $m[0];
            }
            $v = (string) $nilai[$m[1]];

            return $html ? e($v) : $v;
        }, (string) $teks);
    }
}
