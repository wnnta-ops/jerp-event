<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * OCR dokumen identitas (KTP/NPWP) — LOKAL/OFFLINE, file tidak keluar server.
 *
 * Urutan engine:
 *   1. PaddleOCR microservice (FastAPI di 127.0.0.1:8001) — paling akurat.
 *   2. Tesseract LOKAL (fallback bila service mati).
 * Bila keduanya gagal → read() mengembalikan null → pemanggil fallback ke data customer.
 *
 * PaddleOCR: jalankan microservice di C:\Users\Public\java-ocr (runtime\python.exe -m uvicorn app:app
 *   --host 127.0.0.1 --port 8001). Set OCR_PADDLE_URL di .env bila port/host berbeda.
 * Tesseract (Windows): winget install --id UB-Mannheim.TesseractOCR
 *   (opsional set OCR_TESSERACT_BIN di .env bila path non-standar).
 */
class IdDocumentOcr
{
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'bmp', 'tif', 'tiff'];

    /** Base URL microservice PaddleOCR (tanpa trailing slash). */
    public function serviceUrl(): string
    {
        return rtrim((string) (config('services.paddleocr.url') ?: 'http://127.0.0.1:8001'), '/');
    }

    /** Cek microservice PaddleOCR hidup (GET /health, cepat). */
    public function serviceAvailable(): bool
    {
        try {
            return Http::connectTimeout(2)->timeout(4)->get($this->serviceUrl().'/health')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * OCR via microservice PaddleOCR (POST /ocr multipart). @return ?string teks mentah, null bila gagal.
     * Connection-refused (service mati) gagal cepat → pemanggil fallback ke Tesseract.
     */
    public function readViaService(string $absPath, string $ext): ?string
    {
        if (! in_array(strtolower($ext), self::IMAGE_EXT, true) || ! is_file($absPath)) {
            return null;
        }
        try {
            $resp = Http::connectTimeout(3)->timeout(120)
                ->attach('image', (string) file_get_contents($absPath), basename($absPath))
                ->post($this->serviceUrl().'/ocr');
            if (! $resp->successful()) {
                return null;
            }
            $t = trim((string) $resp->json('text'));

            return $t !== '' ? $t : null;
        } catch (\Throwable) {
            return null; // service mati / timeout → fallback Tesseract
        }
    }

    /** Lokasi biner tesseract, atau null bila tidak ditemukan. */
    public function binary(): ?string
    {
        $candidates = array_values(array_filter([
            config('services.tesseract.bin'),                        // .env OCR_TESSERACT_BIN (aman thd config:cache)
            env('OCR_TESSERACT_BIN'),
            'C:/Users/SPV ACC MUP/AppData/Local/Programs/Tesseract-OCR/tesseract.exe', // path terdeteksi di mesin ini (pasti)
            getenv('LOCALAPPDATA') ? getenv('LOCALAPPDATA').'\\Programs\\Tesseract-OCR\\tesseract.exe' : null,
            getenv('USERPROFILE') ? getenv('USERPROFILE').'\\AppData\\Local\\Programs\\Tesseract-OCR\\tesseract.exe' : null,
            'tesseract',
            'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
            'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
        ]));

        foreach ($candidates as $bin) {
            try {
                if (Process::timeout(10)->run([$bin, '--version'])->successful()) {
                    return $bin;
                }
            } catch (\Throwable) {
                // coba kandidat berikutnya
            }
        }

        return null;
    }

    /** Ada engine OCR? PaddleOCR service hidup ATAU Tesseract terpasang. */
    public function available(): bool
    {
        return $this->serviceAvailable() || $this->binary() !== null;
    }

    /** OCR sebuah file gambar → teks mentah, atau null bila gagal / non-gambar / engine tak ada. */
    public function read(string $absPath, string $ext): ?string
    {
        if (! in_array(strtolower($ext), self::IMAGE_EXT, true) || ! is_file($absPath)) {
            return null; // butuh gambar (PDF tidak didukung langsung)
        }
        @set_time_limit(180); // OCR bisa beberapa detik/run — cegah fatal "max execution time 30s"

        // 1) Coba PaddleOCR microservice (paling akurat).
        $svc = $this->readViaService($absPath, $ext);
        if ($svc !== null) {
            return $svc;
        }

        // 2) Fallback Tesseract lokal.
        $bin = $this->binary();
        if ($bin === null) {
            return null;
        }

        // Pra-pemrosesan (upscale + grayscale + kontras + sharpen) agar foto KTP/NPWP terbaca.
        $target = $this->preprocess($absPath);

        $best = '';
        foreach (['ind+eng', 'eng'] as $lang) {
            foreach (['6'] as $psm) {
                try {
                    $r = Process::timeout(60)->run([$bin, $target, 'stdout', '-l', $lang, '--psm', $psm, '--oem', '1']);
                    if ($r->successful()) {
                        $t = trim($r->output());
                        if (strlen($t) > strlen($best)) {
                            $best = $t;
                        }
                    }
                } catch (\Throwable) {
                    // coba kombinasi berikutnya
                }
            }
            if ($best !== '') {
                break; // sudah dapat teks dari bahasa ini (ind+eng gagal cepat bila 'ind' tak ada → lanjut eng)
            }
        }

        if ($target !== $absPath) {
            @unlink($target);
        }

        return $best !== '' ? $best : null;
    }

    /**
     * Baca DUA file (KTP & NPWP). Coba PaddleOCR microservice dulu (paling akurat); untuk dokumen
     * yang gagal via service, fallback ke Tesseract paralel. @return array{0:?string,1:?string} [ktpText, npwpText]
     */
    public function readPair(string $p1, string $e1, string $p2, string $e2): array
    {
        @set_time_limit(180);

        // 1) PaddleOCR microservice per-dokumen.
        $t1 = $this->readViaService($p1, $e1);
        $t2 = $this->readViaService($p2, $e2);
        if ($t1 !== null && $t2 !== null) {
            return [$t1, $t2];
        }

        // 2) Fallback Tesseract HANYA untuk dokumen yang gagal (path kosong → dilewati).
        [$f1, $f2] = $this->readPairTesseract(
            $t1 === null ? $p1 : '', $t1 === null ? $e1 : '',
            $t2 === null ? $p2 : '', $t2 === null ? $e2 : ''
        );

        return [$t1 ?? $f1, $t2 ?? $f2];
    }

    /**
     * OCR DUA file via Tesseract secara PARALEL (start/wait bersamaan) — wall-time ~separuh
     * (penting di server 1-thread). @return array{0:?string,1:?string}
     */
    private function readPairTesseract(string $p1, string $e1, string $p2, string $e2): array
    {
        $bin = $this->binary();
        if ($bin === null) {
            return [null, null];
        }
        @set_time_limit(180);

        $img1 = (in_array(strtolower($e1), self::IMAGE_EXT, true) && is_file($p1)) ? $this->preprocess($p1) : null;
        $img2 = (in_array(strtolower($e2), self::IMAGE_EXT, true) && is_file($p2)) ? $this->preprocess($p2) : null;

        $mk = static function (?string $img) use ($bin): ?SymfonyProcess {
            if ($img === null) {
                return null;
            }
            $p = new SymfonyProcess([$bin, $img, 'stdout', '-l', 'ind+eng', '--psm', '6', '--oem', '1']);
            $p->setTimeout(150);
            try {
                $p->start();
            } catch (\Throwable) {
                return null;
            }

            return $p;
        };
        $pr1 = $mk($img1);
        $pr2 = $mk($img2);

        $grab = static function (?SymfonyProcess $p): ?string {
            if ($p === null) {
                return null;
            }
            try {
                $p->wait();
                $t = trim($p->getOutput());

                return ($p->isSuccessful() && $t !== '') ? $t : null;
            } catch (\Throwable) {
                return null;
            }
        };
        $t1 = $grab($pr1);
        $t2 = $grab($pr2);

        if ($img1 && $img1 !== $p1) {
            @unlink($img1);
        }
        if ($img2 && $img2 !== $p2) {
            @unlink($img2);
        }

        return [$t1, $t2];
    }

    /**
     * Preprocessing GD agar foto KTP/NPWP terbaca: normalisasi ukuran (longer side ~1800px,
     * upscale dibatasi 4×) + grayscale + naikkan kontras + sharpen + Otsu (binarisasi adaptif).
     * Fallback ke file asli bila GD gagal.
     */
    private function preprocess(string $absPath): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $absPath;
        }
        try {
            $im = @imagecreatefromstring((string) @file_get_contents($absPath));
            if ($im === false) {
                return $absPath;
            }
            $w    = imagesx($im);
            $h    = imagesy($im);
            $long = max($w, $h);
            if ($long > 0) {
                $scale = max(0.25, min(3.0, 1400 / $long)); // target ~1400px (cepat utk OCR; upscale maks 3×)
                if (abs($scale - 1.0) > 0.01) {
                    $scaled = imagescale($im, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)), IMG_BICUBIC);
                    if ($scaled !== false) {
                        imagedestroy($im);
                        $im = $scaled;
                    }
                }
            }
            imagefilter($im, IMG_FILTER_GRAYSCALE);
            imagefilter($im, IMG_FILTER_CONTRAST, -15);                            // negatif = kontras NAIK
            @imageconvolution($im, [[0, -1, 0], [-1, 5, -1], [0, -1, 0]], 1, 0);   // sharpen (cepat, C-level)
            $this->otsu($im);                                                     // binarisasi adaptif (di ~1400px → cepat, bantu baca NIK)

            $out = tempnam(sys_get_temp_dir(), 'ocr').'.png';
            imagepng($im, $out);
            imagedestroy($im);

            return is_file($out) ? $out : $absPath;
        } catch (\Throwable) {
            return $absPath;
        }
    }

    /** Binarisasi Otsu (threshold optimal dari histogram). Dipakai pada gambar ~1400px → cepat. */
    private function otsu(\GdImage $im): void
    {
        $W = imagesx($im);
        $H = imagesy($im);
        $hist = array_fill(0, 256, 0);
        for ($y = 0; $y < $H; $y++) {
            for ($x = 0; $x < $W; $x++) {
                $hist[imagecolorat($im, $x, $y) & 0xFF]++;
            }
        }
        $total = $W * $H;
        $sum = 0;
        for ($t = 0; $t < 256; $t++) {
            $sum += $t * $hist[$t];
        }
        $sumB = 0;
        $wB = 0;
        $maxVar = 0.0;
        $thr = 127;
        for ($t = 0; $t < 256; $t++) {
            $wB += $hist[$t];
            if ($wB === 0) {
                continue;
            }
            $wF = $total - $wB;
            if ($wF === 0) {
                break;
            }
            $sumB += $t * $hist[$t];
            $mB = $sumB / $wB;
            $mF = ($sum - $sumB) / $wF;
            $between = $wB * $wF * ($mB - $mF) * ($mB - $mF);
            if ($between > $maxVar) {
                $maxVar = $between;
                $thr = $t;
            }
        }
        $black = imagecolorallocate($im, 0, 0, 0);
        $white = imagecolorallocate($im, 255, 255, 255);
        for ($y = 0; $y < $H; $y++) {
            for ($x = 0; $x < $W; $x++) {
                imagesetpixel($im, $x, $y, (imagecolorat($im, $x, $y) & 0xFF) < $thr ? $black : $white);
            }
        }
    }

    /** @return array{nik:?string,nama:?string,ttl:?string,alamat:?string} */
    public function parseKtp(?string $text): array
    {
        if (! $text) {
            return ['nik' => null, 'nama' => null, 'ttl' => null, 'alamat' => null];
        }
        $nik  = preg_match('/\b(\d{16})\b/', $text, $m) ? $m[1] : null;
        $nama = $this->afterLabel($text, 'Nama');

        // PaddleOCR sering menaruh label & nilai di baris TERPISAH ("Nama" lalu "SAEFUL HARIS").
        if ($nama === null || $nama === '') {
            $nama = $this->valueAfterLabelLine($text, 'Nama');
        }

        // Fallback: di KTP, NAMA biasanya baris tepat setelah baris NIK.
        if (($nama === null || $nama === '') && $nik !== null) {
            $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
            foreach ($lines as $i => $ln) {
                if (str_contains($ln, $nik)) {
                    for ($j = $i + 1, $n = count($lines); $j < $n; $j++) {
                        $cand    = trim($lines[$j]);
                        $letters = preg_replace('/[^A-Za-z]/', '', $cand) ?? '';
                        if (strlen($letters) >= 3 && ! preg_match('/\d/', $cand)) {
                            $nama = $cand;
                            break 2;
                        }
                    }
                }
            }
        }

        return [
            'nik'    => $nik,
            'nama'   => $this->cleanName($nama),
            'ttl'    => $this->afterLabel($text, 'Lahir') ?? $this->valueAfterLabelLine($text, '(?:Tempat|Tgl|Lahir)'),
            'alamat' => $this->afterLabel($text, 'Alamat') ?? $this->valueAfterLabelLine($text, '(?:Alamat|Alama)'),
        ];
    }

    /**
     * Ambil NILAI dari sebuah LABEL yang mungkin berada di baris terpisah (khas PaddleOCR
     * yang mengeluarkan tiap kotak teks sebagai baris sendiri). Coba nilai di baris yg sama
     * dulu, lalu nilai pada baris berikutnya (melewati baris yang merupakan label lain).
     */
    private function valueAfterLabelLine(string $text, string $labelRegex): ?string
    {
        $skip  = '/^(NIK|NAMA|TEMPAT|TGL|TANGGAL|LAHIR|JENIS|KELAMIN|GOL|DARAH|ALAMAT|ALAMA|RT|RW|KEL|DESA|KECAMATAN|AGAMA|STATUS|PERKAWINAN|PEKERJAAN|KEWARGANEGARAAN|BERLAKU|PROVINSI|KOTA|KABUPATEN)/i';
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $n     = count($lines);
        for ($i = 0; $i < $n; $i++) {
            $ln = trim($lines[$i]);
            if (! preg_match('/^'.$labelRegex.'/i', $ln)) {
                continue;
            }
            // a) nilai pada baris yang sama: "Nama : X"
            if (preg_match('/^'.$labelRegex.'\s*[:.\-]?\s*(.+)$/i', $ln, $m)) {
                $v = trim($m[1], " :.-\t");
                if (strlen((string) preg_replace('/[^A-Za-z0-9]/', '', $v)) >= 2) {
                    return $v;
                }
            }
            // b) label sendirian → nilai pada baris berikutnya (lewati baris label lain)
            for ($j = $i + 1; $j < $n; $j++) {
                $cand = trim($lines[$j]);
                if ($cand === '') {
                    continue;
                }
                if (preg_match($skip, $cand)) {
                    continue;
                }

                return $cand;
            }
        }

        return null;
    }

    /** @return array{no:?string,nik:?string} No. NPWP (15 digit/berformat) + NIK (16 digit) terpisah. */
    public function parseNpwp(?string $text): array
    {
        if (! $text) {
            return ['no' => null, 'nik' => null];
        }
        $nik = preg_match('/\b(\d{16})\b/', $text, $m) ? $m[1] : null; // NIK = 16 digit

        $no = null;
        if (preg_match('/(\d{2}\.\d{3}\.\d{3}\.\d-?\d{3}\.\d{3})/', $text, $m)) {
            $no = $m[1];                                                // No. NPWP berformat
        } elseif (preg_match('/NPWP\s*[:.\-]?\s*([0-9][0-9.\- ]{12,})/i', $text, $m)) {
            $d  = preg_replace('/\D/', '', $m[1]) ?? '';
            $no = strlen($d) >= 15 ? substr($d, 0, 15) : trim($m[1]);   // setelah label "NPWP"
        } elseif (preg_match('/\b(\d{15})\b/', $text, $m)) {
            $no = $m[1];                                                // 15 digit (bukan NIK 16)
        }

        return ['no' => $no, 'nik' => $nik];
    }

    /** Tebak baris NAMA dari teks OCR (untuk NPWP yg tak punya label "Nama"). */
    public function readName(?string $text): ?string
    {
        if (! $text) {
            return null;
        }
        $skip = '/^(NPWP|N\.?P\.?W\.?P|NIK|NAMA|ALAMAT|KTP|NO\b|KPP|TERDAFTAR|TANGGAL|TEMPAT|KEMENTERIAN|DIREKTORAT|JENDERAL|PAJAK|REPUBLIK|INDONESIA|PROVIN|PROVING|PROVE|JAWA|TIMUR|BARAT|UTARA|SELATAN|TENGAH|KOTA|KABUPATEN|KABIPATEN|GOL|RT|RW|KEL|KEC|AGAMA|STATUS|PEKERJAAN|KEWARGANEGARAAN|BERLAKU)/i';
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line    = ltrim(trim($line), "|[]{}()<>.,:;-—_= \"'");  // buang simbol/junk di depan
            $letters = preg_replace('/[^A-Za-z]/', '', $line) ?? '';
            if (strlen($letters) >= 4 && ! preg_match('/\d/', $line) && ! preg_match($skip, $line)) {
                return $this->cleanName($line);
            }
        }

        return null;
    }

    /** Ambil bagian nama yang bersih (huruf+spasi) dari teks OCR yang berantakan. */
    public function cleanName(?string $s): ?string
    {
        if ($s === null || $s === '') {
            return null;
        }
        if (preg_match('/[A-Za-z][A-Za-z .]{2,}/', $s, $m)) {
            $n = trim($m[0], ' .');

            return $n !== '' ? $n : null;
        }

        return null;
    }

    /** Cocokkan nama customer dengan teks OCR (toleran error: ≥ separuh kata ditemukan). */
    public function nameMatches(string $fullName, ?string $text): bool
    {
        if (! $text) {
            return false;
        }
        $norm   = static fn ($s) => strtoupper(preg_replace('/\s+/', ' ', trim((string) $s)) ?? '');
        $haystack = $norm($text);
        $tokens   = array_values(array_filter(explode(' ', $norm($fullName)), static fn ($w) => strlen($w) >= 3));
        if ($tokens === []) {
            return false;
        }
        $found = 0;
        foreach ($tokens as $w) {
            if (str_contains($haystack, $w)) {
                $found++;
            }
        }

        return $found >= 1; // cukup SATU kata nama customer terdeteksi → dianggap sesuai
    }

    /**
     * Samakan nama hasil OCR dengan nama customer TERDAFTAR bila kecocokannya kuat
     * (orang yang sama). PaddleOCR sering menghilangkan spasi & sesekali tertukar 1 huruf
     * (mis. "SAEFULHARIS"/"SAEEULHARIS" utk "Saeful Haris"); bila jarak edit kecil → tampilkan
     * ejaan & spasi RESMI. Bila tidak cukup mirip → kembalikan nama OCR apa adanya (jujur).
     * Teks mentah OCR tetap disimpan utuh utk transparansi.
     */
    public function canonicalName(?string $ocrName, ?string $customerName): ?string
    {
        $ocr  = $this->cleanName($ocrName);
        $cust = $customerName !== null ? trim($customerName) : '';
        if ($cust === '' || $ocr === null || $ocr === '') {
            return $ocr;
        }
        $norm = static fn ($s) => preg_replace('/[^A-Z]/', '', strtoupper((string) $s)) ?? '';
        $a = $norm($ocr);
        $b = $norm($cust);
        if ($a === '' || $b === '' || strlen($a) > 255 || strlen($b) > 255) {
            return $ocr;
        }
        $dist = levenshtein($a, $b);
        $thr  = max(2, (int) floor(max(strlen($a), strlen($b)) * 0.25)); // ≤25% beda → orang sama

        return $dist <= $thr ? $cust : $ocr;
    }

    private function afterLabel(string $text, string $label): ?string
    {
        if (preg_match('/'.preg_quote($label, '/').'\s*[:.\-]?\s*([^\r\n]+)/i', $text, $m)) {
            $v = trim($m[1]);

            return $v !== '' ? $v : null;
        }

        return null;
    }
}
