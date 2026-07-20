<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Gl;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsFiscalPeriod;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Models\BsJournalLine;
use App\Domain\BusinessSupport\Services\JournalPartyResolver;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JournalController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        // Filter periode tanggal — default bulan berjalan; mencakup SEMUA jenis sumber jurnal.
        $from = $request->date('date_from') ?? now()->startOfMonth();
        $to   = $request->date('date_to')   ?? now()->endOfMonth();

        $rows = BsJournal::query()
            ->with(['lines.account:id,code,name', 'costCenter:id,code,name'])
            ->withCount('lines')
            ->whereBetween('journal_date', [$from->toDateString(), $to->toDateString()])
            ->when($request->input('source'), fn ($q, $v) => $q->where('source', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('journal_number', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")))
            ->latest('journal_date')->latest('id')
            ->paginate(50)->withQueryString();

        // Master → "Jurnal" = ringkas (rincian tersembunyi). Query → "Detail Transaksi Jurnal" = rincian tampil.
        $isDetail = $request->routeIs('bs.gl.journal.detail');

        return Inertia::render('BusinessSupport/Gl/Journals/Index', [
            'journals'      => $rows,
            'sources'       => BsJournal::SOURCES,
            'statuses'      => BsJournal::STATUSES,
            'defaultDetail' => $isDetail,
            'listRoute'     => $isDetail ? 'bs.gl.journal.detail' : 'bs.gl.journal.index',
            'title'         => $isDetail ? 'Detail Transaksi Jurnal' : 'Jurnal',
            'canApprove'    => (bool) $request->user()?->can('businesssupport.approval.act'),
            'filters'  => array_merge(
                $request->only(['source', 'status', 'search']),
                ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()],
            ),
        ]);
    }

    /**
     * Finalisasi Jurnal — daftar jurnal "Baru" (draft) per periode + rincian, lalu finalisasi
     * (batch) yang sudah balance. Draft = Jurnal Memorial manual yang belum difinalisasi.
     */
    public function finalisasi(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $from = $request->date('date_from') ?? now()->startOfMonth();
        $to   = $request->date('date_to')   ?? now()->endOfMonth();

        $rows = BsJournal::query()
            ->with(['lines.account:id,code,name', 'costCenter:id,code,name', 'createdBy:id,name'])
            ->withCount('lines')
            ->where('status', 'draft')
            ->whereBetween('journal_date', [$from->toDateString(), $to->toDateString()])
            ->when($request->input('source'), fn ($q, $v) => $q->where('source', $v))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('journal_number', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")))
            ->latest('journal_date')->latest('id')
            ->paginate(100)->withQueryString();

        return Inertia::render('BusinessSupport/Gl/Journals/Finalisasi', [
            'journals' => $rows,
            'sources'  => BsJournal::SOURCES,
            'filters'  => array_merge($request->only(['source', 'search']), ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()]),
        ]);
    }

    /**
     * Approval Jurnal — grid batch ala legacy. Default menampilkan jurnal "Final" (belum di-approve);
     * bisa dialihkan ke "Approved" untuk membatalkan approval. Approve = efektif di GL.
     */
    public function approval(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $from = $request->date('date_from') ?? now()->startOfMonth();
        $to   = $request->date('date_to')   ?? now()->endOfMonth();
        // Tab status: 'final' (menunggu approval) | 'posted' (sudah approved → bisa dibatalkan).
        $status = in_array($request->input('status'), ['final', 'posted'], true) ? $request->input('status') : 'final';

        $rows = BsJournal::query()
            ->with(['costCenter:id,code,name', 'createdBy:id,name'])
            ->withCount('lines')
            ->where('status', $status)
            ->whereBetween('journal_date', [$from->toDateString(), $to->toDateString()])
            ->when($request->input('source'), fn ($q, $v) => $q->where('source', $v))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('journal_number', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")))
            ->orderBy('journal_date')->orderBy('id')
            ->paginate(100)->withQueryString();

        $counts = [
            'final'  => (int) BsJournal::where('status', 'final')->whereBetween('journal_date', [$from->toDateString(), $to->toDateString()])->count(),
            'posted' => (int) BsJournal::where('status', 'posted')->whereBetween('journal_date', [$from->toDateString(), $to->toDateString()])->count(),
        ];

        return Inertia::render('BusinessSupport/Gl/Journals/Approval', [
            'journals'   => $rows,
            'sources'    => BsJournal::SOURCES,
            'statuses'   => BsJournal::STATUSES,
            'statusTab'  => $status,
            'counts'     => $counts,
            'canApprove' => (bool) $request->user()?->can('businesssupport.approval.act'),
            'filters'    => array_merge($request->only(['source', 'search']), ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()]),
        ]);
    }

    /** Batch finalisasi: draft (balance) → final. */
    public function finalizeBatch(Request $request, JournalService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        return $this->batch($request, fn (BsJournal $j) => $svc->finalize($j), 'difinalisasi (Final)');
    }

    /** Batch approval: final → posted (efektif di GL). */
    public function approveBatch(Request $request, JournalService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $uid = $request->user()->id;

        return $this->batch($request, fn (BsJournal $j) => $svc->approve($j, $uid), 'di-approve (efektif di GL)');
    }

    /** Batch cancel approval: posted → final (batalkan approval). */
    public function cancelBatch(Request $request, JournalService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        return $this->batch($request, fn (BsJournal $j) => $svc->unapprove($j), 'dibatalkan approval-nya');
    }

    /**
     * Jalankan aksi batch atas daftar id jurnal; hitung sukses & gagal (gagal tidak menghentikan sisanya).
     *
     * @param  \Closure(BsJournal):mixed  $action
     */
    private function batch(Request $request, \Closure $action, string $verb): RedirectResponse
    {
        $v = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $ok = 0;
        $fail = 0;
        $lastError = null;
        foreach (BsJournal::whereIn('id', $v['ids'])->get() as $journal) {
            try {
                $action($journal);
                $ok++;
            } catch (BusinessRuleException $e) {
                $fail++;
                $lastError = $e->getMessage();
            }
        }

        $msg = "{$ok} jurnal {$verb}.";
        if ($fail > 0) {
            $msg .= " {$fail} gagal".($lastError ? " (mis. {$lastError})" : '').'.';
        }

        return back()->with($fail > 0 && $ok === 0 ? 'error' : 'success', $msg);
    }

    /** Export Excel: semua info Detail Transaksi Jurnal (header + semua baris akun) mengikuti filter layar. */
    public function export(Request $request, JournalPartyResolver $party): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $from = $request->date('date_from') ?? now()->startOfMonth();
        $to   = $request->date('date_to')   ?? now()->endOfMonth();

        // Filter rentang kode akun — dipakai saat export dari Buku Besar ("Dari COA .. Sampai COA").
        $fromCode = trim((string) $request->input('from_code', ''));
        $toCode   = trim((string) $request->input('to_code', ''));
        $lo = $hi = '';
        if ($fromCode !== '' || $toCode !== '') {
            $lo = $fromCode !== '' ? $fromCode : $toCode;
            $hi = $toCode !== '' ? $toCode : $fromCode;
            if (strcmp($lo, $hi) > 0) {
                [$lo, $hi] = [$hi, $lo];
            }
        }

        $journals = BsJournal::query()
            ->with(['lines.account:id,code,name', 'costCenter:id,code,name'])
            ->whereBetween('journal_date', [$from->toDateString(), $to->toDateString()])
            ->when($request->input('source'), fn ($q, $v) => $q->where('source', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('journal_number', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")))
            ->when($lo !== '', fn ($q) => $q->whereHas('lines.account', fn ($a) => $a->whereBetween('code', [$lo, $hi])))
            ->latest('journal_date')->latest('id')
            ->get();

        $e  = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES);
        // Format angka ala legacy (gambar acuan): ribuan koma, tanpa desimal, 0 → "-".
        $rp = fn ($v) => abs((float) ($v ?? 0)) < 0.005 ? '-' : number_format((float) $v, 0, '.', ',');
        $src = BsJournal::SOURCES;
        $st  = BsJournal::STATUSES;

        // Pihak Terkait — nama supplier/employee/customer di balik tiap jurnal. Di-resolve
        // sekali untuk SELURUH jurnal periode ini (batch), bukan per baris → bukan N+1.
        $pihak = $party->forJournalIds($journals->pluck('id')->all());

        // Kumpulkan semua baris (jurnal × baris) lalu urutkan PER COA: kode akun naik,
        // di dalam akun tanggal terbaru dulu (sesuai format legacy), saldo berjalan kumulatif.
        $rows = [];
        foreach ($journals as $j) {
            foreach ($j->lines as $ln) {
                // Bila export per rentang akun: hanya baris yang akunnya di dalam rentang.
                $code = (string) $ln->account?->code;
                if ($lo !== '' && ($code < $lo || $code > $hi)) {
                    continue;
                }
                $rows[] = [
                    'code'   => $code,
                    'name'   => (string) $ln->account?->name,
                    'jno'    => $j->journal_number,
                    'date'   => optional($j->journal_date)->toDateString(),
                    'source' => $src[$j->source] ?? $j->source,
                    'pihak'  => $pihak[$j->id] ?? '',
                    'status' => $st[$j->status] ?? $j->status,
                    'desc'   => $j->description,
                    'ref'    => $j->reference,
                    'cc'     => $j->costCenter?->name,
                    'ldesc'  => $ln->description,
                    'debit'  => (float) $ln->debit,
                    'credit' => (float) $ln->credit,
                    'jid'    => (int) $j->id,
                    'lid'    => (int) $ln->id,
                ];
            }
        }
        usort($rows, fn ($a, $b) => [$a['code'], $b['date'], $b['jid'], $b['lid']] <=> [$b['code'], $a['date'], $a['jid'], $a['lid']]);

        // Saldo Awal per COA = akumulasi mutasi SEBELUM tanggal awal periode (ikut filter sumber/status/rentang akun).
        $openings = BsJournalLine::query()
            ->join('bs_journals as j', 'j.id', '=', 'bs_journal_lines.journal_id')
            ->join('bs_accounts as a', 'a.id', '=', 'bs_journal_lines.account_id')
            ->whereNull('j.deleted_at')
            ->whereDate('j.journal_date', '<', $from->toDateString())
            ->when($request->input('source'), fn ($q, $v) => $q->where('j.source', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('j.status', $v))
            ->when($lo !== '', fn ($q) => $q->whereBetween('a.code', [$lo, $hi]))
            ->groupBy('a.code')
            ->selectRaw('a.code, COALESCE(SUM(bs_journal_lines.debit - bs_journal_lines.credit),0) as bal')
            ->pluck('bal', 'code');

        // Urutan kolom: Kode Akun & Nama Akun paling kiri, lalu Pihak Terkait tepat setelah Sumber,
        // Saldo berjalan paling kanan. Tiap akhir COA: baris "Mutasi Saldo" + "Saldo Akhir".
        $h = '<table border="1"><tr style="background:#0f766e;color:#fff">'
            .'<th>Kode Akun</th><th>Nama Akun</th>'
            .'<th>No. Jurnal</th><th>Tanggal</th><th>Sumber</th><th>Pihak Terkait</th><th>Status</th><th>Keterangan</th><th>Referensi</th>'
            .'<th>Cost Center</th><th>Keterangan Baris</th><th>Debit</th><th>Kredit</th><th>Saldo</th></tr>';
        $totalD = 0.0;
        $totalK = 0.0;
        $accD = $accK = $saldo = 0.0;
        $prevCode = null;
        $closeAcc = function () use (&$h, &$accD, &$accK, &$saldo, $rp): void {
            $h .= '<tr style="font-weight:bold"><td colspan="11">Mutasi Saldo</td><td>'.$rp($accD).'</td><td>'.$rp($accK).'</td><td></td></tr>'
                .'<tr style="font-weight:bold"><td colspan="11">Saldo Akhir</td><td></td><td></td><td>'.$rp($saldo).'</td></tr>';
            $accD = $accK = $saldo = 0.0;
        };
        foreach ($rows as $r) {
            if ($r['code'] !== $prevCode) {
                if ($prevCode !== null) {
                    $closeAcc();
                }
                // Buka blok COA: baris "Saldo Awal" (saldo sebelum periode), saldo berjalan mulai dari sini.
                $saldo = (float) ($openings[$r['code']] ?? 0);
                $h .= '<tr style="font-weight:bold"><td colspan="11">Saldo Awal</td><td>'.$rp(0).'</td><td>'.$rp(0).'</td><td>'.$rp($saldo).'</td></tr>';
            }
            $prevCode = $r['code'];
            $accD   += $r['debit'];
            $accK   += $r['credit'];
            $saldo  += $r['debit'] - $r['credit'];
            $totalD += $r['debit'];
            $totalK += $r['credit'];
            $h .= '<tr>'
                .'<td>'.$e($r['code']).'</td>'
                .'<td>'.$e($r['name']).'</td>'
                .'<td>'.$e($r['jno']).'</td>'
                .'<td>'.$e($r['date']).'</td>'
                .'<td>'.$e($r['source']).'</td>'
                .'<td>'.$e($r['pihak']).'</td>'
                .'<td>'.$e($r['status']).'</td>'
                .'<td>'.$e($r['desc']).'</td>'
                .'<td>'.$e($r['ref']).'</td>'
                .'<td>'.$e($r['cc']).'</td>'
                .'<td>'.$e($r['ldesc']).'</td>'
                .'<td>'.$rp($r['debit']).'</td>'
                .'<td>'.$rp($r['credit']).'</td>'
                .'<td>'.$rp($saldo).'</td>'
                .'</tr>';
        }
        if ($prevCode !== null) {
            $closeAcc();
        }
        $h .= '<tr style="font-weight:bold;background:#f1f5f9"><td colspan="11">TOTAL</td><td>'.$rp($totalD).'</td><td>'.$rp($totalK).'</td><td></td></tr></table>';

        $file = 'Detail-Transaksi-Jurnal-'.$from->format('Ymd').'-'.$to->format('Ymd').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'
            .'<h3>DETAIL TRANSAKSI JURNAL</h3><p>Periode: '.$e($from->toDateString()).' s/d '.$e($to->toDateString()).'</p>'
            .$h.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        return Inertia::render('BusinessSupport/Gl/Journals/Form', [
            'accounts'    => BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'costCenters' => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, JournalService $svc, \App\Domain\BusinessSupport\Services\BudgetGuardService $guard): RedirectResponse
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $v = $request->validate([
            'journal_date'   => ['required', 'date'],
            'reference'      => ['nullable', 'string', 'max:150'],
            'description'    => ['required', 'string', 'max:255'],
            'cost_center_id' => ['required', 'integer', 'exists:bs_cost_centers,id'], // wajib (mandatory)
            'lines'                 => ['required', 'array', 'min:2'],
            'lines.*.account_id'    => ['required', 'integer', 'exists:bs_accounts,id'],
            'lines.*.debit'         => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit'        => ['nullable', 'numeric', 'min:0'],
            'lines.*.description'   => ['nullable', 'string', 'max:200'],
        ]);

        try {
            // Gerbang over-budget: tolak input bila baris menambah realisasi melewati anggaran (akun+cost center).
            $guard->assertWithinBudget($v['lines'], (int) $v['cost_center_id'], $v['journal_date']);
            $journal = $svc->post([
                'journal_date'   => $v['journal_date'],
                'source'         => 'memorial',
                'status'         => 'draft', // jurnal memorial manual mulai sebagai Baru
                'reference'      => $v['reference'] ?? null,
                'description'    => $v['description'],
                'cost_center_id' => $v['cost_center_id'] ?? null,
                'created_by'     => $request->user()->id,
            ], $v['lines']);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.gl.journal.show', $journal)->with('success', "Jurnal {$journal->journal_number} dibuat sebagai Baru — finalisasi & approve agar efektif di GL.");
    }

    public function show(Request $request, BsJournal $journal): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        return Inertia::render('BusinessSupport/Gl/Journals/Show', [
            'journal'  => $journal->load(['lines.account:id,code,name', 'costCenter:id,code,name']),
            'sources'  => BsJournal::SOURCES,
            'statuses' => BsJournal::STATUSES,
        ]);
    }

    /** #54: form edit jurnal (koreksi pencatatan). */
    public function edit(Request $request, BsJournal $journal): Response|RedirectResponse
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        // Jurnal pada periode yang sudah CLOSED tidak dapat diedit hingga closing dibuka (Closing Fiskal).
        $period = BsFiscalPeriod::where('tenant_id', $journal->tenant_id)
            ->where('year', $journal->period_year)->where('month', $journal->period_month)->first();
        if ($period && $period->status === 'closed') {
            return redirect()->route('bs.gl.journal.show', $journal)
                ->with('error', "Periode {$journal->period_year}/{$journal->period_month} sudah CLOSED — jurnal tidak dapat diedit hingga closing dibuka.");
        }

        return Inertia::render('BusinessSupport/Gl/Journals/Form', [
            'accounts'    => BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'costCenters' => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'editing'     => array_merge(
                $journal->only(['id', 'journal_number', 'journal_date', 'reference', 'description', 'cost_center_id', 'source', 'status']),
                ['lines' => $journal->lines()->get(['account_id', 'description', 'debit', 'credit'])->toArray()],
            ),
        ]);
    }

    /** #54: simpan perubahan jurnal (ganti baris, tetap balance). */
    public function update(Request $request, BsJournal $journal, JournalService $svc, \App\Domain\BusinessSupport\Services\BudgetGuardService $guard): RedirectResponse
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $v = $request->validate([
            'journal_date'        => ['required', 'date'],
            'reference'           => ['nullable', 'string', 'max:150'],
            'description'         => ['required', 'string', 'max:255'],
            'cost_center_id'      => ['required', 'integer', 'exists:bs_cost_centers,id'], // wajib (mandatory)
            'lines'               => ['required', 'array', 'min:2'],
            'lines.*.account_id'  => ['required', 'integer', 'exists:bs_accounts,id'],
            'lines.*.debit'       => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit'      => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            // Gerbang over-budget (kecualikan jurnal ini dari realisasi agar tak dihitung ganda saat edit).
            $guard->assertWithinBudget($v['lines'], (int) $v['cost_center_id'], $v['journal_date'], $journal->id);
            $svc->update($journal, [
                'journal_date'   => $v['journal_date'],
                'reference'      => $v['reference'] ?? null,
                'description'    => $v['description'],
                'cost_center_id' => $v['cost_center_id'] ?? null,
            ], $v['lines']);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.gl.journal.show', $journal)->with('success', "Jurnal {$journal->journal_number} diperbarui.");
    }

    public function finalize(Request $request, BsJournal $journal, JournalService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        return $this->transition(fn () => $svc->finalize($journal), $journal, 'Jurnal difinalisasi (Final).');
    }

    public function approve(Request $request, BsJournal $journal, JournalService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        return $this->transition(fn () => $svc->approve($journal, $request->user()->id), $journal, 'Jurnal di-approve — efektif di GL.');
    }

    public function reject(Request $request, BsJournal $journal, JournalService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        return $this->transition(fn () => $svc->reject($journal), $journal, 'Jurnal dikembalikan ke Baru.');
    }

    private function transition(callable $fn, BsJournal $journal, string $message): RedirectResponse
    {
        try {
            $fn();
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.gl.journal.show', $journal)->with('success', $message);
    }
}
