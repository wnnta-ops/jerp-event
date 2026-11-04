<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Budget;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsBudgetAllocation;
use App\Domain\BusinessSupport\Models\BsBudgetCorrection;
use App\Domain\BusinessSupport\Models\BsBudgetMutation;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Services\BudgetAnnualReportService;
use App\Domain\BusinessSupport\Services\BudgetGuardService;
use App\Domain\BusinessSupport\Services\BudgetRealizationService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    public function __construct(
        private readonly BudgetRealizationService $realisasi,
        private readonly BudgetAnnualReportService $laporan,
    ) {}

    /** Realisasi s.d. bulan laporan tahunan (default bulan berjalan / Des untuk tahun lalu / Jan untuk tahun depan). */
    private function sdLaporan(Request $request, int $year): int
    {
        $bawaan = $year === (int) now()->year ? (int) now()->month : ($year < (int) now()->year ? 12 : 1);

        return min(12, max(1, (int) $request->integer('sd', $bawaan)));
    }

    /**
     * Anggaran, realisasi & sisa per bulan satu master budget — sisa bulan = anggaran bulan (sudah
     * termasuk Koreksi & Alokasi yang berlaku) − realisasi bulan. Anggaran "Semua cost center"
     * memakai realisasi seluruh cost center akunnya, sama dengan penjaga budget.
     *
     * @return array{anggaran:list<float>, realisasi:list<float>, sisa:list<float>}
     */
    public function perBulan(BsBudget $b): array
    {
        $real = $this->realisasi->byMonth((int) $b->year, (int) $b->account_id, $b->cost_center_id ? (int) $b->cost_center_id : null);
        $out = ['anggaran' => [], 'realisasi' => [], 'sisa' => []];
        for ($m = 1; $m <= 12; $m++) {
            $a = BudgetGuardService::anggaranBulan($b, $m);
            $r = round((float) ($real[$m] ?? 0), 2);
            $out['anggaran'][] = $a;
            $out['realisasi'][] = $r;
            $out['sisa'][] = round($a - $r, 2);
        }

        return $out;
    }

    /** JSON per bulan satu master budget — form Koreksi, Alokasi & ikon mata Master Budget. */
    public function bulan(Request $request, BsBudget $budget): \Illuminate\Http\JsonResponse
    {
        abort_unless($request->user()?->canAny(['budget.master.view', 'budget.correction.create', 'budget.allocation.create']), 403);
        $budget->loadMissing(['account:id,code,name', 'costCenter:id,code,name']);

        return response()->json([
            'id' => $budget->id, 'doc_no' => $budget->doc_no, 'year' => $budget->year, 'wf_status' => $budget->status,
            'account' => trim($budget->account?->code.' - '.$budget->account?->name, ' -'),
            'cost_center' => $budget->costCenter?->name ?? 'Semua cost center',
            'amount' => (float) $budget->amount,
        ] + $this->perBulan($budget));
    }

    /** Hub modul Budget Control (mirror menu eProperty BO /BC/). */
    public function hub(Request $request): Response
    {
        abort_unless($request->user()?->can('budget.master.view'), 403);

        $year    = (int) now()->year;
        $budgets = BsBudget::where('year', $year)->get();
        $totalBudget = (float) $budgets->sum(fn ($b) => (float) $b->amount);
        $real    = 0.0;
        foreach ($budgets as $b) {
            $real += $this->realisasi->total($year, $b->account_id, $b->cost_center_id);
        }

        return Inertia::render('BusinessSupport/Budget/Hub', [
            'summary' => [
                'year'        => $year,
                'budget'      => round($totalBudget, 2),
                'realisasi'   => round($real, 2),
                'sisa'        => round($totalBudget - $real, 2),
                'lines'       => $budgets->count(),
                'pending'     => BsBudget::where('status', 'submitted')->count()
                                + BsBudgetAllocation::where('status', 'baru')->count()
                                + BsBudgetCorrection::where('status', 'baru')->count(),
            ],
        ]);
    }

    /** Print-Out — hub cetak laporan Budget Control. */
    public function printHub(Request $request): Response
    {
        abort_unless($request->user()?->can('budget.report.print'), 403);

        return Inertia::render('BusinessSupport/Budget/PrintHub');
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('budget.master.view'), 403);

        $year = (int) $request->integer('year', (int) now()->year);
        // Gate approval: default hanya tampilkan anggaran yang SUDAH di-approve.
        // (Tambahan anggaran/upload yang belum di-approve tidak muncul di Master Budget Control.)
        $status = (string) $request->input('status', 'approved');
        $sd = $this->sdLaporan($request, $year);

        $budgets = BsBudget::with(['account:id,code,name', 'costCenter:id,code,name'])
            ->where('year', $year)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderBy('doc_no')->get()
            ->map(function ($b) use ($year) {
                $real   = $this->realisasi->total($year, $b->account_id, $b->cost_center_id);
                $amount = (float) $b->amount;

                return [
                    'id' => $b->id, 'doc_no' => $b->doc_no, 'project' => $b->project,
                    'account' => $b->account?->code.' - '.$b->account?->name,
                    'cost_center' => $b->costCenter?->name ?? 'Semua', 'amount' => $amount,
                    'months' => array_values($b->monthsArray()),
                    'realisasi' => $real, 'sisa' => $amount - $real,
                    'percent' => $amount > 0 ? round($real / $amount * 100, 1) : 0,
                    'health' => $amount <= 0 ? 'na' : ($real > $amount ? 'over' : ($real >= $amount * 0.9 ? 'warning' : 'aman')),
                    'wf_status' => $b->status ?? 'draft',
                    'notes' => $b->notes, 'approval_note' => $b->approval_note,
                ];
            })->values();

        return Inertia::render('BusinessSupport/Budget/Index', [
            'year'        => $year,
            'budgets'     => $budgets,
            'summary'     => [
                'amount'    => $budgets->sum('amount'),
                'realisasi' => $budgets->sum('realisasi'),
                'sisa'      => $budgets->sum('amount') - $budgets->sum('realisasi'),
                'approved'  => $budgets->where('wf_status', 'approved')->sum('amount'),
            ],
            'statuses'    => BsBudget::STATUSES,
            'monthLabels' => BsBudget::MONTHS,
            'mutations'   => BsBudgetMutation::where('year', $year)->with(['fromBudget.account:id,code,name', 'toBudget.account:id,code,name'])->latest('mutation_date')->latest('id')->limit(50)->get()
                ->map(fn ($m) => [
                    'id' => $m->id, 'kind' => $m->kind, 'date' => $m->mutation_date?->format('Y-m-d'), 'amount' => (float) $m->amount, 'note' => $m->note,
                    'from' => $m->fromBudget?->account?->code, 'to' => $m->toBudget?->account?->code,
                ]),
            // Semua akun postable (bukan hanya akun beban) agar anggaran bisa untuk akun apa pun.
            'accounts'    => BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name']),
            'costCenters' => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'filterStatus' => $status,
            'pending'      => BsBudget::where('year', $year)->where('status', 'submitted')->count(),
            // Anggaran vs Realisasi Tahunan (menggantikan tabel daftar anggaran). Anggaran selalu
            // yang berlaku — sudah termasuk Koreksi & Alokasi.
            'laporan'      => $this->laporan->susun($year, $status, $sd),
            'sd'           => $sd,
        ]);
    }

    /** Approval Budget Control — inbox batch master budget yang diajukan. */
    public function approvals(Request $request): Response
    {
        abort_unless($request->user()?->can('budget.master.approve'), 403);

        $rows = BsBudget::with(['account:id,code,name', 'costCenter:id,code,name'])
            ->where('status', 'submitted')->orderBy('doc_no')->get()
            ->map(fn ($b) => [
                'id' => $b->id, 'doc_no' => $b->doc_no, 'date' => $b->submitted_at?->format('Y-m-d'),
                'account' => $b->account?->code.' - '.$b->account?->name,
                'cost_center' => $b->costCenter?->name ?? 'Semua', 'amount' => (float) $b->amount,
                'period' => 'Jan s/d Des '.$b->year,
            ])->values();

        return Inertia::render('BusinessSupport/Budget/Approvals', [
            'rows'       => $rows,
            'canApprove' => (bool) $request->user()?->canAny(['budget.master.approve', 'businesssupport.approval.act']),
            'summary'    => ['count' => $rows->count(), 'total' => $rows->sum('amount')],
        ]);
    }

    /** Approve/Reject banyak master budget sekaligus. */
    public function approveBatch(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['budget.master.approve', 'businesssupport.approval.act']), 403);
        $v = $request->validate([
            'ids'      => ['required', 'array', 'min:1'],
            'ids.*'    => ['integer', 'exists:bs_budgets,id'],
            'decision' => ['required', 'in:approve,reject'],
        ]);
        $approve = $v['decision'] === 'approve';
        BsBudget::whereIn('id', $v['ids'])->where('status', 'submitted')->update([
            'status'      => $approve ? 'approved' : 'rejected',
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
        ]);

        return back()->with('success', $approve ? 'Master budget disetujui.' : 'Master budget ditolak.');
    }

    /** Ajukan anggaran (draft → submitted). */
    public function submit(Request $request, BsBudget $budget): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['budget.master.submit', 'businesssupport.setup.manage']), 403);
        if ($budget->status !== 'draft' && $budget->status !== 'rejected') {
            return back()->with('error', 'Hanya anggaran draft/ditolak yang dapat diajukan.');
        }
        $budget->update(['status' => 'submitted', 'submitted_at' => now(), 'approved_at' => null, 'approved_by' => null, 'approval_note' => null]);

        return back()->with('success', 'Anggaran diajukan untuk persetujuan.');
    }

    /** Setujui / tolak anggaran (submitted → approved|rejected). */
    public function approve(Request $request, BsBudget $budget): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['budget.master.approve', 'businesssupport.approval.act']), 403);
        $v = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:255']]);
        if ($budget->status !== 'submitted') {
            return back()->with('error', 'Hanya anggaran yang diajukan yang dapat diputuskan.');
        }
        $approve = $v['decision'] === 'approve';
        $budget->update([
            'status'        => $approve ? 'approved' : 'rejected',
            'approved_at'   => now(),
            'approved_by'   => $request->user()->id,
            'approval_note' => $v['note'] ?? null,
        ]);

        return back()->with('success', $approve ? 'Anggaran disetujui.' : 'Anggaran ditolak.');
    }

    /** Mutasi cepat (legacy): transfer antar baris / koreksi nilai satu baris (langsung). */
    public function mutate(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['budget.master.mutate', 'businesssupport.setup.manage']), 403);

        $v = $request->validate([
            'kind'           => ['required', 'in:transfer,koreksi'],
            'mutation_date'  => ['required', 'date'],
            'from_budget_id' => ['nullable', 'integer', 'exists:bs_budgets,id'],
            'to_budget_id'   => ['nullable', 'integer', 'exists:bs_budgets,id'],
            'amount'         => ['required', 'numeric', 'min:0.01'],
            'note'           => ['nullable', 'string', 'max:255'],
        ]);

        try {
            DB::transaction(function () use ($v, $request): void {
                $amount = round((float) $v['amount'], 2);
                if ($v['kind'] === 'transfer') {
                    $from = BsBudget::findOrFail($v['from_budget_id'] ?? 0);
                    $to   = BsBudget::findOrFail($v['to_budget_id'] ?? 0);
                    if ($from->id === $to->id) {
                        throw new BusinessRuleException('Baris asal dan tujuan tidak boleh sama.', errorCode: 'BUD_SAME');
                    }
                    if ((float) $from->amount < $amount) {
                        throw new BusinessRuleException('Anggaran asal tidak cukup untuk ditransfer.', errorCode: 'BUD_INSUFFICIENT');
                    }
                    $from->update(['amount' => round((float) $from->amount - $amount, 2)]);
                    $to->update(['amount' => round((float) $to->amount + $amount, 2)]);
                    $year = $from->year;
                } else {
                    $target = BsBudget::findOrFail($v['to_budget_id'] ?? ($v['from_budget_id'] ?? 0));
                    $target->update(['amount' => round((float) $target->amount + $amount, 2)]);
                    $year = $target->year;
                }
                BsBudgetMutation::create([
                    'year' => $year, 'kind' => $v['kind'], 'from_budget_id' => $v['from_budget_id'] ?? null,
                    'to_budget_id' => $v['to_budget_id'] ?? null, 'amount' => $amount,
                    'mutation_date' => $v['mutation_date'], 'note' => $v['note'] ?? null, 'created_by' => $request->user()->id,
                ]);
            });
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Mutasi anggaran tercatat.');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['budget.master.create', 'businesssupport.setup.manage']), 403);

        $v      = $this->validateData($request);
        $months = $this->monthsFrom($v);
        $sum    = array_sum($months);
        $amount = $sum > 0 ? $sum : (float) ($v['amount'] ?? 0);

        $existing = BsBudget::where('tenant_id', tenant_id())->where('year', $v['year'])
            ->where('cost_center_id', ($v['cost_center_id'] ?? null) ?: null)->where('account_id', $v['account_id'])->first();

        // Setiap tambahan/perubahan anggaran WAJIB melewati Approval Budget → langsung 'submitted'
        // (masuk antrean approval, tidak tampil di Master Budget Control sampai di-approve).
        $payload = array_merge($months, [
            'project' => $v['project'] ?? null, 'amount' => $amount, 'notes' => $v['notes'] ?? null,
            'status' => 'submitted', 'submitted_at' => now(), 'approved_at' => null, 'approved_by' => null, 'approval_note' => null,
        ]);

        if ($existing) {
            $existing->update($payload);
        } else {
            BsBudget::create(array_merge($payload, [
                'tenant_id'  => tenant_id(),
                'doc_no'     => BsBudget::nextDocNo((int) $v['year'], $v['project'] ?? null),
                'year'       => $v['year'],
                'cost_center_id' => ($v['cost_center_id'] ?? null) ?: null,
                'account_id' => $v['account_id'],
                'created_by' => $request->user()->id,
            ]));
        }

        return back()->with('success', 'Anggaran disimpan & diajukan — menunggu Approval Budget.');
    }

    public function update(Request $request, BsBudget $budget): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['budget.master.update', 'businesssupport.setup.manage']), 403);

        $v      = $this->validateData($request, $budget->id);
        $months = $this->monthsFrom($v);
        $sum    = array_sum($months);
        $amount = $sum > 0 ? $sum : (float) ($v['amount'] ?? 0);

        // Perubahan anggaran mengembalikan status ke 'submitted' → wajib di-approve ulang.
        $budget->update(array_merge($months, [
            'year' => $v['year'], 'project' => $v['project'] ?? null,
            'cost_center_id' => ($v['cost_center_id'] ?? null) ?: null,
            'account_id' => $v['account_id'], 'amount' => $amount, 'notes' => $v['notes'] ?? null,
            'status' => 'submitted', 'submitted_at' => now(), 'approved_at' => null, 'approved_by' => null, 'approval_note' => null,
        ]));

        return back()->with('success', 'Anggaran diperbarui & diajukan ulang — menunggu Approval Budget.');
    }

    public function destroy(Request $request, BsBudget $budget): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['budget.master.delete', 'businesssupport.setup.manage']), 403);
        $budget->delete();

        return back()->with('success', 'Anggaran dihapus.');
    }

    /** @param array<string,mixed> $v @return array<string,float> m1..m12 */
    private function monthsFrom(array $v): array
    {
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $out['m'.$m] = round((float) ($v['m'.$m] ?? 0), 2);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function validateData(Request $request, ?int $id = null): array
    {
        $rules = [
            'year'           => ['required', 'integer', 'min:2000', 'max:2100'],
            'project'        => ['nullable', 'string', 'max:30'],
            'cost_center_id' => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            'account_id'     => ['required', 'integer', 'exists:bs_accounts,id'],
            'amount'         => ['nullable', 'numeric', 'min:0'],
            'notes'          => ['nullable', 'string', 'max:200'],
        ];
        for ($m = 1; $m <= 12; $m++) {
            $rules['m'.$m] = ['nullable', 'numeric', 'min:0'];
        }

        return $request->validate($rules);
    }

    /** Export Budget Control ke Excel (.xls) dengan anggaran & realisasi per bulan (Jan–Des). */
    public function export(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('budget.master.export'), 403);
        $year    = (int) $request->integer('year', (int) now()->year);
        // Sama dengan layar: filter status (default approved) + baris yang tersaring di layar
        // (cari & saringan kolom, dikirim sebagai id — pola Batal Akad).
        $status  = (string) $request->input('status', 'approved');
        $ids     = collect(explode(',', (string) $request->input('ids')))->map(fn ($v) => (int) trim($v))->filter()->unique();
        $budgets = BsBudget::with(['account:id,code,name', 'costCenter:id,code,name'])->where('year', $year)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($request->filled('ids'), fn ($q) => $q->whereIn('id', $ids->all() ?: [0]))
            ->orderBy('doc_no')->get();

        $months = BsBudget::MONTHS;
        $e  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');

        $head = '<tr style="background:#0f766e;color:#fff"><th>No. Budget</th><th>Akun</th><th>Cost Center</th><th>Budget</th>';
        foreach ($months as $m) {
            $head .= '<th>'.$m.'</th>';
        }
        $head .= '<th>Total Realisasi</th><th>Sisa</th><th>%</th></tr>';

        $body = '';
        foreach ($budgets as $b) {
            $monthly = $this->realisasi->byMonth($year, $b->account_id, $b->cost_center_id);
            $total   = array_sum($monthly);
            $amount  = (float) $b->amount;
            $body .= '<tr><td>'.$e($b->doc_no).'</td><td>'.$e($b->account?->code.' - '.$b->account?->name).'</td><td>'.$e($b->costCenter?->name ?? 'Semua').'</td><td>'.$rp($amount).'</td>';
            for ($mo = 1; $mo <= 12; $mo++) {
                $body .= '<td>'.$rp($monthly[$mo] ?? 0).'</td>';
            }
            $body .= '<td>'.$rp($total).'</td><td>'.$rp($amount - $total).'</td><td>'.($amount > 0 ? round($total / $amount * 100, 1) : 0).'%</td></tr>';
        }

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body><table border="1">'.$head.$body.'</table></body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="Budget-Control-'.$year.'.xls"');
    }

    /**
     * Dasar satu angka di laporan tahunan (klik angka / ikon mata): per bulan anggaran berlaku,
     * realisasi & sisa, dengan Koreksi Budget dan Alokasi yang membentuknya, serta baris jurnal
     * realisasinya.
     */
    public function rincian(Request $request): \Illuminate\Http\JsonResponse
    {
        abort_unless($request->user()?->can('budget.master.view'), 403);
        $year = (int) $request->integer('year', (int) now()->year);
        $status = (string) $request->input('status', 'approved');
        $sd = $this->sdLaporan($request, $year);
        $accId = (int) $request->integer('account_id');
        $ccRaw = (string) $request->input('cc', 'semua');
        $cc = $ccRaw === 'semua' ? null : (int) $ccRaw;
        [$from, $to] = BudgetAnnualReportService::rentang($year, $sd);

        $budgets = BsBudget::where('year', $year)->where('account_id', $accId)
            ->when($request->filled('budget_id'), fn ($q) => $q->whereKey((int) $request->integer('budget_id')))
            ->when($status !== 'all' && ! $request->filled('budget_id'), fn ($q) => $q->where('status', $status))
            ->when($ccRaw === 'semua', fn ($q) => $q->whereNull('cost_center_id'), fn ($q) => $q->where('cost_center_id', $cc ?: -1))
            ->orderBy('doc_no')->get();
        $ids = $budgets->pluck('id')->all() ?: [0];
        $koreksi = BsBudgetCorrection::whereIn('budget_id', $ids)->orderBy('corr_date')->orderBy('id')->get();
        $alokasi = BsBudgetAllocation::whereNotNull('to_budget_id')
            ->where(fn ($q) => $q->whereIn('budget_id', $ids)->orWhereIn('to_budget_id', $ids))
            ->with(['budget.account:id,code', 'toBudget.account:id,code'])->orderBy('alloc_date')->orderBy('id')->get();

        $pusat = BsCostCenter::withTrashed()->pluck('name', 'id');

        return response()->json([
            'akun' => BsAccount::withTrashed()->whereKey($accId)->first(['id', 'code', 'name']),
            'cost_center' => $ccRaw === 'semua' ? 'Semua cost center' : ($cc === 0 ? 'Tanpa cost center' : ($pusat[$cc] ?? '—')),
            'periode' => 'Jan – '.BsBudget::MONTHS[$sd - 1].' '.$year,
            'budgets' => $budgets->map(fn ($b) => [
                'id' => $b->id, 'doc_no' => $b->doc_no, 'wf_status' => $b->status ?? 'draft', 'notes' => $b->notes,
                'koreksi' => $koreksi->where('budget_id', $b->id)->values()->map(fn ($c) => [
                    'doc_no' => $c->doc_no, 'tanggal' => $c->corr_date?->format('Y-m-d') ?? substr((string) $c->corr_date, 0, 10),
                    'periode' => BudgetCorrectionController::labelPeriode($c),
                    'nilai' => round(array_sum($c->tambahanBulan()), 2),
                    'lampiran_url' => \App\Domain\BusinessSupport\Services\LampiranPerubahanBudget::url($c),
                    'status' => $c->status, 'note' => $c->note,
                    // Koreksi yang diajukan ke Approval Inbox dibuka di sana; yang lama di layar Koreksi Budget.
                    'url' => $c->approval_id ? route('approval-inbox.show', $c->approval_id) : route('bs.budget.correction.index'),
                ]),
                // Alokasi: keluar (budget ini asal, nilai minus) / masuk (budget ini tujuan, nilai plus).
                'alokasi' => $alokasi->filter(fn ($a) => $a->budget_id === $b->id || $a->to_budget_id === $b->id)->values()->map(fn ($a) => [
                    'doc_no' => $a->doc_no, 'tanggal' => $a->alloc_date?->format('Y-m-d'),
                    'arah' => $a->budget_id === $b->id ? 'keluar' : 'masuk',
                    'lawan' => $a->budget_id === $b->id ? ($a->toBudget?->doc_no.' · '.$a->toBudget?->account?->code) : ($a->budget?->doc_no.' · '.$a->budget?->account?->code),
                    'periode' => collect(array_keys((array) $a->months))->map(fn ($m) => BsBudget::MONTHS[(int) $m - 1])->implode(', '),
                    'nilai' => ($a->budget_id === $b->id ? -1 : 1) * (float) $a->amount, 'note' => $a->notes,
                    'lampiran_url' => \App\Domain\BusinessSupport\Services\LampiranPerubahanBudget::url($a),
                ]),
            ] + $this->perBulan($b)),
            'jurnal' => array_map(fn ($j) => $j + [
                'cost_center' => $j['cost_center_id'] ? ($pusat[$j['cost_center_id']] ?? '—') : 'Tanpa cost center',
                'url' => route('bs.gl.journal.show', $j['journal_id']),
            ], $this->realisasi->rincian($year, $accId, $cc, $from, $to)),
        ]);
    }

    /** Unduh Excel laporan tahunan — susunan & angka sama persis dengan layar. */
    public function laporanExport(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('budget.master.export'), 403);
        $year = (int) $request->integer('year', (int) now()->year);
        $status = (string) $request->input('status', 'approved');
        $sd = $this->sdLaporan($request, $year);
        $lap = $this->laporan->susun($year, $status, $sd);

        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
        $pr = fn ($p) => $p === null ? '—' : number_format((float) $p, 1, ',', '.').'%';
        $sel = fn (array $r, string $gaya, string $uraian) => '<tr style="'.$gaya.'"><td>'.$uraian.'</td><td align="right">'.$rp($r['anggaran']).'</td><td align="right">'.$rp($r['realisasi']).'</td><td align="right">'.$rp($r['sisa']).'</td><td align="right">'.$pr($r['persen']).'</td><td align="right">'.$rp($r['setahun']).'</td></tr>';

        $bulan = BsBudget::MONTHS[$sd - 1];
        $html = '<tr><td colspan="6"><b>Anggaran vs Realisasi Tahunan '.$year.'</b> — anggaran berlaku (termasuk Koreksi & Alokasi) · realisasi s.d. akhir '.$bulan.' '.$year.'</td></tr>'
            .'<tr style="background:#0f766e;color:#fff"><th>Uraian</th><th>Anggaran s.d. '.$bulan.'</th><th>Realisasi s.d. '.$bulan.'</th><th>Sisa s.d. '.$bulan.'</th><th>%</th><th>Anggaran setahun</th></tr>';
        foreach ($lap['bagian'] as $bg) {
            $html .= '<tr style="background:#dbeafe;font-weight:bold"><td colspan="6">'.$e($bg['judul']).'</td></tr>';
            foreach ($bg['grup'] as $g) {
                $html .= '<tr style="background:#f3f4f6;font-weight:bold"><td colspan="6">'.$e($g['kode'].' '.$g['nama']).'</td></tr>';
                foreach ($g['akun'] as $a) {
                    $html .= $sel($a, '', '&nbsp;&nbsp;'.$e($a['kode'].' - '.$a['nama']));
                    foreach ($a['baris'] as $r) {
                        $docs = implode(', ', array_filter(array_column($r['budgets'], 'doc_no')));
                        $html .= $sel($r, 'color:#6b7280', '&nbsp;&nbsp;&nbsp;&nbsp;'.$e($r['cc_nama']).($docs ? ' ('.$e($docs).')' : ''));
                    }
                }
                $html .= $sel($g, 'font-weight:bold', 'Total '.$e($g['kode'].' '.$g['nama']));
            }
            $html .= $sel($bg, 'font-weight:bold;background:#eef2ff', 'TOTAL '.$e(mb_strtoupper($bg['judul'])));
        }
        $html .= $sel($lap['laba'], 'font-weight:bold;background:#fef3c7', 'LABA (pendapatan − beban)');

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body><table border="1">'.$html.'</table></body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="Anggaran-vs-Realisasi-'.$year.'-sd-'.$bulan.'.xls"');
    }
}
