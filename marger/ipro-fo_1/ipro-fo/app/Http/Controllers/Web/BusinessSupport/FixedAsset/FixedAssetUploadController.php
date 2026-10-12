<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\FixedAsset;

use App\Domain\BusinessSupport\Models\BsFixedAsset;
use App\Domain\BusinessSupport\Services\FixedAssetService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Upload massal Aset Tetap — impor daftar aset dari Excel .xlsx (template Java Core).
 * Kolom: No. Aset, name, category, acquisition_date, Unit, acquisition_cost, residual_value, useful_life_months.
 * Nomor aset dipakai apa adanya bila diisi; kategori menerima label (Bangunan/…) maupun kode (bangunan/…).
 * Tidak memposting jurnal perolehan (aset dianggap sudah tercatat / migrasi) — sama seperti registrasi tanpa sumber dana.
 */
class FixedAssetUploadController extends Controller
{
    /** Urutan kolom template. */
    private const COLS = ['No. Aset', 'name', 'category', 'acquisition_date', 'Unit', 'acquisition_cost', 'residual_value', 'useful_life_months'];

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('bs.bulk-import.manage'), 403);

        return Inertia::render('BusinessSupport/FixedAsset/Upload', [
            'header'     => self::COLS,
            'categories' => BsFixedAsset::CATEGORIES,
        ]);
    }

    /** Unduh template XLSX (format Java Core: header + contoh, tanggal dd-mmm-yy & angka ribuan). */
    public function template(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        abort_unless($request->user()?->can('bs.bulk-import.manage'), 403);
        $path = resource_path('templates/template-aset-tetap.xlsx');
        abort_unless(is_file($path), 404, 'Template tidak ditemukan.');

        return response()->download($path, 'Template-Upload-Aset-Tetap.xlsx');
    }

    public function store(Request $request, FixedAssetService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:8192'],
        ], [
            'file.mimes' => 'Berkas harus Excel (.xlsx). Unduh template untuk contoh susunannya.',
        ]);

        $rows = $this->readXlsx($request->file('file')->getRealPath());
        if (count($rows) === 0) {
            return back()->with('error', 'File kosong.');
        }

        // Lewati baris header (kolom ke-2 = "name" atau kolom pertama = "No. Aset").
        $h0 = strtolower(trim((string) ($rows[0][0] ?? '')));
        $h1 = strtolower(trim((string) ($rows[0][1] ?? '')));
        if ($h1 === 'name' || $h0 === 'no. aset' || str_starts_with($h0, 'no aset')) {
            array_shift($rows);
        }

        // Peta kategori: label → key + kata kunci.
        $catByLabel = [];
        foreach (BsFixedAsset::CATEGORIES as $key => $label) {
            $catByLabel[mb_strtolower($label)] = $key;
        }

        $ok = 0;
        $failed = [];
        foreach ($rows as $i => $r) {
            $r = array_values($r);
            $name = trim((string) ($r[1] ?? ''));
            if ($name === '' && trim((string) ($r[0] ?? '')) === '') {
                continue; // baris kosong
            }
            $ln = $i + 2; // baris di file (header dilewati)
            try {
                $category = $this->mapCategory((string) ($r[2] ?? ''), $catByLabel);
                if (! $category) {
                    throw new BusinessRuleException("kategori '".trim((string) ($r[2] ?? ''))."' tidak dikenal (Bangunan/Kendaraan/Inventaris/Tanah)");
                }
                $date = $this->parseDate($r[3] ?? null);
                if (! $date) {
                    throw new BusinessRuleException('tanggal perolehan kosong / tidak valid');
                }
                $cost = $this->num($r[5] ?? 0);
                if ($cost <= 0) {
                    throw new BusinessRuleException('harga perolehan harus > 0');
                }
                $residual = $this->num($r[6] ?? 0);
                if ($residual >= $cost) {
                    $residual = 0.0; // jaga aman: residu tak boleh ≥ harga
                }

                $svc->register([
                    'asset_number'       => trim((string) ($r[0] ?? '')),
                    'name'               => $name,
                    'quantity'           => trim((string) ($r[4] ?? '')) !== '' ? trim((string) $r[4]) : null,
                    'category'           => $category,
                    'acquisition_date'   => $date,
                    'acquisition_cost'   => $cost,
                    'residual_value'     => $residual,
                    'useful_life_months' => $category === 'tanah' ? 0 : (int) $this->num($r[7] ?? 0),
                    // tanpa funding_account_id → tidak memposting jurnal perolehan.
                ], $request->user()->id);
                $ok++;
            } catch (\Throwable $e) {
                $failed[] = "Baris $ln: ".$e->getMessage();
            }
        }

        // Otomatis posting saldo awal ke GL agar aset ter-upload langsung nyambung ke Neraca Saldo.
        // Idempoten & aman: runOpeningBalance hanya menjurnal aset ber-acquire_journal_id NULL
        // (yakni yang baru diupload di atas). Cutoff = akhir bulan lalu (saldo yang dibawa masuk).
        $postedFa = 0;
        $faError  = null;
        if ($ok > 0) {
            try {
                $asOf     = Carbon::now()->subMonthNoOverflow()->endOfMonth()->toDateString();
                $postedFa = (int) $svc->runOpeningBalance($asOf, $request->user()->id)['assets'];
            } catch (\Throwable $e) {
                // Aset sudah tersimpan; kegagalan posting saldo awal tidak membatalkan upload —
                // bisa dijalankan ulang via: php artisan fa:opening-balance
                $faError = $e->getMessage();
            }
        }

        $msg = "$ok aset tetap terupload.";
        if ($postedFa > 0) {
            $msg .= " $postedFa di antaranya diposting saldo awal ke GL.";
        }
        if ($faError !== null) {
            $msg .= ' Namun posting saldo awal GL gagal ('.$faError.') — jalankan ulang: php artisan fa:opening-balance.';
        }
        if ($failed) {
            return back()->with('error', $msg.' Gagal '.count($failed).': '.implode('; ', array_slice($failed, 0, 10)));
        }

        return back()->with('success', $msg);
    }

    /** Kategori dari label/kode/kata kunci → key kategori valid. */
    private function mapCategory(string $raw, array $catByLabel): ?string
    {
        $s = mb_strtolower(trim($raw));
        if ($s === '') {
            return null;
        }
        if (isset(BsFixedAsset::CATEGORIES[$s])) {
            return $s; // sudah key
        }
        if (isset($catByLabel[$s])) {
            return $catByLabel[$s];
        }
        foreach (['bangunan', 'kendaraan', 'inventaris', 'tanah'] as $key) {
            if (str_contains($s, $key)) {
                return $key;
            }
        }

        return null;
    }

    /** Angka toleran: buang pemisah ribuan / karakter non-angka. */
    private function num($raw): float
    {
        return round((float) preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string) $raw)), 2);
    }

    /** Tanggal dari XLSX (serial Excel / string) → Y-m-d. */
    private function parseDate($raw): ?string
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        // Serial Excel (mis. 45289) → basis 1899-12-30.
        if (is_numeric($s) && (float) $s > 20000 && (float) $s < 90000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $s)->toDateString();
        }

        return rescue(fn () => Carbon::parse($s)->toDateString(), null, false);
    }

    /**
     * Reader XLSX minimal (tanpa dependency): baca sheet pertama → array baris (kolom A..).
     * Menangani shared strings, inline string, angka; sel tanggal dikembalikan sebagai serial (dikonversi di parseDate).
     *
     * @return array<int,array<int,string>>
     */
    private function readXlsx(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }

        // Shared strings.
        $shared = [];
        if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false && $ss !== '') {
            $sx = @simplexml_load_string($ss);
            if ($sx !== false) {
                foreach ($sx->si as $si) {
                    // gabung semua node <t> (mendukung rich text).
                    $t = '';
                    foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $node) {
                        $t .= (string) $node;
                    }
                    $shared[] = $t;
                }
            }
        }

        // Sheet pertama.
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheetXml === false || $sheetXml === '') {
            return [];
        }
        $x = @simplexml_load_string($sheetXml);
        if ($x === false) {
            return [];
        }

        $rows = [];
        foreach ($x->sheetData->row as $row) {
            $cells = [];
            $max = 0;
            foreach ($row->c as $c) {
                $ref  = (string) $c['r'];               // mis. "C5"
                $col  = (int) $this->colIndex(preg_replace('/\d+/', '', $ref)); // A→0
                $type = (string) $c['t'];
                if ($type === 's') {
                    $val = $shared[(int) $c->v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = (string) ($c->is->t ?? '');
                } else {
                    $val = (string) $c->v;
                }
                $cells[$col] = $val;
                $max = max($max, $col);
            }
            if ($cells === []) {
                continue;
            }
            // Ratakan jadi array berindeks 0..max (isi sel kosong).
            $flat = [];
            for ($k = 0; $k <= $max; $k++) {
                $flat[$k] = $cells[$k] ?? '';
            }
            $rows[] = $flat;
        }

        return $rows;
    }

    /** Huruf kolom Excel (A, B, .., AA) → indeks 0-based. */
    private function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }
}
