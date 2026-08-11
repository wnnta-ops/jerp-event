<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Gl;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsFiscalPeriod;
use App\Domain\BusinessSupport\Models\BsJournal;
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

        $journals = BsJournal::query()
            ->with(['lines.account:id,code,name', 'costCenter:id,code,name'])
            ->whereBetween('journal_date', [$from->toDateString(), $to->toDateString()])
            ->when($request->input('source'), fn ($q, $v) => $q->where('source', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('journal_number', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")))
            ->latest('journal_date')->latest('id')
            ->get();

        $e  = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES);
        $rp = fn ($v) => number_format((float) ($v ?? 0), 2, ',', '.');
        $src = BsJournal::SOURCES;
        $st  = BsJournal::STATUSES;

        // Pihak Terkait — nama supplier/employee/customer di balik tiap jurnal. Di-resolve
        // sekali untuk SELURUH jurnal periode ini (batch), bukan per baris → bukan N+1.
        $pihak = $party->forJournalIds($journals->pluck('id')->all());

        // Urutan kolom: Kode Akun & Nama Akun paling kiri, lalu Pihak Terkait tepat setelah Sumber.
        $h = '<table border="1"><tr style="background:#0f766e;color:#fff">'
            .'<th>Kode Akun</th><th>Nama Akun</th>'
            .'<th>No. Jurnal</th><th>Tanggal</th><th>Sumber</th><th>Pihak Terkait</th><th>Status</th><th>Keterangan</th><th>Referensi</th>'
            .'<th>Cost Center</th><th>Keterangan Baris</th><th>Debit</th><th>Kredit</th></tr>';
        $totalD = 0.0;
        $totalK = 0.0;
        foreach ($journals as $j) {
            foreach ($j->lines as $ln) {
                $totalD += (float) $ln->debit;
                $totalK += (float) $ln->credit;
                $h .= '<tr>'
                    .'<td>'.$e($ln->account?->code).'</td>'
                    .'<td>'.$e($ln->account?->name).'</td>'
                    .'<td>'.$e($j->journal_number).'</td>'
                    .'<td>'.$e(optional($j->journal_date)->toDateString()).'</td>'
                    .'<td>'.$e($src[$j->source] ?? $j->source).'</td>'
                    .'<td>'.$e($pihak[$j->id] ?? '').'</td>'
                    .'<td>'.$e($st[$j->status] ?? $j->status).'</td>'
                    .'<td>'.$e($j->description).'</td>'
                    .'<td>'.$e($j->reference).'</td>'
                    .'<td>'.$e($j->costCenter?->name).'</td>'
                    .'<td>'.$e($ln->description).'</td>'
                    .'<td>'.$rp($ln->debit).'</td>'
                    .'<td>'.$rp($ln->credit).'</td>'
                    .'</tr>';
            }
        }
        $h .= '<tr style="font-weight:bold;background:#f1f5f9"><td colspan="11">TOTAL</td><td>'.$rp($totalD).'</td><td>'.$rp($totalK).'</td></tr></table>';

        $file = 'Detail-Transaksi-Jurnal-'.$from->format('Ymd').'-'.$to->format('Ymd').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'
            .'<h3>DETAIL TRANSAKSI JURNAL</h3><p>Periode: '.$e($from->toDateString()).' s/d '.$e($to->toDateString()).'</p>'
            .$h.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /**
     * Lengkapi cost center tiap baris jurnal.
     *
     * Tiap COA boleh dibebankan ke cost center sendiri. Baris yang dikosongkan mewarisi cost
     * center header, sehingga dimensi GL tidak pernah kosong dan perilaku jurnal lama (satu
     * cost center untuk seluruh baris) tetap sama persis.
     *
     * PENGECUALIAN: akun bertanda `cost_center_optional` (lihat CoaTanpaCostCenterSeeder) boleh
     * BENAR-BENAR tanpa cost center. Barisnya TIDAK diwarisi cost center header — kalau diwarisi,
     * mengosongkan kolom itu tidak akan pernah berpengaruh dan nilai neraca ikut terstempel ke
     * cost center yang sebenarnya tidak menanggungnya.
     *
     * @param  array<int,array<string,mixed>>  $lines
     * @param  array<int,bool>  $optional  account_id → boleh tanpa cost center
     * @return array<int,array<string,mixed>>
     */
    private function withLineCostCenter(array $lines, ?int $headerCostCenterId, array $optional = []): array
    {
        return array_map(function (array $l) use ($headerCostCenterId, $optional): array {
            $cc = $l['cost_center_id'] ?? null;
            $cc = ($cc === null || $cc === '') ? null : (int) $cc;
            if ($cc === null && empty($optional[(int) ($l['account_id'] ?? 0)])) {
                $cc = $headerCostCenterId;   // akun biasa: warisi header seperti sebelumnya
            }
            $l['cost_center_id'] = $cc;

            return $l;
        }, $lines);
    }

    /**
     * Akun mana saja (dari daftar id ini) yang boleh dijurnal tanpa cost center.
     *
     * @param  array<int,mixed>  $accountIds
     * @return array<int,bool>
     */
    private function costCenterOptionalMap(array $accountIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
        if (! $ids) {
            return [];
        }

        return BsAccount::whereIn('id', $ids)->where('cost_center_optional', true)
            ->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /**
     * Baris berakun WAJIB cost center tetapi tidak punya satu pun (baris kosong & header kosong).
     *
     * @param  array<int,array<string,mixed>>  $lines  hasil withLineCostCenter()
     * @return array<int,string>  nama akun yang bermasalah
     */
    private function missingCostCenter(array $lines): array
    {
        $ids = [];
        foreach ($lines as $l) {
            if (($l['cost_center_id'] ?? null) === null) {
                $ids[] = (int) ($l['account_id'] ?? 0);
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if (! $ids) {
            return [];
        }

        return BsAccount::whereIn('id', $ids)->where(fn ($q) => $q->where('cost_center_optional', false)->orWhereNull('cost_center_optional'))
            ->get(['code', 'name'])->map(fn ($a) => $a->code.' - '.$a->name)->all();
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        return Inertia::render('BusinessSupport/Gl/Journals/Form', [
            'accounts'    => BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'type', 'cost_center_optional']),
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
            // Boleh kosong: sebagian akun neraca memang tidak memakai cost center. Kelengkapannya
            // diperiksa PER BARIS di bawah, sesuai penanda cost_center_optional tiap akun.
            'cost_center_id' => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            'lines'                 => ['required', 'array', 'min:2'],
            'lines.*.account_id'    => ['required', 'integer', 'exists:bs_accounts,id'],
            // Cost center PER BARIS (opsional) — tiap COA boleh dibebankan ke cost center sendiri.
            // Bila dikosongkan, dipakai cost center header sebagai bawaan (lihat withLineCostCenter).
            'lines.*.cost_center_id' => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            'lines.*.debit'         => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit'        => ['nullable', 'numeric', 'min:0'],
            'lines.*.description'   => ['nullable', 'string', 'max:200'],
        ]);

        $headerCc = isset($v['cost_center_id']) ? (int) $v['cost_center_id'] : null;
        $lines = $this->withLineCostCenter(
            $v['lines'],
            $headerCc,
            $this->costCenterOptionalMap(array_column($v['lines'], 'account_id')),
        );
        if ($kurang = $this->missingCostCenter($lines)) {
            return back()->withInput()->with('error', 'Cost center wajib diisi untuk akun: '.implode(', ', $kurang).'.');
        }

        try {
            // Gerbang over-budget: cost center tiap baris SUDAH final di atas (akun biasa mewarisi
            // header, akun bebas cost center tetap null), jadi header tidak dipakai lagi sebagai
            // cadangan — kalau dipakai, baris yang sengaja dikosongkan ikut terhitung ke cost
            // center header dan bisa memicu OVER_BUDGET palsu.
            $guard->assertWithinBudget($lines, null, $v['journal_date']);
            $journal = $svc->post([
                'journal_date'   => $v['journal_date'],
                'source'         => 'memorial',
                'status'         => 'draft', // jurnal memorial manual mulai sebagai Baru
                'reference'      => $v['reference'] ?? null,
                'description'    => $v['description'],
                'cost_center_id' => $v['cost_center_id'] ?? null,
                'created_by'     => $request->user()->id,
            ], $lines);
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
            'accounts'    => BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'type', 'cost_center_optional']),
            'costCenters' => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'editing'     => array_merge(
                $journal->only(['id', 'journal_number', 'journal_date', 'reference', 'description', 'cost_center_id', 'source', 'status']),
                // cost_center_id per baris WAJIB ikut dimuat — tanpa ini, mengedit jurnal akan
                // mengembalikan baris tanpa cost center dan diam-diam menimpanya dgn cost center header.
                ['lines' => $journal->lines()->get(['account_id', 'cost_center_id', 'description', 'debit', 'credit'])->toArray()],
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
            // Lihat catatan pada store(): kelengkapan cost center diperiksa per baris.
            'cost_center_id'      => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            'lines'               => ['required', 'array', 'min:2'],
            'lines.*.account_id'  => ['required', 'integer', 'exists:bs_accounts,id'],
            // Cost center per baris — lihat catatan pada store().
            'lines.*.cost_center_id' => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            'lines.*.debit'       => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit'      => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:200'],
        ]);

        $headerCc = isset($v['cost_center_id']) ? (int) $v['cost_center_id'] : null;
        $lines = $this->withLineCostCenter(
            $v['lines'],
            $headerCc,
            $this->costCenterOptionalMap(array_column($v['lines'], 'account_id')),
        );
        if ($kurang = $this->missingCostCenter($lines)) {
            return back()->withInput()->with('error', 'Cost center wajib diisi untuk akun: '.implode(', ', $kurang).'.');
        }

        try {
            // Gerbang over-budget (kecualikan jurnal ini dari realisasi agar tak dihitung ganda saat
            // edit). Header tidak dipakai sebagai cadangan — lihat catatan pada store().
            $guard->assertWithinBudget($lines, null, $v['journal_date'], $journal->id);
            $svc->update($journal, [
                'journal_date'   => $v['journal_date'],
                'reference'      => $v['reference'] ?? null,
                'description'    => $v['description'],
                'cost_center_id' => $v['cost_center_id'] ?? null,
            ], $lines);
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
