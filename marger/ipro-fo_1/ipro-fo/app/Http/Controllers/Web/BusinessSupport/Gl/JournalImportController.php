<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Gl;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Import Jurnal (CSV) → posting massal ke General Ledger.
 * Format kolom: TANGGAL, KODE_AKUN, DEBIT, KREDIT, KETERANGAN, REFERENSI
 * Baris dengan REFERENSI sama dikelompokkan menjadi satu jurnal (harus balance).
 */
class JournalImportController extends Controller
{
    public function form(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        return Inertia::render('BusinessSupport/Gl/JournalImport', [
            'template' => "TANGGAL,KODE_AKUN,DEBIT,KREDIT,KETERANGAN,REFERENSI\n2026-06-30,50101010101,1000000,0,Beban contoh,JU-001\n2026-06-30,10101010101,0,1000000,Kas keluar,JU-001",
        ]);
    }

    public function store(Request $request, JournalService $journal): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $request->validate([
            'file'    => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $rows = $this->xlsxRead($request->file('file')->getRealPath());
        $header = array_map(fn ($h) => strtoupper(trim((string) $h)), array_shift($rows) ?? []);
        $idx = array_flip($header);
        foreach (['TANGGAL', 'KODE_AKUN', 'DEBIT', 'KREDIT', 'REFERENSI'] as $col) {
            if (! isset($idx[$col])) {
                return back()->with('error', "Kolom '{$col}' tidak ada di file. Header wajib: TANGGAL, KODE_AKUN, DEBIT, KREDIT, KETERANGAN, REFERENSI.");
            }
        }

        $accByCode = BsAccount::pluck('id', 'code');
        $groups = [];
        $errors = [];
        foreach ($rows as $i => $r) {
            $ref  = trim((string) ($r[$idx['REFERENSI']] ?? ''));
            $code = trim((string) ($r[$idx['KODE_AKUN']] ?? ''));
            if ($ref === '' || $code === '') {
                continue;
            }
            if (! isset($accByCode[$code])) {
                $errors[] = 'Baris '.($i + 2).": akun {$code} tidak ditemukan.";
                continue;
            }
            $groups[$ref]['date'] ??= $this->normDate((string) ($r[$idx['TANGGAL']] ?? ''));
            $groups[$ref]['lines'][] = [
                'account_id'  => (int) $accByCode[$code],
                'debit'       => round((float) str_replace([',', ' '], '', (string) ($r[$idx['DEBIT']] ?? 0)), 2),
                'credit'      => round((float) str_replace([',', ' '], '', (string) ($r[$idx['KREDIT']] ?? 0)), 2),
                'description' => isset($idx['KETERANGAN']) ? trim((string) ($r[$idx['KETERANGAN']] ?? '')) : null,
            ];
        }

        if ($errors) {
            return back()->with('error', implode(' ', array_slice($errors, 0, 5)).(count($errors) > 5 ? ' …' : ''));
        }
        if (! $groups) {
            return back()->with('error', 'Tidak ada baris jurnal valid di file.');
        }

        if ($request->boolean('dry_run')) {
            return back()->with('success', 'Dry-run OK: '.count($groups).' jurnal siap diimport ('.array_sum(array_map(fn ($g) => count($g['lines']), $groups)).' baris). Hapus centang dry-run untuk memproses.');
        }

        $posted = 0;
        try {
            foreach ($groups as $ref => $g) {
                $journal->post([
                    'journal_date' => $g['date'], 'source' => 'import', 'reference' => $ref,
                    'description'  => 'Import Jurnal '.$ref, 'created_by' => $request->user()->id,
                ], $g['lines']);
                $posted++;
            }
        } catch (BusinessRuleException $e) {
            return back()->with('error', "Gagal di jurnal ke-{$posted}+1: ".$e->getMessage());
        }

        return redirect()->route('bs.gl.journal.index')->with('success', "{$posted} jurnal berhasil diimport ke General Ledger.");
    }

    /** Unduh template Excel (.xlsx) berisi header + contoh baris (dapat diisi ratusan baris). */
    public function template(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $rows = [
            ['TANGGAL', 'KODE_AKUN', 'DEBIT', 'KREDIT', 'KETERANGAN', 'REFERENSI'],
            ['2026-06-30', '50101010101', 1000000, 0, 'Beban contoh', 'JU-001'],
            ['2026-06-30', '10101010101', 0, 1000000, 'Kas keluar', 'JU-001'],
            ['2026-06-30', '10102010101', 5000000, 0, 'Piutang usaha', 'JU-002'],
            ['2026-06-30', '40101010101', 0, 5000000, 'Penjualan usaha', 'JU-002'],
        ];

        return $this->xlsxDownload($rows, 'Template-Import-Jurnal.xlsx');
    }

    /** Normalisasi tanggal: teks (YYYY-MM-DD) atau serial Excel (basis 1899-12-30). */
    private function normDate(string $v): string
    {
        $v = trim($v);
        if ($v !== '' && ctype_digit($v) && (int) $v > 1000) {
            return \Illuminate\Support\Carbon::create(1899, 12, 30)->addDays((int) $v)->toDateString();
        }

        return $v;
    }

    /** Bangun & unduh file .xlsx minimal (inline string), tanpa dependency eksternal. */
    private function xlsxDownload(array $rows, string $filename): \Illuminate\Http\Response
    {
        $e   = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $col = function (int $i): string { $s = ''; $i++; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m).$s; $i = intdiv($i - 1, 26); } return $s; };
        $body = '';
        foreach ($rows as $ri => $row) {
            $r = $ri + 1;
            $cells = '';
            foreach (array_values($row) as $ci => $val) {
                $ref = $col($ci).$r;
                if ($val === '' || $val === null) {
                    $cells .= '<c r="'.$ref.'"/>';
                } elseif (is_int($val) || is_float($val)) {
                    $cells .= '<c r="'.$ref.'"><v>'.$val.'</v></c>';
                } else {
                    $cells .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.$e($val).'</t></is></c>';
                }
            }
            $body .= '<row r="'.$r.'">'.$cells.'</row>';
        }
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$body.'</sheetData></worksheet>';
        $ct    = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>';
        $rels  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $wb    = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="JURNAL" sheetId="1" r:id="rId1"/></sheets></workbook>';
        $wbr   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>';

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $ct);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('xl/workbook.xml', $wb);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbr);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
        $content = (string) file_get_contents($tmp);
        @unlink($tmp);

        return response($content)
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
    }

    /** Baca file .xlsx (ZipArchive) atau .csv → array baris [ [col0, col1, ...], ... ]. */
    private function xlsxRead(string $path): array
    {
        $sig = (string) @file_get_contents($path, false, null, 0, 2);
        if ($sig !== 'PK') { // bukan zip → perlakukan sebagai CSV
            $rows = [];
            if (($h = @fopen($path, 'r')) !== false) {
                while (($d = fgetcsv($h, 0, ',')) !== false) { $rows[] = $d; }
                fclose($h);
            }

            return $rows;
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) { return []; }
        $shared = [];
        if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            foreach (preg_split('/<si[ >]/', $ss) as $k => $si) {
                if ($k === 0 || ! str_contains($si, '</si>')) { continue; }
                preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $m);
                $shared[] = html_entity_decode(implode('', $m[1]), ENT_QUOTES | ENT_XML1);
            }
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheet === false) { return []; }

        $rows = [];
        preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheet, $rm);
        foreach ($rm[1] as $rowXml) {
            $cells = [];
            preg_match_all('/<c\b([^>]*?)(?:\/>|>(.*?)<\/c>)/s', $rowXml, $cm, PREG_SET_ORDER);
            foreach ($cm as $c) {
                $attr = $c[1];
                $inner = $c[2] ?? '';
                $ci = preg_match('/r="([A-Z]+)\d+"/', $attr, $rr) ? $this->colToIdx($rr[1]) : count($cells);
                $t = preg_match('/t="([^"]+)"/', $attr, $tt) ? $tt[1] : 'n';
                $val = '';
                if ($t === 's' && preg_match('/<v>(.*?)<\/v>/s', $inner, $vv)) {
                    $val = $shared[(int) $vv[1]] ?? '';
                } elseif ($t === 'inlineStr' && preg_match('/<t[^>]*>(.*?)<\/t>/s', $inner, $vv)) {
                    $val = html_entity_decode($vv[1], ENT_QUOTES | ENT_XML1);
                } elseif (preg_match('/<v>(.*?)<\/v>/s', $inner, $vv)) {
                    $val = $vv[1];
                }
                $cells[$ci] = $val;
            }
            if ($cells !== []) {
                ksort($cells);
                $line = [];
                for ($i = 0, $max = max(array_keys($cells)); $i <= $max; $i++) { $line[$i] = $cells[$i] ?? ''; }
                $rows[] = $line;
            }
        }

        return $rows;
    }

    private function colToIdx(string $col): int
    {
        $n = 0;
        foreach (str_split($col) as $ch) { $n = $n * 26 + (ord($ch) - 64); }

        return $n - 1;
    }
}
