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

    /**
     * Yang dipakai bila Template Pesan belum mengatur jenis ini — SATU sumber untuk job pengirim
     * (WA) dan halaman Template Pesan (supaya admin melihat teks yang sedang berjalan).
     *  - wa_config : kunci config ID template Qontak dari .env (null = tidak ada → WA dilewati)
     *  - wa_kunci  : urutan isian {{1}}, {{2}}, …
     *  - email_*   : padanan kata-kunci dari email bawaan (emails/nup-confirmation,
     *                emails/kwitansi-utj, kwitansi BKM) — ubah bersama view-nya.
     *
     * @return array{wa_config:?string, wa_kunci:list<string>, email_subjek:string, email_isi:string}
     */
    public static function bawaan(string $jenis): array
    {
        $ttd = '<p>Hormat Kami,<br><strong>{nama_perusahaan}</strong></p>';

        return match ($jenis) {
            self::TAGIHAN_UTJ => [
                'wa_config' => 'services.qontak.template_utj',
                'wa_kunci' => ['nama', 'nama_acara', 'nominal_utj', 'link_bayar'],
                'email_subjek' => 'Pembayaran UTJ NUP — {nama}',
                'email_isi' => '<p>Halo <strong>{nama}</strong>,</p>'
                    .'<p>Terima kasih telah mendaftar. Data Anda akan <strong>diverifikasi oleh tim kami</strong> terlebih dahulu.</p>'
                    .'<p>Untuk mengamankan pendaftaran, mohon lakukan pembayaran <strong>Uang Tanda Jadi (UTJ)</strong> berikut:</p>'
                    .'<ul><li>Nominal UTJ: <strong>{nominal_utj}</strong></li><li>Event: {nama_acara}</li><li>Tanggal launching: {tgl_launching}</li></ul>'
                    .'<p>Bayar UTJ melalui tautan ini: <a href="{link_bayar}">{link_bayar}</a></p>'
                    .'<p><strong>Nomor urut NUP</strong> akan diterbitkan setelah pembayaran terverifikasi dan data Anda disetujui tim kami.</p>',
            ],
            self::KONFIRMASI_NUP => [
                'wa_config' => 'services.qontak.template_konfirmasi',
                'wa_kunci' => ['nama', 'nama_acara', 'no_nup', 'no_hp', 'nik', 'sales'],
                'email_subjek' => 'Nomor Urut NUP Anda — {no_nup}',
                'email_isi' => '<p>Kepada Yth.<br>Bapak/Ibu <strong>{nama}</strong></p>'
                    .'<p>Kami mengucapkan Selamat kepada Bapak/Ibu telah menjadi calon pemesan atas unit properti di <strong>{nama_acara}</strong> dengan :</p>'
                    .'<ul><li>No Urut NUP: <strong>{no_nup}</strong></li><li>Nama Calon Pemesan: {nama}</li><li>No Telepon/HP: {no_hp}</li><li>NIK: {nik}</li><li>Sales/Agen: {sales}</li></ul>'
                    .'<p>Terimakasih atas kepercayaan Bapak/Ibu telah memilih Java Residence sebagai hunian terbaik untuk Bapak/Ibu Sekeluarga.</p>'
                    .'<p>Hormat Kami,<br>Direktur<br><strong>{nama_perusahaan}</strong></p>'
                    .'<p><em>Note: Harap membawa Dokumen terlampir ini dan kwitansi pada saat acara pilih unit {nama_acara}.</em></p>'
                    .'<p>Lihat / Unduh SPRS + Kwitansi: <a href="{link_sprs}">{link_sprs}</a></p>',
            ],
            self::KWITANSI_UTJ => [
                'wa_config' => 'services.qontak.template_utj_paid',
                'wa_kunci' => ['nama', 'nominal_angka', 'nama_acara'],
                'email_subjek' => 'Pembayaran UTJ Diterima — {nama}',
                'email_isi' => '<p>Kepada Yth.<br>Bapak/Ibu <strong>{nama}</strong></p>'
                    .'<p>Pembayaran UTJ sebesar <strong>{nominal_utj}</strong> untuk NUP <strong>{nama_acara}</strong> telah kami terima.</p>'
                    .'<p>Kwitansi terlampir pada email ini.</p><p>Terima kasih atas kepercayaan Bapak/Ibu.</p>'.$ttd,
            ],
            default => [ // KWITANSI_BAYAR — tak punya ID template .env
                'wa_config' => null,
                'wa_kunci' => ['nama', 'no_kwitansi', 'nominal_angka', 'unit'],
                'email_subjek' => 'Kwitansi Pembayaran {no_kwitansi}',
                'email_isi' => '<p>Yth. {nama},</p><p>Terima kasih, pembayaran {jenis_pembayaran} sebesar <b>{nominal}</b> untuk unit {unit} telah kami terima.</p>',
            ],
        };
    }

    /** ID template WA bawaan dari .env untuk jenis ini ('' = tidak ada). */
    public static function waIdBawaan(string $jenis): string
    {
        $kunci = self::bawaan($jenis)['wa_config'];

        return $kunci ? trim((string) config($kunci)) : '';
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
