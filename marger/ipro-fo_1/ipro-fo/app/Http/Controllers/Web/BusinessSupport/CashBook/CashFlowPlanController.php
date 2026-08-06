<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\CashBook;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCfCategory;
use App\Domain\BusinessSupport\Models\BsCfFigure;
use App\Domain\BusinessSupport\Models\BsCfFormula;
use App\Domain\BusinessSupport\Models\BsCfPeriodSetting;
use App\Http\Controllers\Controller;
use App\Support\Export\SimpleXlsxWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Modul CASH FLOW (Cash Book) — Set Up Kategori, Set Up Rumus, dan Report Cashflow.
 *
 * ANGKA AKTUAL-nya berasal dari "Simpan Pembagian" pada Rekening Ledger: tiap mutasi kas/bank
 * dibagi ke kategori arus kas (bs_ledger_cluster_splits.cf_category_id). Jadi laporan ini adalah
 * rekap dari klasifikasi yang dilakukan operator di ledger, bukan tebakan dari nama akun.
 */
class CashFlowPlanController extends Controller
{
    /** Urutan & label seksi laporan. */
    private const SECTIONS = [
        'inflow_ops'     => 'CASH IN OPS',
        'outflow'        => 'CASH OUT',
        'inflow_non_ops' => 'CASH IN NON OPS',
    ];

    // ── Set Up Kategori ───────────────────────────────────────────────────

    public function categories(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        return Inertia::render('BusinessSupport/CashBook/CashFlow/Categories', [
            'categories' => BsCfCategory::orderBy('sort_order')->orderBy('id')->get()
                ->map(fn ($c) => [
                    'id' => $c->id, 'section' => $c->section, 'group_name' => $c->group_name,
                    'code' => $c->code, 'name' => $c->name, 'sort_order' => $c->sort_order, 'is_active' => $c->is_active,
                ]),
            'sections' => collect(self::SECTIONS)->map(fn ($label, $id) => ['id' => $id, 'label' => $label])->values(),
            // Grup yang sudah ada (untuk saran isian) — grup hanya dipakai di seksi OUTFLOW.
            'groups' => BsCfCategory::whereNotNull('group_name')->distinct()->orderBy('group_name')->pluck('group_name')->values(),
        ]);
    }

    public function categoryStore(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $v = $this->categoryData($request);
        BsCfCategory::create($v + ['tenant_id' => tenant_id() ?? default_tenant_id()]);

        return back()->with('success', 'Kategori cash flow ditambahkan.');
    }

    public function categoryUpdate(Request $request, BsCfCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $category->update($this->categoryData($request, $category->id));

        return back()->with('success', 'Kategori cash flow diperbarui.');
    }

    public function categoryDestroy(Request $request, BsCfCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        // Kategori yang sudah dipakai membagi mutasi TIDAK boleh hilang — angka laporan bulan
        // lalu akan ikut lenyap tanpa jejak. Nonaktifkan saja supaya berhenti muncul di pilihan.
        $used = DB::table('bs_ledger_cluster_splits')->where('cf_category_id', $category->id)->count();
        if ($used > 0) {
            return back()->with('error', "Kategori ini sudah dipakai pada {$used} pembagian mutasi — nonaktifkan saja agar riwayat laporannya tetap utuh.");
        }
        $category->delete();

        return back()->with('success', 'Kategori cash flow dihapus.');
    }

    /** @return array<string,mixed> */
    private function categoryData(Request $request, ?int $id = null): array
    {
        $v = $request->validate([
            'section'    => ['required', Rule::in(array_keys(self::SECTIONS))],
            'group_name' => ['nullable', 'string', 'max:60'],
            'code'       => ['required', 'string', 'max:64', Rule::unique('bs_cf_categories', 'code')->ignore($id)->where('tenant_id', tenant_id())],
            'name'       => ['required', 'string', 'max:150'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active'  => ['boolean'],
        ]);
        // Grup hanya bermakna pada CASH OUT; seksi lain dipaksa kosong agar laporannya tidak
        // memunculkan sub-judul yang tak pernah dipakai.
        $v['group_name'] = $v['section'] === 'outflow' ? ($v['group_name'] ?: null) : null;
        $v['sort_order'] = $v['sort_order'] ?? 0;

        return $v;
    }

    // ── Set Up Rumus ──────────────────────────────────────────────────────

    public function formulas(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        return Inertia::render('BusinessSupport/CashBook/CashFlow/Formulas', [
            'formulas' => BsCfFormula::orderBy('sort_order')->orderBy('id')->get(['id', 'code', 'name', 'expression', 'description', 'sort_order']),
        ]);
    }

    public function formulaUpdate(Request $request, BsCfFormula $formula): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        // Kode & rumusnya dipakai mesin laporan → hanya nama & keterangan yang boleh disunting.
        $v = $request->validate([
            'name'        => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $formula->update($v);

        return back()->with('success', 'Keterangan rumus diperbarui.');
    }

    // ── Report Cashflow ───────────────────────────────────────────────────

    public function report(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        [$year, $upto] = $this->reportPeriod($request);
        $data = $this->reportData($year, $upto);

        return Inertia::render('BusinessSupport/CashBook/CashFlow/Report', $data + [
            'year'      => $year,
            'upto'      => $upto,
            'formulas'  => BsCfFormula::orderBy('sort_order')->get(['code', 'name', 'expression', 'description']),
            'hasSplits' => DB::table('bs_ledger_cluster_splits')->whereNotNull('cf_category_id')->exists(),
        ]);
    }

    /**
     * Unduh Excel — isinya SAMA PERSIS dengan yang tampil di layar karena keduanya memakai
     * reportData() yang sama; tidak ada perhitungan kedua yang bisa menyimpang diam-diam.
     * Rincian kategori selalu ikut terunduh, walaupun di layar sedang tertutup.
     */
    public function reportExcel(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        [$year, $upto] = $this->reportPeriod($request);
        ['months' => $months, 'rows' => $rows, 'summary' => $summary, 'opening' => $opening] = $this->reportData($year, $upto);

        $MON = [1 => 'JANUARI', 'FEBRUARI', 'MARET', 'APRIL', 'MEI', 'JUNI', 'JULI', 'AGUSTUS', 'SEPTEMBER', 'OKTOBER', 'NOVEMBER', 'DESEMBER'];
        $subs = ['WPNB', 'Proyeksi', 'Actual', 'Gap'];
        $colCount = 1 + count($months) * 4 + 2;

        $widths = [0 => 42];
        for ($i = 1; $i < $colCount; $i++) {
            $widths[$i] = 14;
        }

        $writer = new SimpleXlsxWriter(
            sheetName: 'Report Cashflow '.$year,
            colWidths: $widths,
            freezeRows: 4,   // judul + meta + header bulan + header sub-kolom
            freezeCols: 1,   // kolom Kategori tetap terlihat saat digulir ke kanan
        );

        $writer->addTitle('REPORT CASHFLOW '.$year, $colCount);
        $writer->addMeta(
            'Periode Januari–'.ucfirst(mb_strtolower($MON[$upto])).' '.$year
            .' · diunduh '.now()->translatedFormat('d F Y H:i').' · '.($request->user()?->name ?? '')
            .' · Actual berasal dari Simpan Pembagian di Rekening Ledger'
            .' · baris saldo hanya punya satu angka per bulan sehingga ditulis di kolom Actual',
            $colCount,
        );

        $groups = array_map(fn ($m) => ['label' => $MON[$m].' '.$year, 'span' => 4], $months);
        $groups[] = ['label' => 'TOTAL '.$year, 'span' => 1];
        $groups[] = ['label' => 'TOTAL 2022–'.$year, 'span' => 1];
        $writer->addGroupHeader($groups, 1, ['KATEGORI']);

        $head = ['KATEGORI'];
        foreach ($months as $m) {
            foreach ($subs as $s) {
                $head[] = $s;
            }
        }
        $head[] = 'Total '.$year;
        $head[] = 'Total 2022–'.$year;
        $writer->addHeader($head);

        // Kolom pertama teks, sisanya angka.
        $num = array_fill(0, $colCount, true);
        $num[0] = false;

        foreach ($rows as $r) {
            if (($r['kind'] ?? '') !== 'category') {
                // Judul seksi / sub-judul grup: satu baris label saja, seperti di layar.
                $writer->addRow([$r['label']], $num);

                continue;
            }
            $line = [$r['label']];
            foreach ($months as $mi => $m) {
                $c = $r['cells'][$mi] ?? ['wnpb' => 0, 'projection' => 0, 'actual' => 0];
                $line[] = $c['wnpb'];
                $line[] = $c['projection'];
                $line[] = $c['actual'];
                $line[] = round($c['projection'] - $c['actual'], 2);   // Gap = Proyeksi − Actual (rumus 7)
            }
            $line[] = $r['total_ytd'] ?? 0;
            $line[] = $r['total_multi'] ?? 0;
            $writer->addRow($line, $num);
        }

        foreach (['nett_ops' => 'Nett Cashplan Ops', 'nett_non_ops' => 'Nett Cashplan Non Ops', 'nett' => 'Nett Cashflow'] as $k => $label) {
            $line = [$label];
            foreach ($summary as $s) {
                $line[] = $s['wnpb'][$k];
                $line[] = $s['projection'][$k];
                $line[] = $s['actual'][$k];
                $line[] = round($s['projection'][$k] - $s['actual'][$k], 2);
            }
            $line[] = round(array_sum(array_map(fn ($s) => (float) $s['actual'][$k], $summary)), 2);
            $line[] = null;
            $writer->addRow($line, $num);
        }

        foreach (['beginning' => 'Beginning Balance', 'ending' => 'Ending Balance', 'blokir_angsuran' => 'Blokir Angsuran Loan',
            'effective' => 'Effective Balance', 'buffer' => 'Buffer', 'nett_effective' => 'Nett Effective Balance'] as $k => $label) {
            $line = [$label];
            foreach ($summary as $s) {
                // Satu angka per bulan → ditaruh di kolom Actual, tiga kolom lain dikosongkan.
                $line[] = null;
                $line[] = null;
                $line[] = $s[$k];
                $line[] = null;
            }
            $line[] = null;
            $line[] = null;
            $writer->addRow($line, $num);
        }

        $writer->addRow([]);
        $writer->addMeta(
            'Beginning Balance bulan pertama diambil dari posisi kas/bank menurut jurnal sebelum 1 Januari '.$year
            .' (Rp '.number_format($opening, 0, ',', '.').'), bukan diinput ulang. Bulan berikutnya = Ending Balance bulan sebelumnya.',
            $colCount,
        );

        $path = tempnam(sys_get_temp_dir(), 'cfrep').'.xlsx';
        $writer->save($path);

        return response()->download($path, 'Report-Cashflow-'.$year.'-sd-'.str_pad((string) $upto, 2, '0', STR_PAD_LEFT).'-'.now()->format('Ymd-Hi').'.xlsx')
            ->deleteFileAfterSend(true);
    }

    /** @return array{0:int,1:int} [tahun, s/d bulan] */
    private function reportPeriod(Request $request): array
    {
        $year = (int) ($request->integer('year') ?: now()->year);
        $year = min(2100, max(2000, $year));
        $upto = min(12, max(1, (int) ($request->integer('upto') ?: ($year === now()->year ? now()->month : 12))));

        return [$year, $upto];
    }

    /**
     * Inti laporan: baris kategori + ringkasan per bulan. Dipakai bersama oleh tampilan layar
     * dan unduhan Excel supaya angkanya tidak mungkin berbeda.
     *
     * @return array{months:array<int,int>, rows:array<int,array<string,mixed>>, summary:array<int,array<string,mixed>>, opening:float}
     */
    private function reportData(int $year, int $upto): array
    {
        $months = range(1, $upto);

        $categories = BsCfCategory::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $catIds = $categories->pluck('id')->all();

        $actual   = $this->actualByCategoryMonth($year, $catIds);
        $actualPY = $this->actualByCategoryMonth(null, $catIds, 2022, $year);   // Σ 2022 s/d tahun ini
        $figures  = $this->figuresByCategoryMonth($year, $catIds);
        $settings = BsCfPeriodSetting::where('period_year', $year)->get()->keyBy('period_month');

        // Baris kategori, urut seksi → grup → sort_order (persis susunan lampiran).
        $rows = [];
        foreach (self::SECTIONS as $section => $sectionLabel) {
            $inSection = $categories->where('section', $section)->values();
            if ($inSection->isEmpty()) {
                continue;
            }
            $rows[] = ['kind' => 'section', 'label' => $sectionLabel, 'section' => $section];

            foreach ($inSection->groupBy(fn ($c) => (string) ($c->group_name ?? '')) as $group => $items) {
                if ($group !== '') {
                    $rows[] = ['kind' => 'group', 'label' => $group, 'section' => $section];
                }
                foreach ($items as $c) {
                    $rows[] = [
                        'kind' => 'category', 'id' => $c->id, 'label' => $c->name, 'section' => $section,
                        'cells' => collect($months)->map(fn ($m) => [
                            'wnpb'      => (float) ($figures[$c->id][$m]['wnpb'] ?? 0),
                            'projection' => (float) ($figures[$c->id][$m]['projection'] ?? 0),
                            'actual'    => (float) ($actual[$c->id][$m] ?? 0),
                        ])->values()->all(),
                        'total_ytd'   => round(array_sum(array_map(fn ($m) => (float) ($actual[$c->id][$m] ?? 0), $months)), 2),
                        'total_multi' => round((float) ($actualPY[$c->id] ?? 0), 2),
                    ];
                }
            }
        }

        // Ringkasan per bulan (rumus 1–3 + baris efektif pada lampiran).
        $sum = function (string $section, int $m, string $key) use ($rows) {
            $t = 0.0;
            foreach ($rows as $r) {
                if (($r['kind'] ?? '') === 'category' && $r['section'] === $section) {
                    $t += (float) ($r['cells'][$m - 1][$key] ?? 0);
                }
            }

            return round($t, 2);
        };

        $opening = $this->openingCashBalance($year);
        $summary = [];
        $begin = $opening;
        foreach ($months as $m) {
            $row = [];
            foreach (['wnpb', 'projection', 'actual'] as $k) {
                $inOps  = $sum('inflow_ops', $m, $k);
                $inNon  = $sum('inflow_non_ops', $m, $k);
                $out    = $sum('outflow', $m, $k);
                $row[$k] = [
                    'inflow_ops' => $inOps, 'inflow_non_ops' => $inNon, 'outflow' => $out,
                    // Lampiran memakai "Nett Cashplan Ops" untuk NETT keseluruhan; di sini dipecah
                    // supaya tiap baris jujur pada namanya dan jumlahnya tetap sama.
                    'nett_ops'   => round($inOps - $out, 2),
                    'nett_non_ops' => $inNon,
                    'nett'       => round($inOps + $inNon - $out, 2),
                ];
            }
            // Saldo mengikuti angka AKTUAL (rumus 1 & 3).
            $st = $settings->get($m);
            $blokir = (float) ($st->blokir_angsuran ?? 0);
            $buffer = (float) ($st->buffer ?? 0);
            $end = round($begin + $row['actual']['nett'], 2);
            $eff = round($end - $blokir, 2);

            $row['month']            = $m;
            $row['beginning']        = round($begin, 2);
            $row['ending']           = $end;
            $row['blokir_angsuran']  = $blokir;
            $row['buffer']           = $buffer;
            $row['effective']        = $eff;
            $row['nett_effective']   = round($eff - $buffer, 2);
            $summary[] = $row;
            $begin = $end;
        }

        return ['months' => $months, 'rows' => $rows, 'summary' => $summary, 'opening' => round($opening, 2)];
    }

    /** Simpan angka manual (WPNB / Proyeksi per kategori-bulan, Blokir Angsuran & Buffer per bulan). */
    public function reportSave(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        $v = $request->validate([
            'year'                    => ['required', 'integer', 'min:2000', 'max:2100'],
            'figures'                 => ['nullable', 'array', 'max:5000'],
            'figures.*.cf_category_id' => ['required', 'integer', 'exists:bs_cf_categories,id'],
            'figures.*.period_month'  => ['required', 'integer', 'min:1', 'max:12'],
            'figures.*.wnpb'          => ['nullable', 'numeric'],
            'figures.*.projection'    => ['nullable', 'numeric'],
            'settings'                => ['nullable', 'array', 'max:12'],
            'settings.*.period_month' => ['required', 'integer', 'min:1', 'max:12'],
            'settings.*.blokir_angsuran' => ['nullable', 'numeric'],
            'settings.*.buffer'       => ['nullable', 'numeric'],
        ]);

        $tenantId = tenant_id() ?? default_tenant_id();
        DB::transaction(function () use ($v, $tenantId): void {
            foreach (($v['figures'] ?? []) as $f) {
                BsCfFigure::updateOrCreate(
                    ['tenant_id' => $tenantId, 'cf_category_id' => (int) $f['cf_category_id'], 'period_year' => (int) $v['year'], 'period_month' => (int) $f['period_month']],
                    ['wnpb' => round((float) ($f['wnpb'] ?? 0), 2), 'projection' => round((float) ($f['projection'] ?? 0), 2)],
                );
            }
            foreach (($v['settings'] ?? []) as $s) {
                BsCfPeriodSetting::updateOrCreate(
                    ['tenant_id' => $tenantId, 'period_year' => (int) $v['year'], 'period_month' => (int) $s['period_month']],
                    ['blokir_angsuran' => round((float) ($s['blokir_angsuran'] ?? 0), 2), 'buffer' => round((float) ($s['buffer'] ?? 0), 2)],
                );
            }
        });

        return back()->with('success', 'Angka WPNB / Proyeksi & pengaturan bulan disimpan.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** Id akun kas/bank (sumber mutasi yang dibagi di Rekening Ledger). */
    private function cashAccountIds(): array
    {
        return BsAccount::where('is_active', true)->where('is_postable', true)
            ->where(fn ($q) => $q->where('code', 'like', '10101%')->orWhere('name', 'like', '%kas%')->orWhere('name', 'like', '%bank%'))
            ->pluck('id')->all();
    }

    /**
     * AKTUAL per kategori per bulan = Σ pembagian mutasi (Rekening Ledger → Simpan Pembagian).
     *
     * @return array<int,array<int,float>>|array<int,float>  [cat][bulan] bila $year diisi; [cat] bila rentang tahun
     */
    private function actualByCategoryMonth(?int $year, array $catIds, ?int $fromYear = null, ?int $toYear = null): array
    {
        if (! $catIds) {
            return [];
        }

        $q = DB::table('bs_ledger_cluster_splits as s')
            ->join('bs_journal_lines as l', 'l.id', '=', 's.journal_line_id')
            ->join('bs_journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.status', 'posted')
            ->whereNull('s.deleted_at')
            ->whereIn('s.cf_category_id', $catIds);

        if ($year !== null) {
            return $q->where('j.period_year', $year)
                ->groupBy('s.cf_category_id', 'j.period_month')
                ->selectRaw('s.cf_category_id, j.period_month, COALESCE(SUM(s.amount),0) as total')
                ->get()
                ->reduce(function (array $acc, $r) {
                    $acc[(int) $r->cf_category_id][(int) $r->period_month] = (float) $r->total;

                    return $acc;
                }, []);
        }

        return $q->whereBetween('j.period_year', [(int) $fromYear, (int) $toYear])
            ->groupBy('s.cf_category_id')
            ->selectRaw('s.cf_category_id, COALESCE(SUM(s.amount),0) as total')
            ->pluck('total', 'cf_category_id')
            ->map(fn ($v) => (float) $v)->all();
    }

    /** @return array<int,array<int,array{wnpb:float,projection:float}>> */
    private function figuresByCategoryMonth(int $year, array $catIds): array
    {
        if (! $catIds) {
            return [];
        }

        return BsCfFigure::where('period_year', $year)->whereIn('cf_category_id', $catIds)
            ->get(['cf_category_id', 'period_month', 'wnpb', 'projection'])
            ->reduce(function (array $acc, $f) {
                $acc[(int) $f->cf_category_id][(int) $f->period_month] = [
                    'wnpb' => (float) $f->wnpb, 'projection' => (float) $f->projection,
                ];

                return $acc;
            }, []);
    }

    /**
     * Beginning Balance bulan pertama = posisi kas/bank menurut GL SEBELUM 1 Januari tahun ybs.
     * Diambil dari jurnal, bukan diinput ulang, supaya tidak pernah berbeda dengan pembukuan.
     */
    private function openingCashBalance(int $year): float
    {
        $ids = $this->cashAccountIds();
        if (! $ids) {
            return 0.0;
        }

        return round((float) DB::table('bs_journal_lines as l')
            ->join('bs_journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.status', 'posted')->where('j.period_year', '<', $year)
            ->whereIn('l.account_id', $ids)
            ->sum(DB::raw('l.debit - l.credit')), 2);
    }
}
