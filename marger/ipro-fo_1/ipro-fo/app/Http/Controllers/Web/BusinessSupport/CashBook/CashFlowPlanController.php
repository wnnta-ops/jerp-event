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
use App\Support\Xlsx\SimpleXlsx;
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

        // Satuan mengikuti layar: JUTAAN RUPIAH. Berkas ini dan Report Cashflow harus terbaca
        // sama, jangan sampai orang membandingkan dua angka yang ternyata beda satuan.
        $jt = fn ($v) => $v === null ? null : round(((float) $v) / 1_000_000, 2);

        $writer->addTitle('REPORT CASHFLOW '.$year.' (DALAM JUTAAN RUPIAH)', $colCount);
        $writer->addMeta(
            'Periode Januari–'.ucfirst(mb_strtolower($MON[$upto])).' '.$year
            .' · diunduh '.now()->translatedFormat('d F Y H:i').' · '.($request->user()?->name ?? '')
            .' · SELURUH ANGKA DALAM JUTAAN RUPIAH (2.000 = Rp 2.000.000.000)'
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
                $line[] = $jt($c['wnpb']);
                $line[] = $jt($c['projection']);
                $line[] = $jt($c['actual']);
                $line[] = $jt($c['projection'] - $c['actual']);   // Gap = Proyeksi − Actual (rumus 7)
            }
            $line[] = $jt($r['total_ytd'] ?? 0);
            $line[] = $jt($r['total_multi'] ?? 0);
            $writer->addRow($line, $num);
        }

        foreach (['nett_ops' => 'Nett Cashplan Ops', 'nett_non_ops' => 'Nett Cashplan Non Ops', 'nett' => 'Nett Cashflow'] as $k => $label) {
            $line = [$label];
            foreach ($summary as $s) {
                $line[] = $jt($s['wnpb'][$k]);
                $line[] = $jt($s['projection'][$k]);
                $line[] = $jt($s['actual'][$k]);
                $line[] = $jt($s['projection'][$k] - $s['actual'][$k]);
            }
            $line[] = $jt(array_sum(array_map(fn ($s) => (float) $s['actual'][$k], $summary)));
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
                $line[] = $jt($s[$k]);
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

    // ── Import massal angka cashflow periode sebelumnya ───────────────────

    /** Judul kolom template import — dipakai saat menulis maupun membaca berkas. */
    private const IMPORT_HEADER = [
        'KODE KATEGORI', 'NAMA KATEGORI', 'TAHUN', 'BULAN', 'WPNB (JUTAAN RUPIAH)', 'PROYEKSI (JUTAAN RUPIAH)',
    ];

    /** Pengali satuan berkas import: isi berkas dalam JUTAAN, tersimpan dalam rupiah penuh. */
    private const JUTA = 1_000_000;

    /**
     * Unduh template import — sudah terisi SELURUH kategori aktif × 12 bulan untuk tahun yang
     * diminta, beserta angka yang sudah tersimpan. Jadi berkas ini sekaligus berfungsi sebagai
     * salinan tahun berjalan: unduh → sunting di Excel → unggah kembali.
     *
     * Kolom TAHUN sengaja ada di tiap baris supaya SATU berkas bisa memuat BEBERAPA tahun
     * sekaligus — persis kebutuhan "import periode sebelumnya" yang biasanya lebih dari setahun.
     */
    public function reportTemplate(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $year = (int) ($request->integer('year') ?: now()->year);
        $categories = BsCfCategory::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $figures = $this->figuresByCategoryMonth($year, $categories->pluck('id')->all());

        $rows = [self::IMPORT_HEADER];
        foreach ($categories as $c) {
            for ($m = 1; $m <= 12; $m++) {
                $f = $figures[$c->id][$m] ?? null;
                // Ditulis dalam JUTAAN — satuan yang sama dengan tabel Report Cashflow, supaya
                // angka yang dilihat di layar dan yang disunting di berkas tidak berbeda satuan.
                $rows[] = [
                    (string) $c->code,
                    (string) $c->name,
                    $year,
                    $m,
                    round(((float) ($f['wnpb'] ?? 0)) / self::JUTA, 6),
                    round(((float) ($f['projection'] ?? 0)) / self::JUTA, 6),
                ];
            }
        }

        // Berkas tanpa kategori pun tetap punya satu baris contoh, supaya bentuknya terlihat.
        if ($categories->isEmpty()) {
            $rows[] = ['CONTOH-01', 'Nama kategori sesuai Set Up Kategori', $year, 1, 0, 0];
        }

        return response(SimpleXlsx::toString($rows), 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Template-Import-Cashflow-'.$year.'.xlsx"',
        ]);
    }

    /**
     * Import massal WPNB & Proyeksi dari .xlsx/.xls/.csv.
     *
     * Kategori diresolusi dari KODE (bila kosong, dari NAMA). Angka ditulis dengan updateOrCreate
     * pada kunci (tenant, kategori, tahun, bulan) sehingga meng-import ulang berkas yang sama
     * memperbarui baris yang ada — tidak pernah menggandakan.
     *
     * SATUAN: berkas ditulis dalam JUTAAN rupiah — sama dengan tabel Report Cashflow — lalu
     * dikalikan sejuta saat disimpan (basis data tetap menyimpan rupiah penuh).
     *
     * Berkas TEMPLATE LAMA yang judul kolomnya bertulis "RUPIAH PENUH" tetap dibaca sebagai
     * rupiah penuh. Tanpa pengecualian ini, mengunggah berkas lama akan menggandakan angkanya
     * sejuta kali TANPA terlihat. Satuan yang dipakai selalu disebut pada pesan hasil import.
     */
    public function reportImport(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $request->validate(['file' => ['required', 'file', 'max:8192']]);

        $upload = $request->file('file');
        $ext = strtolower((string) $upload->getClientOriginalExtension());

        try {
            if (in_array($ext, ['xlsx', 'xls'], true)) {
                $rows = SimpleXlsx::rows($upload->getRealPath());
            } elseif (in_array($ext, ['csv', 'txt'], true)) {
                // $escape diisi eksplisit — sejak PHP 8.4 nilai bawaannya dideprekasi.
                $rows = array_map(
                    fn ($l) => str_getcsv($l, ',', '"', '\\'),
                    file($upload->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
                );
            } else {
                return back()->with('error', 'Format file harus .xlsx, .xls, atau .csv. Unduh template untuk format yang benar.');
            }
        } catch (\Throwable $e) {
            return back()->with('error', 'File tidak dapat dibaca: '.$e->getMessage());
        }

        $rows = array_values(array_filter((array) $rows, fn ($r) => is_array($r) && trim(implode('', array_map('strval', $r))) !== ''));
        if (count($rows) < 2) {
            return back()->with('error', 'File kosong atau hanya berisi baris header.');
        }

        $header = array_map(fn ($h) => strtoupper(trim((string) $h)), array_shift($rows));
        $find = function (callable $pred) use ($header): ?int {
            foreach ($header as $i => $h) {
                if ($pred($h)) {
                    return $i;
                }
            }

            return null;
        };

        $iCode = $find(fn ($h) => str_contains($h, 'KODE'));
        $iName = $find(fn ($h) => str_contains($h, 'NAMA') || str_contains($h, 'KATEGORI') && ! str_contains($h, 'KODE'));
        $iYear = $find(fn ($h) => str_contains($h, 'TAHUN') || str_contains($h, 'YEAR'));
        $iMon = $find(fn ($h) => str_contains($h, 'BULAN') || str_contains($h, 'MONTH'));
        $iWnpb = $find(fn ($h) => str_contains($h, 'WPNB') || str_contains($h, 'WNPB'));
        $iProj = $find(fn ($h) => str_contains($h, 'PROYEKSI') || str_contains($h, 'PROJECTION'));

        // Satuan berkas: JUTAAN (baku, sesuai template baru). Berkas template LAMA yang judul
        // kolomnya bertulis "RUPIAH PENUH" tetap dibaca apa adanya — kalau tidak, angkanya
        // akan tergandakan sejuta kali tanpa terlihat.
        $judulNilai = trim(($iWnpb !== null ? $header[$iWnpb] : '').' '.($iProj !== null ? $header[$iProj] : ''));
        $satuanJuta = ! str_contains($judulNilai, 'RUPIAH PENUH');

        $missing = [];
        if ($iCode === null && $iName === null) { $missing[] = 'Kode/Nama Kategori'; }
        if ($iYear === null) { $missing[] = 'Tahun'; }
        if ($iMon === null) { $missing[] = 'Bulan'; }
        if ($iWnpb === null && $iProj === null) { $missing[] = 'WPNB / Proyeksi'; }
        if ($missing) {
            return back()->with('error', 'Kolom wajib tidak ditemukan: '.implode(', ', $missing).'. Unduh template untuk format yang benar.');
        }

        // Peta kategori: kode & nama sama-sama diterima, dicocokkan tanpa peduli besar-kecil huruf.
        $categories = BsCfCategory::get(['id', 'code', 'name']);
        $byCode = $categories->keyBy(fn ($c) => mb_strtoupper(trim((string) $c->code)));
        $byName = $categories->keyBy(fn ($c) => mb_strtoupper(trim((string) $c->name)));

        // Terima "2000000", "1234567.89", "1.234.567,89", "Rp 2.000.000", dan "(1.000)" (negatif).
        $angka = function (?string $v): float {
            $v = trim((string) $v);
            if ($v === '') {
                return 0.0;
            }
            $negatif = str_starts_with($v, '(') && str_ends_with($v, ')');
            $v = preg_replace('/[^0-9.,\-]/', '', $v) ?? '';   // buang "Rp", spasi, dsb.
            if ($v === '') {
                return 0.0;
            }

            $adaKoma = str_contains($v, ',');
            $adaTitik = str_contains($v, '.');
            if ($adaKoma && $adaTitik) {
                // Format Indonesia: titik ribuan, koma desimal.
                $v = str_replace(['.', ','], ['', '.'], $v);
            } elseif ($adaKoma) {
                $v = str_replace(',', '.', $v);
            } elseif ($adaTitik) {
                // Titik saja bisa berarti ribuan ("2.000.000") atau desimal ("1234.56").
                // Dianggap RIBUAN bila tiap kelompok setelah titik pertama tepat 3 digit —
                // dengan begitu "2.000.000" → 2000000 sementara "1234.56" tetap desimal.
                $bagian = explode('.', $v);
                $ribuan = count($bagian) > 1;
                foreach (array_slice($bagian, 1) as $b) {
                    if (! preg_match('/^\d{3}$/', $b)) {
                        $ribuan = false;
                        break;
                    }
                }
                if ($ribuan) {
                    $v = implode('', $bagian);
                }
            }

            $n = (float) $v;

            return $negatif ? -abs($n) : $n;
        };

        $tenantId = tenant_id() ?? default_tenant_id();
        $masuk = 0;
        $lewat = [];
        $tahunSet = [];

        DB::transaction(function () use ($rows, $iCode, $iName, $iYear, $iMon, $iWnpb, $iProj, $byCode, $byName, $angka, $tenantId, $satuanJuta, &$masuk, &$lewat, &$tahunSet): void {
            foreach ($rows as $n => $r) {
                $baris = $n + 2;   // +1 header, +1 karena manusia menghitung dari 1
                $code = $iCode !== null ? mb_strtoupper(trim((string) ($r[$iCode] ?? ''))) : '';
                $name = $iName !== null ? mb_strtoupper(trim((string) ($r[$iName] ?? ''))) : '';
                $cat = ($code !== '' ? $byCode->get($code) : null) ?? ($name !== '' ? $byName->get($name) : null);
                if (! $cat) {
                    $lewat[] = "baris {$baris}: kategori '".($code ?: $name)."' tidak dikenal";

                    continue;
                }

                $year = (int) preg_replace('/\D/', '', (string) ($r[$iYear] ?? ''));
                $month = (int) preg_replace('/\D/', '', (string) ($r[$iMon] ?? ''));
                if ($year < 2000 || $year > 2100) {
                    $lewat[] = "baris {$baris}: tahun '{$year}' di luar 2000–2100";

                    continue;
                }
                if ($month < 1 || $month > 12) {
                    $lewat[] = "baris {$baris}: bulan '{$month}' harus 1–12";

                    continue;
                }

                // Isi berkas JUTAAN → disimpan rupiah penuh.
                $kali = $satuanJuta ? self::JUTA : 1;
                BsCfFigure::updateOrCreate(
                    ['tenant_id' => $tenantId, 'cf_category_id' => (int) $cat->id, 'period_year' => $year, 'period_month' => $month],
                    [
                        'wnpb'       => round(($iWnpb !== null ? $angka($r[$iWnpb] ?? null) : 0) * $kali, 2),
                        'projection' => round(($iProj !== null ? $angka($r[$iProj] ?? null) : 0) * $kali, 2),
                    ],
                );
                $masuk++;
                $tahunSet[$year] = true;
            }
        });

        if ($masuk === 0) {
            return back()->with('error', 'Tidak ada baris yang bisa diimport. '.($lewat ? implode('; ', array_slice($lewat, 0, 5)) : ''));
        }

        ksort($tahunSet);
        // Satuan yang dipakai SELALU disebut — salah satuan langsung ketahuan operator.
        $satuan = $satuanJuta ? 'jutaan rupiah' : 'rupiah penuh';
        $msg = "{$masuk} baris angka cashflow diimport (tahun ".implode(', ', array_keys($tahunSet))
            .", dibaca dalam {$satuan}).";
        if ($lewat) {
            // Sebutkan yang dilewati — jangan sampai berkas terpotong diam-diam dianggap sukses penuh.
            $msg .= ' '.count($lewat).' baris dilewati: '.implode('; ', array_slice($lewat, 0, 5)).(count($lewat) > 5 ? ' …' : '');

            return back()->with('error', $msg);
        }

        return back()->with('success', $msg);
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
