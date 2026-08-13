<?php

declare(strict_types=1);

namespace App\Support\Barcode;

/**
 * Pembuat QR Code (Model 2, mode byte, tingkat koreksi M) sebagai SVG — tanpa pustaka luar.
 *
 * Dipakai untuk barcode tanda tangan pada SIP: bentuknya KOTAK, disematkan langsung ke HTML
 * (layar maupun cetak) sebagai SVG inline sehingga tidak ada berkas gambar yang perlu
 * disimpan dan hasil cetaknya tetap tajam di ukuran berapa pun.
 *
 * Versi 1–10 didukung (cukup untuk kode tanda tangan; v10 memuat 213 byte).
 */
final class QrCode
{
    /** Tingkat koreksi kesalahan M (~15%) — penanda bit 00. */
    private const EC_BITS = 0b00;

    /** [versi => [ec codeword per blok, blok grup-1, data/blok grup-1, blok grup-2, data/blok grup-2]] */
    private const EC_TABLE = [
        1 => [10, 1, 16, 0, 0],
        2 => [16, 1, 28, 0, 0],
        3 => [26, 1, 44, 0, 0],
        4 => [18, 2, 32, 0, 0],
        5 => [24, 2, 43, 0, 0],
        6 => [16, 4, 27, 0, 0],
        7 => [18, 4, 31, 0, 0],
        8 => [22, 2, 38, 2, 39],
        9 => [22, 3, 36, 2, 37],
        10 => [26, 4, 43, 1, 44],
    ];

    /** Titik tengah pola penyelaras per versi (v1 tidak punya). */
    private const ALIGN = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    /** Daya tampung mode byte (jumlah karakter) per versi pada tingkat M. */
    private const BYTE_CAPACITY = [
        1 => 14, 2 => 26, 3 => 42, 4 => 62, 5 => 84,
        6 => 106, 7 => 122, 8 => 152, 9 => 180, 10 => 213,
    ];

    /** @var array<int,int> */
    private static array $exp = [];

    /** @var array<int,int> */
    private static array $log = [];

    public static function isEncodable(string $value): bool
    {
        $len = strlen($value);

        return $len > 0 && $len <= self::BYTE_CAPACITY[10];
    }

    /** Versi terkecil yang memuat teks ini. */
    public static function versionFor(string $value): ?int
    {
        foreach (self::BYTE_CAPACITY as $v => $cap) {
            if (strlen($value) <= $cap) {
                return $v;
            }
        }

        return null;
    }

    /**
     * Matriks modul QR: true = hitam.
     *
     * @return array<int,array<int,bool>>|null null bila teks tidak dapat dimuat
     */
    public static function matrix(string $value): ?array
    {
        $version = self::versionFor($value);
        if ($version === null || $value === '') {
            return null;
        }

        $codewords = self::codewords($value, $version);
        $size = 17 + 4 * $version;

        // Matriks kerja + penanda modul fungsi (tidak boleh ditimpa data).
        $m = array_fill(0, $size, array_fill(0, $size, false));
        $fixed = array_fill(0, $size, array_fill(0, $size, false));

        self::placeFinders($m, $fixed, $size);
        self::placeTiming($m, $fixed, $size);
        self::placeAlignment($m, $fixed, $version, $size);

        // Modul gelap wajib + area format/versi dipesan lebih dulu.
        $m[4 * $version + 9][8] = true;
        $fixed[4 * $version + 9][8] = true;
        self::reserveFormat($fixed, $size);
        if ($version >= 7) {
            self::reserveVersion($fixed, $size);
        }

        self::placeData($m, $fixed, $codewords, $size);

        // Pilih mask dengan penalti terkecil (aturan baku QR).
        $best = null;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $cand = self::applyMask($m, $fixed, $mask, $size);
            self::writeFormat($cand, $mask, $size);
            if ($version >= 7) {
                self::writeVersion($cand, $version, $size);
            }
            $score = self::penalty($cand, $size);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $cand;
            }
        }

        return $best;
    }

    /**
     * SVG QR siap disematkan (inline, tanpa deklarasi XML).
     *
     * @param  int  $moduleSize  ukuran 1 modul dalam px
     * @param  int  $quiet       lebar zona tenang dalam modul (baku 4 — wajib agar terbaca pemindai)
     */
    public static function svg(string $value, int $moduleSize = 3, int $quiet = 4, bool $showText = false): string
    {
        $m = self::matrix($value);
        if ($m === null) {
            return '';
        }

        $size = count($m);
        $full = ($size + 2 * $quiet) * $moduleSize;
        $textH = $showText ? 13 : 0;

        $rects = '';
        foreach ($m as $y => $row) {
            $x = 0;
            while ($x < $size) {
                if (! $row[$x]) { $x++; continue; }
                // Modul hitam berderet digabung jadi satu <rect> — SVG jauh lebih ringkas.
                $run = 1;
                while ($x + $run < $size && $row[$x + $run]) { $run++; }
                $rects .= '<rect x="'.(($quiet + $x) * $moduleSize).'" y="'.(($quiet + $y) * $moduleSize)
                    .'" width="'.($run * $moduleSize).'" height="'.$moduleSize.'"/>';
                $x += $run;
            }
        }

        $label = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $text = $showText
            ? '<text x="'.($full / 2).'" y="'.($full + $textH - 3).'" text-anchor="middle" font-family="monospace" font-size="10" fill="currentColor">'.$label.'</text>'
            : '';

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$full.' '.($full + $textH).'"'
            .' width="'.$full.'" height="'.($full + $textH).'" role="img" aria-label="QR '.$label.'">'
            .'<rect width="'.$full.'" height="'.($full + $textH).'" fill="#fff"/>'
            .'<g fill="currentColor">'.$rects.'</g>'.$text.'</svg>';
    }

    // ── Penyusunan data ───────────────────────────────────────────────────

    /**
     * Deretan codeword akhir (data + koreksi kesalahan, sudah di-interleave).
     *
     * @return array<int,int>
     */
    private static function codewords(string $value, int $version): array
    {
        [$ecPerBlock, $g1, $d1, $g2, $d2] = self::EC_TABLE[$version];
        $totalData = $g1 * $d1 + $g2 * $d2;

        // Bit: mode byte (0100) + jumlah karakter + isi.
        $bits = '0100';
        $countBits = $version <= 9 ? 8 : 16;
        $bits .= str_pad(decbin(strlen($value)), $countBits, '0', STR_PAD_LEFT);
        foreach (str_split($value) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }

        // Terminator maksimal 4 bit, lalu ratakan ke kelipatan 8.
        $bits .= str_repeat('0', min(4, $totalData * 8 - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);

        $data = [];
        foreach (str_split($bits, 8) as $byte) {
            $data[] = bindec($byte);
        }
        // Isian sisa: 0xEC / 0x11 bergantian (baku).
        $pad = [0xEC, 0x11];
        $i = 0;
        while (count($data) < $totalData) {
            $data[] = $pad[$i++ % 2];
        }

        // Pecah ke blok, hitung koreksi kesalahan tiap blok.
        $blocks = [];
        $ecs = [];
        $p = 0;
        foreach ([[$g1, $d1], [$g2, $d2]] as [$cnt, $len]) {
            for ($b = 0; $b < $cnt; $b++) {
                $blk = array_slice($data, $p, $len);
                $p += $len;
                $blocks[] = $blk;
                $ecs[] = self::reedSolomon($blk, $ecPerBlock);
            }
        }

        // Interleave: kolom demi kolom, data dulu lalu koreksi kesalahan.
        $out = [];
        $maxData = max(array_map('count', $blocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as $blk) {
                if (isset($blk[$i])) {
                    $out[] = $blk[$i];
                }
            }
        }
        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecs as $ec) {
                $out[] = $ec[$i];
            }
        }

        return $out;
    }

    /** Tabel logaritma GF(256), polinomial primitif 0x11D. */
    private static function initGf(): void
    {
        if (self::$exp !== []) {
            return;
        }
        $x = 1;
        for ($i = 0; $i < 256; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 256; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return self::$exp[(self::$log[$a] + self::$log[$b]) % 255];
    }

    /**
     * Codeword koreksi kesalahan Reed–Solomon.
     *
     * @param  array<int,int>  $data
     * @return array<int,int>
     */
    public static function reedSolomon(array $data, int $ecLen): array
    {
        self::initGf();

        // Polinomial generator: Π (x − α^i)
        $gen = [1];
        for ($i = 0; $i < $ecLen; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $c) {
                $next[$j] ^= $c;
                $next[$j + 1] ^= self::gfMul($c, self::$exp[$i]);
            }
            $gen = $next;
        }

        $rem = array_merge($data, array_fill(0, $ecLen, 0));
        for ($i = 0; $i < count($data); $i++) {
            $factor = $rem[$i];
            if ($factor === 0) {
                continue;
            }
            foreach ($gen as $j => $c) {
                $rem[$i + $j] ^= self::gfMul($c, $factor);
            }
        }

        return array_slice($rem, count($data), $ecLen);
    }

    // ── Penyusunan matriks ────────────────────────────────────────────────

    /**
     * @param  array<int,array<int,bool>>  $m
     * @param  array<int,array<int,bool>>  $fixed
     */
    private static function placeFinders(array &$m, array &$fixed, int $size): void
    {
        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$cx, $cy]) {
            for ($y = -1; $y <= 7; $y++) {
                for ($x = -1; $x <= 7; $x++) {
                    $px = $cx + $x;
                    $py = $cy + $y;
                    if ($px < 0 || $py < 0 || $px >= $size || $py >= $size) {
                        continue;
                    }
                    $on = ($x >= 0 && $x <= 6 && ($y === 0 || $y === 6))
                        || ($y >= 0 && $y <= 6 && ($x === 0 || $x === 6))
                        || ($x >= 2 && $x <= 4 && $y >= 2 && $y <= 4);
                    $m[$py][$px] = $on;
                    $fixed[$py][$px] = true;
                }
            }
        }
    }

    /**
     * @param  array<int,array<int,bool>>  $m
     * @param  array<int,array<int,bool>>  $fixed
     */
    private static function placeTiming(array &$m, array &$fixed, int $size): void
    {
        for ($i = 8; $i < $size - 8; $i++) {
            $on = $i % 2 === 0;
            $m[6][$i] = $on;
            $fixed[6][$i] = true;
            $m[$i][6] = $on;
            $fixed[$i][6] = true;
        }
    }

    /**
     * @param  array<int,array<int,bool>>  $m
     * @param  array<int,array<int,bool>>  $fixed
     */
    private static function placeAlignment(array &$m, array &$fixed, int $version, int $size): void
    {
        $pos = self::ALIGN[$version];
        foreach ($pos as $cy) {
            foreach ($pos as $cx) {
                // Lewati yang bertabrakan dengan pola pencari.
                if (($cx === 6 && $cy === 6) || ($cx === 6 && $cy === $size - 7) || ($cx === $size - 7 && $cy === 6)) {
                    continue;
                }
                for ($y = -2; $y <= 2; $y++) {
                    for ($x = -2; $x <= 2; $x++) {
                        $m[$cy + $y][$cx + $x] = max(abs($x), abs($y)) !== 1;
                        $fixed[$cy + $y][$cx + $x] = true;
                    }
                }
            }
        }
    }

    /** @param array<int,array<int,bool>> $fixed */
    private static function reserveFormat(array &$fixed, int $size): void
    {
        for ($i = 0; $i < 9; $i++) {
            $fixed[8][$i] = true;
            $fixed[$i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $fixed[8][$size - 1 - $i] = true;
            $fixed[$size - 1 - $i][8] = true;
        }
    }

    /** @param array<int,array<int,bool>> $fixed */
    private static function reserveVersion(array &$fixed, int $size): void
    {
        for ($i = 0; $i < 6; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $fixed[$i][$size - 11 + $j] = true;
                $fixed[$size - 11 + $j][$i] = true;
            }
        }
    }

    /**
     * Penempatan data zig-zag dari kanan-bawah ke atas.
     *
     * @param  array<int,array<int,bool>>  $m
     * @param  array<int,array<int,bool>>  $fixed
     * @param  array<int,int>  $codewords
     */
    private static function placeData(array &$m, array $fixed, array $codewords, int $size): void
    {
        $bits = '';
        foreach ($codewords as $cw) {
            $bits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        }
        $len = strlen($bits);
        $i = 0;
        $up = true;

        for ($right = $size - 1; $right > 0; $right -= 2) {
            if ($right === 6) {
                $right = 5;   // kolom timing dilewati
            }
            for ($v = 0; $v < $size; $v++) {
                $y = $up ? $size - 1 - $v : $v;
                foreach ([0, 1] as $dx) {
                    $x = $right - $dx;
                    if ($fixed[$y][$x]) {
                        continue;
                    }
                    $m[$y][$x] = $i < $len && $bits[$i] === '1';
                    $i++;
                }
            }
            $up = ! $up;
        }
    }

    /**
     * @param  array<int,array<int,bool>>  $m
     * @param  array<int,array<int,bool>>  $fixed
     * @return array<int,array<int,bool>>
     */
    private static function applyMask(array $m, array $fixed, int $mask, int $size): array
    {
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($fixed[$y][$x]) {
                    continue;
                }
                $m[$y][$x] = $m[$y][$x] !== self::maskBit($mask, $x, $y);
            }
        }

        return $m;
    }

    private static function maskBit(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
            5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
            6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
            default => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
        };
    }

    /** Informasi format (tingkat EC + mask) — BCH(15,5), di-XOR 0x5412. @param array<int,array<int,bool>> $m */
    private static function writeFormat(array &$m, int $mask, int $size): void
    {
        $data = (self::EC_BITS << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 9) & 1) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;

        for ($i = 0; $i < 15; $i++) {
            $on = (($bits >> $i) & 1) === 1;
            // Salinan 1 — sekitar pola pencari kiri-atas.
            if ($i < 6) {
                $m[$i][8] = $on;
            } elseif ($i === 6) {
                $m[7][8] = $on;
            } elseif ($i === 7) {
                $m[8][8] = $on;
            } elseif ($i === 8) {
                $m[8][7] = $on;
            } else {
                $m[8][14 - $i] = $on;
            }
            // Salinan 2 — kanan-atas & kiri-bawah.
            if ($i < 8) {
                $m[8][$size - 1 - $i] = $on;
            } else {
                $m[$size - 15 + $i][8] = $on;
            }
        }
    }

    /** Informasi versi (v7+) — BCH(18,6). @param array<int,array<int,bool>> $m */
    private static function writeVersion(array &$m, int $version, int $size): void
    {
        $rem = $version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 11) & 1) * 0x1F25);
        }
        $bits = ($version << 12) | $rem;

        for ($i = 0; $i < 18; $i++) {
            $on = (($bits >> $i) & 1) === 1;
            $a = intdiv($i, 3);
            $b = $size - 11 + $i % 3;
            $m[$a][$b] = $on;
            $m[$b][$a] = $on;
        }
    }

    /** Skor penalti pemilihan mask (aturan N1–N4). @param array<int,array<int,bool>> $m */
    private static function penalty(array $m, int $size): int
    {
        $score = 0;

        // N1 — deret ≥5 modul sewarna (baris & kolom).
        foreach ([true, false] as $isRow) {
            for ($a = 0; $a < $size; $a++) {
                $run = 1;
                for ($b = 1; $b < $size; $b++) {
                    $cur = $isRow ? $m[$a][$b] : $m[$b][$a];
                    $prev = $isRow ? $m[$a][$b - 1] : $m[$b - 1][$a];
                    if ($cur === $prev) {
                        $run++;
                    } else {
                        if ($run >= 5) { $score += 3 + ($run - 5); }
                        $run = 1;
                    }
                }
                if ($run >= 5) { $score += 3 + ($run - 5); }
            }
        }

        // N2 — blok 2×2 sewarna.
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $v = $m[$y][$x];
                if ($v === $m[$y][$x + 1] && $v === $m[$y + 1][$x] && $v === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }

        // N3 — pola 1:1:3:1:1 yang mirip pola pencari.
        $pat1 = [true, false, true, true, true, false, true, false, false, false, false];
        $pat2 = array_reverse($pat1);
        foreach ([true, false] as $isRow) {
            for ($a = 0; $a < $size; $a++) {
                for ($b = 0; $b + 11 <= $size; $b++) {
                    $seq = [];
                    for ($k = 0; $k < 11; $k++) {
                        $seq[] = $isRow ? $m[$a][$b + $k] : $m[$b + $k][$a];
                    }
                    if ($seq === $pat1 || $seq === $pat2) {
                        $score += 40;
                    }
                }
            }
        }

        // N4 — ketimpangan proporsi hitam/putih.
        $dark = 0;
        foreach ($m as $row) {
            foreach ($row as $v) {
                if ($v) { $dark++; }
            }
        }
        $pct = (int) (100 * $dark / ($size * $size));
        $score += 10 * (int) floor(abs($pct - 50) / 5);

        return $score;
    }
}
