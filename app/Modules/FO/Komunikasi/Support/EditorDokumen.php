<?php

namespace App\Modules\FO\Komunikasi\Support;

/**
 * Editor visual Template Dokumen: SPR/SPRS dirender dengan template cetak yang SAMA, tetapi
 * teks aturannya dibungkus span yang bisa diedit langsung (contenteditable). Data transaksi
 * (customer, unit, harga) tetap data contoh dan tidak bisa diedit.
 */
final class EditorDokumen
{
    /** Tag yang boleh ada di teks dokumen — sama dengan yang lolos saat dicetak. */
    public const TAG = '<b><i><u><br><strong><em>';

    /**
     * Buang semua tag kecuali b/i/u/br/strong/em, LALU buang atribut tag yang tersisa —
     * strip_tags membiarkan atribut (mis. <b onmouseover=…>), jadi tanpa langkah kedua
     * teks template bisa menyisipkan skrip ke halaman cetak.
     */
    public static function aman(string $html): string
    {
        return (string) preg_replace('/<(\/?)(strong|em|br|b|i|u)\b[^>]*>/i', '<$1$2>', strip_tags($html, self::TAG));
    }

    /** Span teks yang bisa diedit. $polos = teks satu baris tanpa format (nama, kota, …). */
    public static function kolom(string $kunci, string $nilai, bool $polos = false): string
    {
        return '<span class="ed" contenteditable="true" spellcheck="false" data-k="'.e($kunci).'"'
            .($polos ? ' data-polos="1"' : '').'>'
            .($polos ? e($nilai) : self::aman($nilai))
            .'</span>';
    }

    /**
     * Deretan tombol kecil untuk satu butir daftar (pasal / butir / syarat).
     *
     * @param  array<int,array{0:string,1:string,2:string}>  $tombol  [op, label, judul]
     */
    public static function kendali(string $daftar, int $i, array $tombol): string
    {
        return '<span class="ctl" contenteditable="false">'.collect($tombol)->map(fn ($t) => '<button type="button" data-op="'.e($t[0]).'" data-list="'.e($daftar).'" data-i="'.$i.'" title="'.e($t[2]).'">'.e($t[1]).'</button>')->implode('').'</span>';
    }

    /**
     * Isian kiriman editor → bentuk isi yang disimpan: hanya kunci yang dikenal dokumen itu,
     * teks dibersihkan dari tag selain b/i/u/br.
     *
     * @return array<string,mixed>
     */
    public static function bersihkan(string $dokumen, array $isi): array
    {
        $bawaan = DokumenBawaan::untuk($dokumen);
        $teks = fn ($v) => trim((string) preg_replace('/(<br\s*\/?>\s*)+$/i', '', self::aman(str_replace('&nbsp;', ' ', (string) $v))));
        $daftar = fn ($xs) => array_values(array_filter(array_map($teks, (array) $xs), fn ($v) => $v !== ''));

        $hasil = [];
        foreach ($bawaan as $kunci => $awal) {
            if ($kunci === 'pasal') {
                $hasil['pasal'] = array_values(array_map(fn ($p) => [
                    'judul' => $teks($p['judul'] ?? ''),
                    'isi' => $teks($p['isi'] ?? ''),
                    'butir' => $daftar($p['butir'] ?? []),
                    'halaman_baru' => (bool) ($p['halaman_baru'] ?? false),
                ], array_filter((array) ($isi['pasal'] ?? []), 'is_array')));
            } elseif ($kunci === 'syarat') {
                $hasil['syarat'] = $daftar($isi['syarat'] ?? []);
            } else {
                $hasil[$kunci] = array_key_exists($kunci, $isi) ? $teks($isi[$kunci]) : $awal;
            }
        }

        return $hasil;
    }
}
