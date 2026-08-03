<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Contractor;

use App\Domain\BusinessSupport\Models\BsWorkOrder;
use App\Domain\BusinessSupport\Models\BsWorkOrderProgress;
use App\Domain\BusinessSupport\Services\ContractorService;
use App\Domain\Foundation\Tenant\Models\Cluster;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Domain\Master\Unit\Models\Unit;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kontrol Progress Lapangan (KPL / opname) — mencatat progress lapangan terhadap SPK.
 * Output = termin progress (jurnal Dr CIP+PPN / Cr Hutang+Retensi) via ContractorService::addProgress.
 * Detail (show) bisa diklik dari daftar; tombol "Cetak BAP" mengeluarkan Berita Acara Pembayaran
 * dengan perhitungan sama seperti template Excel "BAP Pembangunan".
 */
class KplController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $rows = BsWorkOrderProgress::query()
            ->with(['workOrder:id,spk_number,title,supplier_id,status', 'workOrder.supplier:id,name'])
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('kpl_number', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")))
            ->latest('progress_date')->latest('id')->paginate(50)->withQueryString();

        return Inertia::render('BusinessSupport/Contractor/Kpl/Index', [
            'rows'    => $rows,
            'filters' => $request->only('search'),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $spks = BsWorkOrder::with('supplier:id,name')->withCount('units')->where('status', 'active')
            ->orderByDesc('spk_date')->orderByDesc('id')->limit(500)->get()
            ->map(fn ($w) => [
                'id' => $w->id, 'spk_number' => $w->spk_number, 'title' => $w->title, 'jenis' => $w->jenis,
                'supplier' => $w->supplier?->name, 'remaining' => (float) $w->remaining_value,
                'contract_value' => (float) $w->contract_value,
                'retention_pct' => (float) $w->retention_pct, 'advance_pct' => (float) $w->advance_pct, 'ppn_pct' => (float) $w->ppn_pct,
                'alloc_units' => (int) $w->units_count,   // >0 → porsi kontrak dari alokasi unit SPK
            ])->values();

        return Inertia::render('BusinessSupport/Contractor/Kpl/Create', [
            'spks'     => $spks,
            // Project master (sumber unit rumah) untuk registrasi massal per unit.
            'projects' => Project::orderBy('name')->get(['id', 'name'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
        ]);
    }

    public function store(Request $request, ContractorService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        // Mode MASSAL per unit rumah (ada 'units') vs mode tunggal (nilai DPP langsung).
        if ($request->has('units')) {
            $v = $request->validate([
                'work_order_id'        => ['required', 'integer', 'exists:bs_work_orders,id'],
                'project_id'           => ['nullable', 'integer'],   // wajib hanya untuk mode proporsional (SPK tanpa alokasi unit)
                'progress_date'        => ['required', 'date'],
                'description'          => ['required', 'string', 'max:200'],
                'releaser'             => ['nullable', 'string', 'max:100'],
                'pic'                  => ['nullable', 'string', 'max:100'],
                'no_manual'            => ['nullable', 'string', 'max:50'],
                'contractor_signer'    => ['nullable', 'string', 'max:100'],
                'units'                => ['required', 'array', 'min:1'],
                'units.*.unit_id'      => ['required', 'integer', 'distinct'],
                'units.*.progress_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            ]);
            $wo = BsWorkOrder::findOrFail($v['work_order_id']);
            try {
                $p = $svc->addProgressBulk($wo, $v, $request->user()->id);
            } catch (BusinessRuleException $e) {
                return back()->with('error', $e->getMessage());
            }

            return redirect()->route('bs.spk.kpl.index')
                ->with('success', 'KPL HOUSING massal tercatat: '.$p->progressUnits()->count().' unit — jurnal termin otomatis.');
        }

        // Mode MASSAL per item pekerjaan (INFRA / LAIN-LAIN).
        if ($request->has('items')) {
            $v = $request->validate([
                'work_order_id'         => ['required', 'integer', 'exists:bs_work_orders,id'],
                'category'              => ['required', 'in:infra,lain_lain'],
                'progress_date'         => ['required', 'date'],
                'description'           => ['required', 'string', 'max:200'],
                'releaser'              => ['nullable', 'string', 'max:100'],
                'pic'                   => ['nullable', 'string', 'max:100'],
                'no_manual'             => ['nullable', 'string', 'max:50'],
                'contractor_signer'     => ['nullable', 'string', 'max:100'],
                'items'                 => ['required', 'array', 'min:1'],
                'items.*.label'         => ['nullable', 'string', 'max:200'],
                'items.*.satuan'        => ['nullable', 'string', 'max:30'],
                'items.*.volume'        => ['nullable', 'numeric'],
                'items.*.nilai'         => ['nullable', 'numeric', 'min:0'],
                'items.*.progress_pct'  => ['nullable', 'numeric', 'min:0', 'max:100'],
            ]);
            $wo = BsWorkOrder::findOrFail($v['work_order_id']);
            try {
                $p = $svc->addProgressItems($wo, $v, $request->user()->id);
            } catch (BusinessRuleException $e) {
                return back()->with('error', $e->getMessage());
            }

            return redirect()->route('bs.spk.kpl.index')
                ->with('success', 'KPL '.strtoupper($v['category']).' massal tercatat: '.$p->progressUnits()->count().' item — jurnal termin otomatis.');
        }

        $v = $request->validate([
            'work_order_id'     => ['required', 'integer', 'exists:bs_work_orders,id'],
            'progress_date'     => ['required', 'date'],
            'description'       => ['required', 'string', 'max:200'],
            'progress_value'    => ['required', 'numeric', 'min:0.01'],
            'releaser'          => ['nullable', 'string', 'max:100'],
            'pic'               => ['nullable', 'string', 'max:100'],
            'no_manual'         => ['nullable', 'string', 'max:50'],
            'contractor_signer' => ['nullable', 'string', 'max:100'],
        ]);

        $wo = BsWorkOrder::findOrFail($v['work_order_id']);
        try {
            $svc->addProgress($wo, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.spk.kpl.index')->with('success', 'Kontrol Progress Lapangan tercatat — jurnal termin otomatis.');
    }

    /** Unit rumah yang dialokasikan pada suatu SPK — Porsi Kontrak = harga satuan alokasi. */
    public function spkUnits(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);
        $wo    = BsWorkOrder::find((int) $request->input('work_order_id'));
        $units = $wo
            ? $wo->units()->with('unit:id,current_price,base_price')->orderBy('seq')->orderBy('id')->get(['id', 'unit_id', 'unit_code', 'unit_price'])
                ->map(fn ($u) => [
                    'id'        => $u->unit_id,
                    'unit_code' => (string) $u->unit_code,
                    'porsi'     => (float) $u->unit_price,
                    'harga'     => (float) ($u->unit?->current_price ?: $u->unit?->base_price ?: 0),
                ])->values()
            : collect();

        return response()->json(['units' => $units]);
    }

    /** Daftar unit rumah suatu project (untuk registrasi KPL massal — fallback proporsional). */
    public function units(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $projectId = (int) $request->input('project_id');
        $clusterId = (int) $request->input('cluster_id');
        $cluster   = trim((string) $request->input('cluster', ''));
        $tahap     = trim((string) $request->input('tahap', ''));

        $query = Unit::where('project_id', $projectId);

        // Tahap = segmen ke-2 unit_code (mis. 'JRS5-001-III-33a' → '001'). Cocok persis
        // ATAU numerik agnostik zero-pad ('5'~'005', '1'~'001'); tahap kosong → cluster saja.
        $filterTahap = function ($q) use ($tahap): void {
            if ($tahap === '') {
                return;
            }
            $seg2 = "SUBSTRING_INDEX(SUBSTRING_INDEX(unit_code, '-', 2), '-', -1)";
            $q->where(function ($w) use ($seg2, $tahap): void {
                $w->whereRaw("{$seg2} = ?", [$tahap]);
                if (ctype_digit($tahap)) {
                    $w->orWhereRaw("({$seg2} REGEXP '^[0-9]+$' AND CAST({$seg2} AS UNSIGNED) = ?)", [(int) $tahap]);
                }
            });
        };

        if ($clusterId > 0) {
            // Cluster dipilih dari dropdown → HANYA unit terdaftar pada cluster itu.
            // Sengaja tanpa fallback: cluster kosong unit harus tampil kosong, bukan seluruh project.
            $query->where('cluster_id', $clusterId);
            $filterTahap($query);
        } elseif ($cluster !== '') {
            // Jalur lama (cluster sbg LABEL teks) — resolve ke cluster_id via master (name atau code).
            $clusterIds = Cluster::where('project_id', $projectId)
                ->where(fn ($q) => $q->whereRaw('LOWER(name) = ?', [mb_strtolower($cluster)])
                    ->orWhereRaw('LOWER(code) = ?', [mb_strtolower($cluster)]))
                ->pluck('id')->all();

            // Label tak punya padanan di master → fallback project_id (jangan kosongkan hasil).
            if ($clusterIds !== []) {
                $query->whereIn('cluster_id', $clusterIds);
                $filterTahap($query);
            }
        }

        $units = $query->orderBy('unit_code')
            ->get(['id', 'unit_code', 'current_price', 'base_price'])
            ->map(fn ($u) => ['id' => $u->id, 'unit_code' => $u->unit_code, 'price' => (float) ($u->current_price ?: $u->base_price ?: 0)])
            ->values();

        return response()->json(['units' => $units, 'denom' => (float) $units->sum('price')]);
    }

    /**
     * Daftar Cluster milik sebuah project (Sales) — sumber dropdown Cluster di Registrasi SPK.
     * Unit pada Registrasi SPK disaring memakai cluster_id dari sini (lihat units()).
     */
    public function clusters(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $projectId = (int) $request->input('project_id');
        $clusters  = $projectId > 0
            ? Cluster::where('project_id', $projectId)->orderBy('name')->get(['id', 'code', 'name'])
                ->map(fn ($c) => ['id' => $c->id, 'code' => (string) $c->code, 'name' => (string) $c->name])->values()
            : collect();

        return response()->json(['clusters' => $clusters]);
    }

    /** Item pekerjaan (BoQ) dari SPK — untuk prefill registrasi INFRA/LAIN-LAIN. */
    public function items(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);
        $wo    = BsWorkOrder::find((int) $request->input('work_order_id'));
        $items = $wo
            ? $wo->items()->orderBy('seq')->orderBy('id')->get(['description', 'unit', 'qty', 'amount'])
                ->map(fn ($i) => ['label' => (string) $i->description, 'satuan' => (string) $i->unit, 'volume' => (float) $i->qty, 'nilai' => (float) $i->amount])->values()
            : collect();

        return response()->json(['items' => $items]);
    }

    /** Unduh template Excel (.xlsx) registrasi massal — kolom sesuai kategori (housing / infra). */
    public function importTemplate(Request $request): HttpResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);
        $cat = (string) $request->input('category', 'housing');

        if (in_array($cat, ['infra', 'lain_lain'], true)) {
            $rows = [['Uraian Pekerjaan', 'Satuan', 'Volume', 'Nilai', 'Progress %']];
            $wo   = BsWorkOrder::find((int) $request->input('work_order_id'));
            if ($wo) {
                foreach ($wo->items()->orderBy('seq')->orderBy('id')->get(['description', 'unit', 'qty', 'amount']) as $it) {
                    $rows[] = [(string) $it->description, (string) $it->unit, (float) $it->qty, (float) $it->amount, ''];
                }
            }
            if (count($rows) === 1) {
                $rows[] = ['(uraian pekerjaan)', 'ls', 1, 0, ''];
            }

            return $this->xlsxDownload($rows, 'template-kpl-infra.xlsx');
        }

        // Mode alokasi SPK (work_order_id) → kode unit dari alokasi; else dari project.
        if (($woId = (int) $request->input('work_order_id')) > 0) {
            $wo    = BsWorkOrder::find($woId);
            $codes = $wo ? $wo->units()->orderBy('seq')->orderBy('id')->pluck('unit_code') : collect();
        } else {
            $codes = Unit::where('project_id', (int) $request->input('project_id'))->orderBy('unit_code')->pluck('unit_code');
        }
        $rows  = [['Kode Unit', 'Progress %']];
        foreach ($codes as $c) {
            $rows[] = [(string) $c, ''];
        }
        if (count($rows) === 1) {
            $rows[] = ['(isi kode unit)', ''];
        }

        return $this->xlsxDownload($rows, 'template-kpl-housing.xlsx');
    }

    /** Parse file .xlsx/.csv yang diunggah → baris mentah [[c0,c1,...]] (dipetakan di client per kategori). */
    public function importParse(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);
        // Validasi tipe (mimes) + ukuran (CLAUDE.md §5.10); xlsxRead juga sniff isi (PK/CSV) sbg pertahanan ganda.
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120']]);
        $rows = $this->xlsxRead($request->file('file')->getRealPath());
        $out  = [];
        foreach ($rows as $i => $r) {
            $c0 = trim((string) ($r[0] ?? ''));
            if ($c0 === '') {
                continue;
            }
            $low = strtolower($c0);
            if ($i === 0 && (str_contains($low, 'kode') || str_contains($low, 'uraian') || $low === 'no' || $low === 'no.')) {
                continue; // lewati baris header
            }
            $out[] = array_map(fn ($x) => is_string($x) ? trim($x) : $x, array_values($r));
        }

        return response()->json(['rows' => $out]);
    }

    /** Bangun & unduh file .xlsx minimal (inline string), tanpa dependency eksternal. */
    private function xlsxDownload(array $rows, string $filename): HttpResponse
    {
        $e   = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $col = function (int $i): string { $s = ''; $i++; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m).$s; $i = intdiv($i - 1, 26); } return $s; };
        $body = '';
        foreach ($rows as $ri => $row) {
            $r = $ri + 1; $cells = '';
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
        $wb    = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="KPL" sheetId="1" r:id="rId1"/></sheets></workbook>';
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
                $attr = $c[1]; $inner = $c[2] ?? '';
                $ci   = preg_match('/r="([A-Z]+)\d+"/', $attr, $rr) ? $this->colToIdx($rr[1]) : count($cells);
                $t    = preg_match('/t="([^"]+)"/', $attr, $tt) ? $tt[1] : 'n';
                $val  = '';
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

    /** Detail KPL (dapat diklik dari daftar) + preview angka BAP + tombol Cetak. */
    public function show(Request $request, BsWorkOrderProgress $progress): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);
        $progress->load(['workOrder:id,spk_number,spk_date,title,supplier_id,contract_value,addendum_value,retention_pct,advance_pct,ppn_pct,project_id,status', 'workOrder.supplier:id,name', 'workOrder.project:id,name']);

        return Inertia::render('BusinessSupport/Contractor/Kpl/Show', [
            'kpl' => $progress,
            'bap' => $this->computeBap($progress),
        ]);
    }

    /** Cetak Berita Acara Pembayaran (HTML → window.print). */
    public function bap(Request $request, BsWorkOrderProgress $progress): HttpResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);
        $progress->load(['workOrder.supplier:id,name', 'workOrder.project:id,name']);

        return response($this->renderBap($this->computeBap($progress)))
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    // ── Perhitungan BAP (basis GROSS / termasuk PPN, sama seperti template Excel) ──
    private function computeBap(BsWorkOrderProgress $p): array
    {
        $wo = $p->workOrder;
        $ppnFactor = 1 + (float) $wo->ppn_pct / 100;
        $retPct    = (float) $wo->retention_pct / 100;
        $advPct    = (float) $wo->advance_pct / 100;

        // Nilai kontrak disajikan gross (termasuk PPN) seperti BAP; data app tersimpan DPP (excl. PPN).
        // CATATAN: contract_value SUDAH termasuk addendum (ContractorService::addendum menambah delta ke
        // contract_value DAN addendum_value). Maka kontrak awal (N35) = contract_value − addendum_value,
        // dan N37 = contract_value gross (= N35 + N36), agar addendum tidak terhitung dua kali.
        $originalDpp   = round((float) $wo->contract_value - (float) $wo->addendum_value, 2);
        $contractGross = round($originalDpp * $ppnFactor, 2);                // N35 = Nilai Kontrak Awal (murni)
        $addendumGross = round((float) $wo->addendum_value * $ppnFactor, 2); // N36 = delta addendum
        $finalGross    = round((float) $wo->contract_value * $ppnFactor, 2); // N37 = kontrak kini (= N35 + N36)
        $uangMuka      = round($advPct * $finalGross, 2);

        // Progress kumulatif (DPP) s/d termin ini (rank) & sebelum ini, urut tanggal lalu id.
        $all  = $wo->progress()->orderBy('progress_date')->orderBy('id')->get(['id', 'progress_value'])->values();
        $rank = 0;
        foreach ($all as $i => $row) {
            if ((int) $row->id === (int) $p->id) { $rank = $i + 1; break; }
        }
        $cumNow = 0.0; $cumPrev = 0.0;
        foreach ($all as $i => $row) {
            if ($i < $rank) { $cumNow += (float) $row->progress_value; }
            if ($i < $rank - 1) { $cumPrev += (float) $row->progress_value; }
        }

        // Nilai hak kumulatif (= N44) untuk suatu progress DPP kumulatif.
        $entitlement = function (float $cumDpp) use ($ppnFactor, $finalGross, $uangMuka, $retPct): float {
            $g          = $cumDpp * $ppnFactor;
            $dpRecovery = $finalGross > 0 ? -($g / $finalGross) * $uangMuka : 0.0;
            $sub        = $g + $uangMuka + $dpRecovery;
            $ret        = -($g * $retPct);

            return round($sub + $ret, 2);
        };
        $eNow  = $entitlement($cumNow);
        $ePrev = $rank > 1 ? $entitlement($cumPrev) : 0.0;

        $n39 = round($cumNow * $ppnFactor, 2);
        $n40 = $uangMuka;
        $n41 = $finalGross > 0 ? round(-($n39 / $finalGross) * $uangMuka, 2) : 0.0;
        $n42 = round($n39 + $n40 + $n41, 2);
        $n43 = round(-($n39 * $retPct), 2);
        $n44 = round($n42 + $n43, 2);
        $n45 = round(-$ePrev, 2);
        $n46 = round($n44 + $n45, 2);
        $n48 = round($finalGross - $ePrev, 2);
        $n49 = round($n48 - $n46, 2);

        $d      = $p->progress_date;
        $bulan  = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $hari   = ['Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'];
        $roman  = [1 => 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
        $day    = (int) $d->format('j');
        $month  = (int) $d->format('n');
        $year   = (int) $d->format('Y');
        $company = (array) config('business_support.company');

        return [
            'kpl_number'     => $p->kpl_number,
            'bap_number'     => sprintf('%03d/BAP/MUP-MUK/%s/%d', max(1, $rank), $roman[$month] ?? '', $year),
            'payment_seq'    => max(1, $rank),
            'pemberi_tugas'  => $company['name'] ?? 'PT. MITRA USAHA PROPERTINDO',
            'pemberi_signer' => $company['signer_name'] ?? '(__________)',
            'pemberi_title'  => $company['signer_title'] ?? 'Direktur',
            'city'           => $company['city'] ?? 'Sidoarjo',
            'kontraktor'     => $wo->supplier?->name ?? '-',
            'kontraktor_signer' => $p->contractor_signer ?: '(__________)',
            'pekerjaan'      => $wo->title,
            'project'        => $wo->project?->name,
            'spk_number'     => $wo->spk_number,
            'spk_date'       => optional($wo->spk_date)->format('d/m/Y'),
            'uraian'         => $p->description,
            // tanggal terbilang
            'tgl_hari'       => $hari[$d->format('l')] ?? '',
            'tgl_display'    => sprintf('%02d %s %d', $day, $bulan[$month] ?? '', $year),
            'tgl_day_words'  => $this->terbilang($day),
            'tgl_bulan'      => $bulan[$month] ?? '',
            'tgl_year_words' => $this->terbilang($year),
            // persentase & nilai
            'retensi_pct'    => (float) $wo->retention_pct,
            'advance_pct'    => (float) $wo->advance_pct,
            'ppn_pct'        => (float) $wo->ppn_pct,
            'progress_pct'   => $finalGross > 0 ? round($n39 / $finalGross * 100, 2) : 0.0,
            'contract_gross' => $contractGross,   // N35
            'addendum_gross' => $addendumGross,   // N36
            'final_gross'    => $finalGross,      // N37
            'progress_val'   => $n39,             // N39
            'uang_muka'      => $n40,             // N40
            'dp_recovery'    => $n41,             // N41
            'sub_total'      => $n42,             // N42
            'retensi'        => $n43,             // N43
            'after_retensi'  => $n44,             // N44
            'prev_paid'      => $n45,             // N45
            'payable_now'    => $n46,             // N46
            'sisa_sebelum'   => $n48,             // N48
            'sisa_setelah'   => $n49,             // N49
        ];
    }

    /** Angka bulat → terbilang Bahasa Indonesia (untuk tanggal BAP). */
    private function terbilang(int $n): string
    {
        $n = abs($n);
        $s = ['', 'Satu', 'Dua', 'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh', 'Delapan', 'Sembilan', 'Sepuluh', 'Sebelas'];
        if ($n < 12) {
            return $s[$n];
        }
        if ($n < 20) {
            return trim($this->terbilang($n - 10).' Belas');
        }
        if ($n < 100) {
            return trim($this->terbilang(intdiv($n, 10)).' Puluh '.$this->terbilang($n % 10));
        }
        if ($n < 200) {
            return trim('Seratus '.$this->terbilang($n - 100));
        }
        if ($n < 1000) {
            return trim($this->terbilang(intdiv($n, 100)).' Ratus '.$this->terbilang($n % 100));
        }
        if ($n < 2000) {
            return trim('Seribu '.$this->terbilang($n - 1000));
        }
        if ($n < 1000000) {
            return trim($this->terbilang(intdiv($n, 1000)).' Ribu '.$this->terbilang($n % 1000));
        }
        if ($n < 1000000000) {
            return trim($this->terbilang(intdiv($n, 1000000)).' Juta '.$this->terbilang($n % 1000000));
        }

        return trim($this->terbilang(intdiv($n, 1000000000)).' Milyar '.$this->terbilang($n % 1000000000));
    }

    /** HTML Berita Acara Pembayaran (auto-print). Layout mengikuti template Excel BAP. */
    private function renderBap(array $b): string
    {
        $e  = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $rp = fn ($v) => number_format((float) $v, 2, ',', '.');
        $row = fn (string $label, float $val, string $op = '') => '<tr><td class="lbl">'.$e($label).'</td><td class="op">'.$e($op).'</td><td class="r">'.$rp($val).'</td></tr>';

        $calc = ''
            .'<tr><td class="lbl">Nilai Kontrak Awal</td><td class="op">= Rp.</td><td class="r">'.$rp($b['contract_gross']).'</td></tr>'
            .'<tr><td class="lbl">Nilai Pekerjaan Tambah Kurang</td><td class="op">+</td><td class="r">'.$rp($b['addendum_gross']).'</td></tr>'
            .'<tr class="sub"><td class="lbl">Nilai Kontrak Akhir</td><td class="op">= Rp.</td><td class="r">'.$rp($b['final_gross']).'</td></tr>'
            .'<tr><td colspan="3" class="sp"></td></tr>'
            .'<tr><td class="lbl">Nilai Pekerjaan yang telah dilaksanakan sampai saat ini ('.$rp($b['progress_pct']).'%)</td><td class="op">Rp.</td><td class="r">'.$rp($b['progress_val']).'</td></tr>'
            .'<tr><td class="lbl">Uang muka ('.$rp($b['advance_pct']).'%)</td><td class="op">+</td><td class="r">'.$rp($b['uang_muka']).'</td></tr>'
            .'<tr><td class="lbl">Pengurangan pengembalian uang muka</td><td class="op">+</td><td class="r">'.$rp($b['dp_recovery']).'</td></tr>'
            .'<tr class="sub"><td class="lbl">Sub total</td><td class="op">= Rp.</td><td class="r">'.$rp($b['sub_total']).'</td></tr>'
            .'<tr><td class="lbl">Pengurangan Retensi ('.$rp($b['retensi_pct']).'%) x Nilai Progress</td><td class="op">+</td><td class="r">'.$rp($b['retensi']).'</td></tr>'
            .'<tr class="sub"><td class="lbl">Jumlah setelah Retensi</td><td class="op">= Rp.</td><td class="r">'.$rp($b['after_retensi']).'</td></tr>'
            .'<tr><td class="lbl">Pembayaran yang telah dibayarkan sampai saat ini</td><td class="op">+</td><td class="r">'.$rp($b['prev_paid']).'</td></tr>'
            .'<tr class="tot"><td class="lbl">Total Pembayaran yang dapat dibayarkan sekarang</td><td class="op">= Rp.</td><td class="r">'.$rp($b['payable_now']).'</td></tr>'
            .'<tr><td colspan="3" class="sp"></td></tr>'
            .'<tr><td class="lbl">Sisa Pembayaran <b>sebelum</b> pembayaran ini</td><td class="op">Rp.</td><td class="r">'.$rp($b['sisa_sebelum']).'</td></tr>'
            .'<tr><td class="lbl">Sisa Pembayaran <b>setelah</b> pembayaran ini</td><td class="op">Rp.</td><td class="r">'.$rp($b['sisa_setelah']).'</td></tr>';

        return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>BAP '.$e($b['bap_number']).'</title><style>
            @page{size:A4;margin:16mm}*{box-sizing:border-box}
            body{font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#111;margin:0;line-height:1.45}
            h1{font-size:16px;text-align:center;margin:0;letter-spacing:.5px}
            .no{text-align:center;margin:2px 0 14px;font-size:11px}
            .meta{width:100%;border-collapse:collapse;margin-bottom:10px}
            .meta td{vertical-align:top;padding:1px 4px}.meta .k{width:150px}.meta .s{width:12px}
            p{margin:6px 0}.i{margin-left:18px}
            table.calc{width:100%;border-collapse:collapse;margin:8px 0}
            table.calc td{padding:2px 6px}table.calc td.lbl{width:60%}
            table.calc td.op{width:12%;text-align:center}
            table.calc td.r{text-align:right;font-variant-numeric:tabular-nums;border-bottom:1px solid #bbb}
            table.calc tr.sub td{font-weight:bold}table.calc tr.sub td.r{border-top:1px solid #333}
            table.calc tr.tot td{font-weight:bold;background:#f2f2f2}
            table.calc td.sp{height:6px;border:0}
            .signs{width:100%;margin-top:26px}.signs td{text-align:center;vertical-align:top;width:50%;font-size:11px}
            .space{height:60px}.nm{font-weight:bold;text-decoration:underline}
            .note{margin-top:22px;font-size:9px;color:#444;border-top:1px solid #ccc;padding-top:6px}
            @media print{.noprint{display:none}}
            </style></head><body>
            <h1>BERITA ACARA PEMBAYARAN</h1>
            <div class="no">No : '.$e($b['bap_number']).' &nbsp;·&nbsp; Pembayaran ke '.$e($b['payment_seq']).'</div>

            <table class="meta">
              <tr><td class="k">Pemberi Tugas</td><td class="s">:</td><td>'.$e($b['pemberi_tugas']).'</td></tr>
              <tr><td class="k">Kontraktor</td><td class="s">:</td><td>'.$e($b['kontraktor']).'</td></tr>
              <tr><td class="k">Pekerjaan</td><td class="s">:</td><td>'.$e($b['pekerjaan']).($b['project'] ? ' — '.$e($b['project']) : '').'</td></tr>
              <tr><td class="k">No. Kontrak Kerja</td><td class="s">:</td><td>'.$e($b['spk_number']).' &nbsp; ('.$e($b['spk_date']).')</td></tr>
              <tr><td class="k">Nilai Kontrak</td><td class="s">:</td><td>Rp. '.$rp($b['contract_gross']).' <span style="color:#666">(termasuk PPh &amp; PPN)</span></td></tr>
              <tr><td class="k">Nilai Addendum</td><td class="s">:</td><td>Rp. '.$rp($b['addendum_gross']).' <span style="color:#666">(termasuk PPh &amp; PPN '.$rp($b['ppn_pct']).'%)</span></td></tr>
              <tr><td class="k">Lampiran</td><td class="s">:</td><td>Copy SPK, Foto Progres pekerjaan.</td></tr>
            </table>

            <p>Pada hari ini <b>'.$e($b['tgl_hari']).'</b>, tanggal <b>'.$e($b['tgl_day_words']).'</b> bulan <b>'.$e($b['tgl_bulan']).'</b> Tahun <b>'.$e($b['tgl_year_words']).'</b>, kami yang bertandatangan di bawah ini :</p>
            <p class="i">1. '.$e($b['pemberi_signer']).' &mdash; untuk dan atas nama <b>'.$e($b['pemberi_tugas']).'</b></p>
            <p class="i">2. '.$e($b['kontraktor_signer']).' &mdash; untuk dan atas nama <b>'.$e($b['kontraktor']).'</b></p>
            <p>Dengan ini menyatakan bahwa :</p>
            <p class="i">Berdasarkan Berita Acara Pemeriksaan Kemajuan Pekerjaan terlampir, maka progress kemajuan pekerjaan yang telah dilaksanakan Kontraktor adalah : <b>'.$e($b['uraian']).'</b>.</p>
            <p class="i">Pekerjaan yang telah dilaksanakan tersebut telah sesuai dengan ketentuan-ketentuan yang tercantum dalam Kontrak Kerja dan petunjuk-petunjuk yang telah diberikan.</p>
            <p>Sehingga sesuai Kontrak Kerja, Kontraktor berhak menerima pembayaran dengan perincian sebagai berikut ( belum dipotong PPh Final ) :</p>

            <table class="calc">'.$calc.'</table>

            <p>Demikian Berita Acara Pembayaran ini dibuat dan ditandatangani dengan sesungguhnya dalam rangkap 1 (satu) untuk dipergunakan sebagaimana mestinya.</p>

            <table class="signs">
              <tr><td>'.$e($b['city']).', '.$e($b['tgl_display']).'<br>Disetujui oleh,<br><b>'.$e($b['pemberi_tugas']).'</b><div class="space"></div><span class="nm">'.$e($b['pemberi_signer']).'</span><br>'.$e($b['pemberi_title']).'</td>
              <td>Kontraktor,<br><b>'.$e($b['kontraktor']).'</b><div class="space"></div><span class="nm">'.$e($b['kontraktor_signer']).'</span></td></tr>
            </table>

            <div class="note">Note: Pembayaran Uang Muka dapat dilakukan setelah Jaminan Uang Muka dan Jaminan Pelaksanaan diterima oleh Bagian Keuangan.</div>
            <button class="noprint" onclick="window.print()" style="margin-top:14px;padding:6px 16px">Cetak</button>
            <script>window.onload=function(){setTimeout(function(){window.print()},350)}</script></body></html>';
    }
}
