<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\FixedAsset;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsFixedAsset;
use App\Domain\BusinessSupport\Services\FixedAssetService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class FixedAssetController extends Controller
{
    /** Label status aset (dipakai overview & report). */
    private const STATUSES = ['active' => 'Aktif', 'fully_depreciated' => 'Habis Susut', 'sold' => 'Terjual', 'disposed' => 'Dihapus'];

    /** Overview Aset — ringkasan seluruh modul Aset Tetap + daftar aset (filter per-kolom di client). */
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $assets = BsFixedAsset::orderBy('category')->latest('acquisition_date')->latest('id')->get();

        $rows = $assets->map(fn ($a) => [
            'id' => $a->id, 'asset_number' => $a->asset_number, 'name' => $a->name,
            'category' => $a->category, 'category_label' => BsFixedAsset::CATEGORIES[$a->category] ?? $a->category,
            'acquisition_date' => $a->acquisition_date?->toDateString(), 'acquisition_cost' => (float) $a->acquisition_cost,
            'accumulated_depreciation' => (float) $a->accumulated_depreciation, 'book_value' => $a->book_value,
            'status' => $a->status, 'pic' => $a->pic, 'location' => $a->location,
        ])->values();

        // SALDO MENGIKUTI NERACA SALDO (GL) — bukan penjumlahan kartu aset. Lihat glByCategory().
        $gl = $this->glByCategory();

        // Ringkasan per kategori. Harga Perolehan & Akumulasi diambil dari saldo akun GL agar
        // selalu sama dengan Neraca Saldo; jumlah unit tetap dari daftar aset (subledger).
        $byCategory = [];
        foreach (BsFixedAsset::CATEGORIES as $k => $label) {
            $grp = $assets->where('category', $k);
            $g   = $gl[$k] ?? ['cost' => 0.0, 'accum' => 0.0, 'asset_code' => null, 'accum_code' => null];
            // Kategori tetap ditampilkan bila GL bersaldo walau belum ada kartu asetnya, supaya
            // penjumlahan tabel selalu sama dengan total Neraca Saldo.
            if ($grp->isEmpty() && (float) $g['cost'] === 0.0 && (float) $g['accum'] === 0.0) {
                continue;
            }
            $subCost  = (float) $grp->sum(fn ($a) => (float) $a->acquisition_cost);
            $subAccum = (float) $grp->sum(fn ($a) => (float) $a->accumulated_depreciation);
            $byCategory[] = [
                'key' => $k, 'label' => $label, 'count' => $grp->count(),
                'cost' => (float) $g['cost'], 'accum' => (float) $g['accum'],
                'book' => round((float) $g['cost'] - (float) $g['accum'], 2),
                // Kode akun sumber + nilai subledger & selisihnya (untuk penelusuran bila berbeda).
                'asset_code'    => $g['asset_code'],
                'accum_code'    => $g['accum_code'],
                'sub_cost'      => $subCost,
                'sub_accum'     => $subAccum,
                'diff_cost'     => round((float) $g['cost'] - $subCost, 2),
                'diff_accum'    => round((float) $g['accum'] - $subAccum, 2),
            ];
        }

        $cost  = round((float) collect($byCategory)->sum('cost'), 2);
        $accum = round((float) collect($byCategory)->sum('accum'), 2);

        // Ringkasan per status.
        $byStatus = [];
        foreach (self::STATUSES as $k => $label) {
            $grp = $assets->where('status', $k);
            $byStatus[] = ['key' => $k, 'label' => $label, 'count' => $grp->count(), 'cost' => (float) $grp->sum(fn ($a) => (float) $a->acquisition_cost)];
        }

        $year = (int) now()->year;

        return Inertia::render('BusinessSupport/FixedAsset/Index', [
            'assets'     => $rows,
            'categories' => BsFixedAsset::CATEGORIES,
            'statuses'   => self::STATUSES,
            'byCategory' => $byCategory,
            'byStatus'   => $byStatus,
            'summary'    => [
                'count'         => $assets->count(),
                'cost'          => $cost,
                'accum'         => $accum,
                'book_value'    => $cost - $accum,
                'dep_this_year' => (float) \App\Domain\BusinessSupport\Models\BsDepreciationEntry::where('period_year', $year)->sum('amount'),
                'dep_year'      => $year,
                'sold_count'    => $assets->where('status', 'sold')->count(),
                'sold_proceeds' => (float) $assets->where('status', 'sold')->sum(fn ($a) => (float) $a->disposal_proceeds),
                'disposed_count'=> $assets->where('status', 'disposed')->count(),
            ],
        ]);
    }

    /**
     * Saldo GL (Neraca Saldo) per kategori aset tetap — SUMBER TUNGGAL angka Harga Perolehan
     * & Akumulasi Penyusutan pada Master/Overview Aset Tetap.
     *
     * Rumusnya SENGAJA disamakan persis dengan Neraca Saldo (LedgerReportController::trialBalanceData):
     * Σ(debit − credit) atas baris jurnal berstatus 'posted'. Karena dihitung ulang setiap halaman
     * dibuka, setiap jurnal baru (registrasi aset, penyusutan, penjualan/penghapusan, koreksi manual)
     * LANGSUNG tercermin — master aset tetap tidak pernah lagi menyimpang dari neraca saldo.
     *
     * Pemetaan kategori → akun diambil dari config business_support.fixed_asset:
     *   bangunan   → 10201010102 / akumulasi 10203030301
     *   kendaraan  → 10201010103 / akumulasi 10203030302
     *   inventaris → 10201010104 / akumulasi 10203030303
     * Akun akumulasi bersaldo normal KREDIT, jadi tandanya dibalik agar tampil positif.
     *
     * @return array<string, array{cost:float, accum:float, asset_code:?string, accum_code:?string}>
     */
    private function glByCategory(): array
    {
        $map = (array) config('business_support.fixed_asset', []);

        $codes = [];
        foreach ($map as $acc) {
            foreach (['asset', 'accum'] as $kind) {
                if (! empty($acc[$kind])) {
                    $codes[] = (string) $acc[$kind];
                }
            }
        }
        $idByCode = BsAccount::whereIn('code', array_values(array_unique($codes)))->pluck('id', 'code');

        $net = $idByCode->isEmpty() ? collect() : \App\Domain\BusinessSupport\Models\BsJournalLine::query()
            ->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            ->where('bs_journals.status', 'posted')
            ->whereIn('bs_journal_lines.account_id', $idByCode->values()->all())
            ->selectRaw('bs_journal_lines.account_id, COALESCE(SUM(bs_journal_lines.debit - bs_journal_lines.credit),0) as net')
            ->groupBy('bs_journal_lines.account_id')
            ->pluck('net', 'account_id');

        $out = [];
        foreach ($map as $cat => $acc) {
            $assetId = ! empty($acc['asset']) ? ($idByCode[$acc['asset']] ?? null) : null;
            $accumId = ! empty($acc['accum']) ? ($idByCode[$acc['accum']] ?? null) : null;

            $out[(string) $cat] = [
                'cost'       => $assetId ? round((float) ($net[$assetId] ?? 0), 2) : 0.0,
                // dibalik: akumulasi penyusutan bersaldo kredit
                'accum'      => $accumId ? round(-1 * (float) ($net[$accumId] ?? 0), 2) : 0.0,
                'asset_code' => $acc['asset'] ?? null,
                'accum_code' => $acc['accum'] ?? null,
            ];
        }

        return $out;
    }

    /** Report Penjualan/Pelepasan Aset — aset terjual/dihapus + laba/rugi, filter tanggal pelepasan. */
    public function penjualan(Request $request): Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);
        $rows = $this->penjualanRows($request);

        return Inertia::render('BusinessSupport/FixedAsset/Penjualan', [
            'rows'       => $rows,
            'categories' => BsFixedAsset::CATEGORIES,
            'filters'    => $request->only(['date_from', 'date_to', 'category']),
            'total'      => [
                'count'    => $rows->count(),
                'cost'     => round($rows->sum('cost'), 2),
                'book'     => round($rows->sum('book'), 2),
                'proceeds' => round($rows->sum('proceeds'), 2),
                'gain'     => round($rows->sum(fn ($r) => (float) ($r['gain'] ?? 0)), 2),
            ],
        ]);
    }

    private function penjualanRows(Request $request)
    {
        return BsFixedAsset::whereIn('status', ['sold', 'disposed'])
            ->when($request->input('category'), fn ($q, $v) => $q->where('category', $v))
            ->when($request->input('date_from'), fn ($q, $d) => $q->whereDate('disposed_date', '>=', $d))
            ->when($request->input('date_to'), fn ($q, $d) => $q->whereDate('disposed_date', '<=', $d))
            ->orderByDesc('disposed_date')->orderByDesc('id')->get()
            ->map(function ($a) {
                $book     = round((float) $a->acquisition_cost - (float) $a->accumulated_depreciation, 2);
                $proceeds = (float) $a->disposal_proceeds;

                return [
                    'id' => $a->id, 'asset_number' => $a->asset_number, 'name' => $a->name,
                    'category' => $a->category, 'category_label' => BsFixedAsset::CATEGORIES[$a->category] ?? $a->category,
                    'acquisition_date' => $a->acquisition_date?->toDateString(), 'cost' => (float) $a->acquisition_cost,
                    'accum' => (float) $a->accumulated_depreciation, 'book' => $book,
                    'disposed_date' => $a->disposed_date?->toDateString(), 'disposal_type' => $a->disposal_type ?? $a->status,
                    'proceeds' => $proceeds, 'gain' => $a->status === 'sold' ? round($proceeds - $book, 2) : null,
                    'status' => $a->status,
                ];
            })->values();
    }

    /** Export Excel Penjualan Aset (mengikuti filter). */
    public function penjualanExport(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);
        $rows = $this->penjualanRows($request)->map(fn ($r) => [
            $r['asset_number'], $r['name'], $r['category_label'], $r['acquisition_date'], $r['cost'], $r['accum'], $r['book'],
            $r['disposed_date'], $r['disposal_type'] === 'sold' || $r['status'] === 'sold' ? 'Jual' : 'Hapus', $r['proceeds'], $r['gain'],
        ]);

        return $this->xls('Penjualan Aset Tetap',
            ['No. Aset', 'Nama', 'Kategori', 'Tgl Perolehan', 'Harga', 'Akumulasi', 'Nilai Buku', 'Tgl Lepas', 'Jenis', 'Hasil Jual', 'Laba/(Rugi)'], $rows);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        return Inertia::render('BusinessSupport/FixedAsset/Form', [
            'categories'      => BsFixedAsset::CATEGORIES,
            'fundingAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)
                ->where(fn ($q) => $q->where('code', 'like', '101010%')->orWhere('type', 'liability'))
                ->orderBy('code')->get(['id', 'code', 'name', 'type']),
        ]);
    }

    public function store(Request $request, FixedAssetService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $v = $request->validate([
            'name'               => ['required', 'string', 'max:1000'],
            'quantity'           => ['nullable', 'string', 'max:32'],
            'category'           => ['required', 'in:bangunan,kendaraan,inventaris,tanah'],
            'acquisition_date'   => ['required', 'date'],
            'acquisition_cost'   => ['required', 'numeric', 'min:0.01'],
            'residual_value'     => ['nullable', 'numeric', 'min:0', 'lt:acquisition_cost'],
            'useful_life_months' => ['nullable', 'integer', 'min:0', 'max:1200'],
            'funding_account_id' => ['nullable', 'integer', 'exists:bs_accounts,id'],
            'pic'                => ['nullable', 'string', 'max:100'],
            'location'           => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $asset = $svc->register($v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.fa.show', $asset)->with('success', "Aset {$asset->asset_number} terdaftar.");
    }

    /** Form edit master aset — semua komponen (memakai halaman Form yang sama, mode edit). */
    public function edit(Request $request, BsFixedAsset $fixedAsset): Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        return Inertia::render('BusinessSupport/FixedAsset/Form', [
            'categories'      => BsFixedAsset::CATEGORIES,
            'fundingAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)
                ->where(fn ($q) => $q->where('code', 'like', '101010%')->orWhere('type', 'liability'))
                ->orderBy('code')->get(['id', 'code', 'name', 'type']),
            // acquisition_date sbg Y-m-d (bukan datetime UTC) agar prefill <input type=date> tidak geser 1 hari (TZ +7).
            'asset' => [
                'id'                 => $fixedAsset->id,
                'asset_number'       => $fixedAsset->asset_number,
                'name'               => $fixedAsset->name,
                'quantity'           => $fixedAsset->quantity,
                'category'           => $fixedAsset->category,
                'acquisition_date'   => $fixedAsset->acquisition_date?->toDateString(),
                'acquisition_cost'   => (float) $fixedAsset->acquisition_cost,
                'residual_value'     => (float) $fixedAsset->residual_value,
                'useful_life_months' => (int) $fixedAsset->useful_life_months,
                'pic'                => $fixedAsset->pic,
                'location'           => $fixedAsset->location,
            ],
        ]);
    }

    /**
     * Simpan perubahan master aset (semua komponen). Penyusutan/bulan & nilai buku = accessor → dihitung ulang
     * otomatis. TIDAK menyentuh jurnal perolehan / entri penyusutan yang sudah terbentuk (koreksi master saja).
     */
    public function update(Request $request, BsFixedAsset $fixedAsset): RedirectResponse
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $v = $request->validate([
            // #Master: nomor aset tetap boleh diedit — wajib unik (abaikan diri sendiri & yang sudah dihapus).
            'asset_number'       => ['required', 'string', 'max:50', \Illuminate\Validation\Rule::unique('bs_fixed_assets', 'asset_number')->ignore($fixedAsset->id)->whereNull('deleted_at')],
            'name'               => ['required', 'string', 'max:1000'],
            'quantity'           => ['nullable', 'string', 'max:32'],
            'category'           => ['required', 'in:bangunan,kendaraan,inventaris,tanah'],
            'acquisition_date'   => ['required', 'date'],
            'acquisition_cost'   => ['required', 'numeric', 'min:0.01'],
            'residual_value'     => ['nullable', 'numeric', 'min:0', 'lt:acquisition_cost'],
            'useful_life_months' => ['nullable', 'integer', 'min:0', 'max:1200'],
            'pic'                => ['nullable', 'string', 'max:100'],
            'location'           => ['nullable', 'string', 'max:100'],
        ]);

        $fixedAsset->update([
            'asset_number'       => trim($v['asset_number']),
            'name'               => $v['name'],
            'quantity'           => $v['quantity'] ?? null,
            'category'           => $v['category'],
            'acquisition_date'   => $v['acquisition_date'],
            'acquisition_cost'   => $v['acquisition_cost'],
            'residual_value'     => $v['residual_value'] ?? 0,
            'useful_life_months' => $v['category'] === 'tanah' ? 0 : ($v['useful_life_months'] ?? 0),
            'pic'                => $v['pic'] ?? null,
            'location'           => $v['location'] ?? null,
        ]);

        return redirect()->route('bs.fa.show', $fixedAsset)->with('success', "Aset {$fixedAsset->asset_number} diperbarui.");
    }

    public function show(Request $request, BsFixedAsset $fixedAsset): Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        return Inertia::render('BusinessSupport/FixedAsset/Show', [
            'asset'   => array_merge(
                // entries.journal: nomor jurnal jauh lebih berarti daripada id mentah — apalagi
                // sejak jurnal penyusutan digabung per kategori (1 jurnal dipakai banyak aset).
                $fixedAsset->load(['assetAccount:id,code,name', 'entries' => fn ($q) => $q->orderBy('period_year')->orderBy('period_month')->with('journal:id,journal_number')])->toArray(),
                // acquisition_date sbg Y-m-d agar default "Per Tanggal" saldo awal = Tgl Perolehan (tanpa geser 1 hari TZ +7).
                ['book_value' => $fixedAsset->book_value, 'monthly_depreciation' => $fixedAsset->monthly_depreciation, 'depreciable_base' => $fixedAsset->depreciable_base, 'acquisition_date' => $fixedAsset->acquisition_date?->toDateString()],
            ),
            'now'     => ['year' => (int) now()->year, 'month' => (int) now()->month],
            'today'   => now()->toDateString(),
            'cashAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)
                ->where('code', 'like', '101010%')->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function dispose(Request $request, BsFixedAsset $fixedAsset, FixedAssetService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $v = $request->validate([
            'disposed_date' => ['required', 'date'],
            'note'          => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $svc->dispose($fixedAsset, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.fa.show', $fixedAsset)->with('success', "Aset {$fixedAsset->asset_number} dihapus — jurnal pelepasan terbentuk.");
    }

    public function sell(Request $request, BsFixedAsset $fixedAsset, FixedAssetService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $v = $request->validate([
            'disposed_date'   => ['required', 'date'],
            'proceeds'        => ['required', 'numeric', 'min:0.01'],
            'cash_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            'note'            => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $svc->sell($fixedAsset, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.fa.show', $fixedAsset)->with('success', "Aset {$fixedAsset->asset_number} terjual — jurnal laba/rugi terbentuk.");
    }

    public function opening(Request $request, BsFixedAsset $fixedAsset, FixedAssetService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $v = $request->validate([
            'as_of_date' => ['required', 'date'],
            'amount'     => ['required', 'numeric', 'min:0.01'],
        ]);

        try {
            $svc->openingDepreciation($fixedAsset, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.fa.show', $fixedAsset)->with('success', 'Saldo awal akumulasi penyusutan tercatat.');
    }

    public function depreciate(Request $request, BsFixedAsset $fixedAsset, FixedAssetService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        // Rentang periode: bulan awal (year/month) s/d bulan akhir (end_year/end_month).
        // end_* opsional → bila kosong, hanya 1 bulan (kompatibel perilaku lama). Idempoten per bulan.
        $v = $request->validate([
            'year'      => ['required', 'integer', 'min:2000', 'max:2100'],
            'month'     => ['required', 'integer', 'min:1', 'max:12'],
            'end_year'  => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'end_month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $startY = (int) $v['year'];
        $startM = (int) $v['month'];
        $endY   = (int) ($v['end_year'] ?? $startY);
        $endM   = (int) ($v['end_month'] ?? $startM);
        $start  = $startY * 12 + ($startM - 1);
        $end    = $endY * 12 + ($endM - 1);
        if ($end < $start) {
            return back()->with('error', 'Bulan akhir penyusutan tidak boleh sebelum bulan awal.');
        }
        if ($end - $start > 120) {
            return back()->with('error', 'Rentang penyusutan maksimal 120 bulan.');
        }

        $posted = 0;
        try {
            for ($idx = $start; $idx <= $end; $idx++) {
                if ($svc->depreciate($fixedAsset, intdiv($idx, 12), ($idx % 12) + 1, $request->user()->id)) {
                    $posted++;
                }
            }
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        $range = $start === $end ? "{$startY}/{$startM}" : "{$startY}/{$startM} s/d {$endY}/{$endM}";

        return redirect()->route('bs.fa.show', $fixedAsset)->with($posted ? 'success' : 'error', $posted
            ? "Penyusutan {$range} terposting — {$posted} bulan (Dr Beban / Cr Akumulasi)."
            : "Tidak ada penyusutan baru untuk {$range} (sudah disusut / belum perolehan / nilai habis).");
    }

    public function run(Request $request): Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        return Inertia::render('BusinessSupport/FixedAsset/Run', [
            'now'      => ['year' => (int) now()->year, 'month' => (int) now()->month],
            'eligible' => BsFixedAsset::where('status', 'active')->where('category', '!=', 'tanah')->count(),
        ]);
    }

    public function runStore(Request $request, FixedAssetService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('bs'), 403);

        $v = $request->validate([
            'year'  => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        try {
            $res = $svc->runBatch($v['year'], $v['month'], $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Jurnal digabung per kategori/COA → sebutkan jumlah jurnalnya, bukan cuma jumlah aset.
        return redirect()->route('bs.fa.index')->with('success',
            "Penyusutan {$v['year']}/{$v['month']}: {$res['posted']} aset terposting (Rp ".number_format($res['total'], 0, ',', '.')
            .") dalam {$res['journals']} jurnal gabungan per kategori, {$res['skipped']} dilewati.");
    }

    /**
     * Report Summary Aset — jadwal penyusutan garis lurus (persis lampiran):
     * per kategori → per aset → per periode (tahunan lampau + bulanan tahun terpilih):
     * Jumlah Bulan, Beban Penyusutan, Akumulasi, Nilai Buku + subtotal per kategori & grand total.
     */
    public function summary(Request $request): Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);
        $d = $this->computeSchedule($request);

        return Inertia::render('BusinessSupport/FixedAsset/Summary', [
            'year'       => $d['year'],
            'periods'    => array_map(fn ($p) => ['key' => $p['key'], 'label' => $p['label']], $d['periods']),
            'groups'     => $d['groups'],
            'grand'      => $d['grand'],
            'categories' => BsFixedAsset::CATEGORIES,
            'filters'    => $request->only(['date_from', 'date_to', 'category', 'search']),
        ]);
    }

    /** Export Excel Summary Aset — jadwal penyusutan diratakan (flatten) per periode. */
    public function summaryExport(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->canModule('bs'), 403);
        $d = $this->computeSchedule($request);

        $header = ['No', 'Nama Aset', 'Jumlah', 'Nomer', 'Tanggal', 'Harga', 'Masa Manfaat (bln)', 'PIC', 'Lokasi', 'Peny./Bulan'];
        foreach ($d['periods'] as $p) {
            $header[] = $p['label'].' - Jml Bln';
            $header[] = $p['label'].' - Beban';
            $header[] = $p['label'].' - Akumulasi';
            $header[] = $p['label'].' - Nilai Buku';
        }
        $nc = count($header);

        $rows = [];
        foreach ($d['groups'] as $g) {
            $cat = array_fill(0, $nc, ''); $cat[1] = $g['label']; $rows[] = $cat;
            foreach ($g['assets'] as $a) {
                $r = [$a['no'], $a['name'], $a['quantity'], $a['asset_number'], $a['acquisition_date'], $a['acquisition_cost'], $a['useful_life_months'], $a['pic'], $a['location'], $a['monthly']];
                foreach ($a['cells'] as $c) {
                    $r[] = $c['months'] ?: ''; $r[] = $c['expense'] ?: ''; $r[] = $c['accum'] ?: ''; $r[] = $c['book'];
                }
                $rows[] = $r;
            }
            $st = array_fill(0, $nc, ''); $st[1] = 'SUB TOTAL '.$g['label']; $st[5] = $g['subtotal']['cost']; $st[9] = $g['subtotal']['monthly'];
            $i = 10;
            foreach ($g['subtotal']['cells'] as $c) { $st[$i + 1] = $c['expense'] ?: ''; $st[$i + 2] = $c['accum'] ?: ''; $st[$i + 3] = $c['book']; $i += 4; }
            $rows[] = $st;
        }
        $gr = array_fill(0, $nc, ''); $gr[1] = 'GRAND TOTAL'; $gr[5] = $d['grand']['cost'];
        $i = 10;
        foreach ($d['grand']['cells'] as $c) { $gr[$i + 1] = $c['expense'] ?: ''; $gr[$i + 2] = $c['accum'] ?: ''; $gr[$i + 3] = $c['book']; $i += 4; }
        $rows[] = $gr;

        return $this->xls('Summary Aset - Jadwal Penyusutan '.$d['year'], $header, $rows);
    }

    /** Bangun file .xls (HTML-table) kompatibel Excel. @param array<int,array<int,mixed>> $rows */
    private function xls(string $title, array $header, $rows): \Illuminate\Http\Response
    {
        $e = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $h = '<table border="1"><tr style="background:#0f766e;color:#fff">';
        foreach ($header as $c) {
            $h .= '<th>'.$e($c).'</th>';
        }
        $h .= '</tr>';
        foreach ($rows as $r) {
            $h .= '<tr>';
            foreach ($r as $c) {
                $h .= '<td>'.$e($c).'</td>';
            }
            $h .= '</tr>';
        }
        $h .= '</table>';
        $file = str_replace(' ', '-', $title).'-'.now()->format('Ymd-His').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body><h3>'.$e($title).'</h3>'.$h.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /**
     * Hitung jadwal penyusutan garis lurus (dipakai Summary Aset & exportnya).
     * Filter: year (kolom bulanan), date_from/date_to (tgl perolehan), category, search.
     *
     * @return array{year:int, periods:array<int,array<string,mixed>>, groups:array<int,mixed>, grand:array<string,mixed>}
     */
    private function computeSchedule(Request $request): array
    {
        $year   = (int) $request->integer('year', (int) now()->year);
        $assets = BsFixedAsset::whereIn('status', ['active', 'fully_depreciated'])
            ->when($request->input('category'), fn ($q, $v) => $q->where('category', $v))
            ->when($request->input('date_from'), fn ($q, $d) => $q->whereDate('acquisition_date', '>=', $d))
            ->when($request->input('date_to'), fn ($q, $d) => $q->whereDate('acquisition_date', '<=', $d))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('asset_number', 'like', "%{$s}%")))
            ->orderBy('category')->orderBy('acquisition_date')->orderBy('id')->get();

        // Periode: tahunan dari tahun perolehan terlama (maks 6 th ke belakang) s/d year-1, lalu bulanan Jan-Des year.
        $minYear   = (int) ($assets->min(fn ($a) => (int) $a->acquisition_date->year) ?: $year);
        $startYear = max($minYear, $year - 6);
        $mn        = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $periods   = [];
        for ($y = $startYear; $y < $year; $y++) {
            $periods[] = ['key' => "y{$y}", 'label' => "Tahun {$y}", 'end' => Carbon::create($y, 12, 1)->endOfMonth()];
        }
        for ($m = 1; $m <= 12; $m++) {
            $periods[] = ['key' => "m{$year}-{$m}", 'label' => $mn[$m - 1]." {$year}", 'end' => Carbon::create($year, $m, 1)->endOfMonth()];
        }
        $pc = count($periods);

        // Akumulasi & jumlah bulan penyusutan tercatat sampai akhir suatu periode.
        $accumAt = function (BsFixedAsset $a, Carbon $end): array {
            $monthly = $a->monthly_depreciation;
            if ($monthly <= 0 || $a->acquisition_date->gt($end)) {
                return ['months' => 0, 'accum' => 0.0];
            }
            $months = ($end->year - $a->acquisition_date->year) * 12 + ($end->month - $a->acquisition_date->month) + 1;
            $months = max(0, min($months, (int) $a->useful_life_months));

            return ['months' => $months, 'accum' => min(round($months * $monthly, 2), $a->depreciable_base)];
        };
        $blank = fn () => array_fill(0, $pc, ['expense' => 0.0, 'accum' => 0.0, 'book' => 0.0]);
        $add   = function (array $agg, int $i, float $e, float $ac, float $bk): array {
            $agg[$i] = ['expense' => $agg[$i]['expense'] + $e, 'accum' => $agg[$i]['accum'] + $ac, 'book' => $agg[$i]['book'] + $bk];

            return $agg;
        };

        $groups = [];
        $grandCost = 0.0; $grandCells = $blank(); $no = 0;
        foreach (BsFixedAsset::CATEGORIES as $catKey => $catLabel) {
            $catAssets = $assets->where('category', $catKey)->values();
            if ($catAssets->isEmpty()) {
                continue;
            }
            $rows = []; $subCost = 0.0; $subMonthly = 0.0; $subCells = $blank();
            foreach ($catAssets as $a) {
                $no++;
                $cost = (float) $a->acquisition_cost;
                $subCost += $cost; $grandCost += $cost; $subMonthly += $a->monthly_depreciation;
                $cells = []; $prevAccum = 0.0; $prevMonths = 0;
                foreach ($periods as $i => $p) {
                    $at      = $accumAt($a, $p['end']);
                    $expense = round($at['accum'] - $prevAccum, 2);
                    $book    = round($cost - $at['accum'], 2);
                    $cells[] = ['months' => max(0, $at['months'] - $prevMonths), 'expense' => $expense, 'accum' => $at['accum'], 'book' => $book];
                    $subCells   = $add($subCells, $i, $expense, $at['accum'], $book);
                    $grandCells = $add($grandCells, $i, $expense, $at['accum'], $book);
                    $prevAccum = $at['accum']; $prevMonths = $at['months'];
                }
                $rows[] = [
                    'no' => $no, 'name' => $a->name, 'quantity' => $a->quantity, 'asset_number' => $a->asset_number,
                    'acquisition_date' => $a->acquisition_date->toDateString(), 'acquisition_cost' => $cost,
                    'useful_life_months' => (int) $a->useful_life_months, 'pic' => $a->pic, 'location' => $a->location,
                    'monthly' => $a->monthly_depreciation, 'cells' => $cells,
                ];
            }
            $groups[] = ['category' => $catKey, 'label' => $catLabel, 'assets' => $rows, 'subtotal' => ['cost' => round($subCost, 2), 'monthly' => round($subMonthly, 2), 'cells' => $subCells]];
        }

        return [
            'year'    => $year,
            'periods' => $periods,
            'groups'  => $groups,
            'grand'   => ['cost' => round($grandCost, 2), 'cells' => $grandCells],
        ];
    }
}
