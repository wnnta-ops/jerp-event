<?php

declare(strict_types=1);

namespace App\Support\Barcode;

/**
 * Pembuat barcode Code 128-B sebagai SVG — tanpa pustaka pihak ketiga.
 *
 * Dipakai untuk tanda tangan barcode pada SIP: barcode disematkan langsung ke HTML (layar
 * maupun cetak) sebagai SVG inline, jadi tidak ada berkas gambar yang perlu disimpan dan
 * hasil cetaknya tetap tajam di ukuran berapa pun.
 *
 * Code 128-B dipilih karena memuat seluruh karakter ASCII tercetak (spasi s/d '~'), cukup
 * untuk kode tanda tangan berupa huruf, angka, titik, dan strip.
 */
final class Code128
{
    /**
     * Lebar modul tiap pola, berurutan bar-spasi-bar-spasi-bar-spasi.
     * Indeks 0–102 = nilai data, 103–105 = Start A/B/C, 106 = Stop.
     * Semua pola berjumlah 11 modul kecuali Stop yang 13 modul.
     */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312',
        '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222',
        '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131',
        '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321',
        '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121',
        '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321',
        '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224',
        '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114',
        '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112',
        '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113',
        '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412',
        '211214', '211232', '2331112',
    ];

    private const START_B = 104;
    private const STOP = 106;

    /** Karakter yang boleh dipakai pada kode barcode (ASCII tercetak Code 128-B). */
    public static function isEncodable(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        foreach (self::bytes($value) as $b) {
            if ($b < 32 || $b > 126) {
                return false;
            }
        }

        return true;
    }

    /**
     * Deretan lebar modul (bar, spasi, bar, …) untuk sebuah nilai.
     *
     * @return array<int,int>
     */
    public static function modules(string $value): array
    {
        $codes = [self::START_B];
        foreach (self::bytes($value) as $b) {
            $codes[] = $b - 32;   // Code B: nilai = ASCII − 32
        }

        // Checksum tertimbang: Start + Σ(posisi × nilai), modulo 103.
        $sum = self::START_B;
        foreach (array_slice($codes, 1) as $i => $c) {
            $sum += ($i + 1) * $c;
        }
        $codes[] = $sum % 103;
        $codes[] = self::STOP;

        $widths = [];
        foreach ($codes as $c) {
            foreach (str_split(self::PATTERNS[$c]) as $w) {
                $widths[] = (int) $w;
            }
        }

        return $widths;
    }

    /**
     * SVG barcode siap disematkan (inline, tanpa deklarasi XML).
     *
     * @param  int  $moduleWidth  lebar 1 modul dalam satuan px
     * @param  int  $height       tinggi batang dalam px
     * @param  bool $showText     cetak kodenya di bawah barcode
     */
    public static function svg(string $value, int $moduleWidth = 2, int $height = 40, bool $showText = true): string
    {
        if (! self::isEncodable($value)) {
            return '';
        }

        $widths = self::modules($value);
        $total = array_sum($widths) * $moduleWidth;
        $textH = $showText ? 13 : 0;
        $svgH = $height + $textH;

        $bars = '';
        $x = 0;
        foreach ($widths as $i => $w) {
            $w *= $moduleWidth;
            // Indeks genap = bar (hitam), ganjil = spasi.
            if ($i % 2 === 0) {
                $bars .= '<rect x="'.$x.'" y="0" width="'.$w.'" height="'.$height.'" fill="currentColor"/>';
            }
            $x += $w;
        }

        $text = '';
        if ($showText) {
            $label = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            $text = '<text x="'.($total / 2).'" y="'.($svgH - 2).'" text-anchor="middle"'
                .' font-family="monospace" font-size="10" letter-spacing="1" fill="currentColor">'.$label.'</text>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$total.' '.$svgH.'"'
            .' width="'.$total.'" height="'.$svgH.'" role="img" aria-label="Barcode '.htmlspecialchars($value, ENT_QUOTES, 'UTF-8').'">'
            .$bars.$text.'</svg>';
    }

    /** @return array<int,int> */
    private static function bytes(string $value): array
    {
        return array_map('ord', str_split($value));
    }
}
