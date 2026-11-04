<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Budget;

use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsBudgetAllocation;
use App\Domain\BusinessSupport\Services\BudgetAllocationService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Alokasi Budget (dok BC.AL/...): pindah anggaran dari budget A (COA lama) ke budget B (COA baru)
 * per bulan, langsung berlaku tanpa approval — lihat BudgetAllocationService. Baris lama (tanpa
 * budget tujuan) = alokasi model lama, tetap ditampilkan di daftar.
 */
class BudgetAllocationController extends Controller
{
    /** Sisa budget yang masih bisa dialokasikan dari satu master budget. */
    public static function sisa(BsBudget $budget): float
    {
        $allocated = (float) BsBudgetAllocation::where('budget_id', $budget->id)
            ->whereIn('status', ['baru', 'approved'])->sum('amount');

        return round((float) $budget->amount - $allocated, 2);
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('budget.allocation.view'), 403);
        $year = (int) $request->integer('year', (int) now()->year);
        // Gate approval: default hanya alokasi yang SUDAH di-approve.
        $status = (string) $request->input('status', 'approved');

        $rows = BsBudgetAllocation::with(['account:id,code,name', 'costCenter:id,code,name', 'budget:id,doc_no', 'toBudget.account:id,code,name', 'toBudget.costCenter:id,name'])
            ->where('year', $year)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')->get()
            ->map(fn ($a) => [
                'id' => $a->id, 'doc_no' => $a->doc_no, 'date' => $a->alloc_date?->format('Y-m-d'),
                'ref' => $a->budget?->doc_no, 'account' => $a->account?->code.' - '.$a->account?->name,
                'cost_center' => $a->costCenter?->name ?? 'Semua',
                'period' => $a->months
                    ? collect(array_keys($a->months))->map(fn ($m) => BsBudget::MONTHS[(int) $m - 1])->implode(', ').' '.$a->year
                    : BsBudget::MONTHS[($a->period_from ?: 1) - 1].' s/d '.BsBudget::MONTHS[($a->period_to ?: 12) - 1].' '.$a->year,
                'to_ref' => $a->toBudget?->doc_no,
                'to_account' => $a->toBudget ? $a->toBudget->account?->code.' - '.$a->toBudget->account?->name : null,
                'to_cost_center' => $a->toBudget ? ($a->toBudget->costCenter?->name ?? 'Semua') : null,
                'dari_invoice' => $a->source_type === BsBudgetAllocation::SOURCE_INVOICE ? $a->source_id : null,
                'notes' => $a->notes,
                'amount' => (float) $a->amount, 'total_budget' => (float) $a->total_budget, 'status' => $a->status,
                'lampiran_url' => \App\Domain\BusinessSupport\Services\LampiranPerubahanBudget::url($a),
            ])->values();

        return Inertia::render('BusinessSupport/Budget/Allocations/Index', [
            'year' => $year, 'rows' => $rows, 'statuses' => BsBudgetAllocation::STATUSES,
            'filterStatus' => $status,
            'pending' => BsBudgetAllocation::where('year', $year)->where('status', 'baru')->count(),
            'summary' => ['count' => $rows->count(), 'total' => $rows->sum('amount')],
        ]);
    }

    /** Form alokasi — master budget approved; angka per bulan diambil dari bs.budget.bulan. */
    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('budget.allocation.create'), 403);
        $year = (int) $request->integer('year', (int) now()->year);

        $budgets = BsBudget::with(['account:id,code,name', 'costCenter:id,code,name'])
            ->where('year', $year)->where('status', 'approved')->orderBy('doc_no')->get()
            ->map(fn ($b) => [
                'id' => $b->id, 'doc_no' => $b->doc_no,
                'account' => $b->account?->code.' - '.$b->account?->name,
                'cost_center' => $b->costCenter?->name ?? 'Semua', 'amount' => (float) $b->amount,
            ])->values();

        return Inertia::render('BusinessSupport/Budget/Allocations/Create', [
            'year' => $year, 'budgets' => $budgets, 'monthLabels' => BsBudget::MONTHS,
        ]);
    }

    public function store(Request $request, BudgetAllocationService $service): RedirectResponse
    {
        abort_unless($request->user()?->can('budget.allocation.create'), 403);
        $v = $request->validate([
            'budget_id'    => ['required', 'integer', 'exists:bs_budgets,id'],
            'to_budget_id' => ['required', 'integer', 'exists:bs_budgets,id', 'different:budget_id'],
            'alloc_date'   => ['required', 'date'],
            'months'       => ['required', 'array'],
            'months.*'     => ['nullable', 'numeric', 'min:0'],
            'notes'        => ['nullable', 'string', 'max:200'],
        ], ['to_budget_id.different' => 'Budget tujuan tidak boleh sama dengan budget asal.'], ['budget_id' => 'Budget asal', 'to_budget_id' => 'Budget tujuan']);

        try {
            $a = DB::transaction(fn () => $service->pindah((int) $v['budget_id'], (int) $v['to_budget_id'],
                array_map(fn ($x) => (float) ($x ?? 0), $v['months']), $v['alloc_date'], $v['notes'] ?? null, $request->user()->id));
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return to_route('bs.budget.allocation.index')->with('success', "Alokasi {$a->doc_no} tersimpan — anggaran sudah dipindah.");
    }

    /**
     * Inbox approval batch alokasi. Alokasi dari Registrasi Invoice Hutang berjalan di Approval
     * Inbox (approval_id, chain AL-DEFAULT = Finance): Setujui/Tolak di sini = bertindak di tahap
     * yang menunggu (approval yang sama, dua pintu). Anggaran baru dipindah saat disetujui.
     */
    public function approvals(Request $request, \App\Domain\ApprovalInbox\Services\ApprovalInboxQuery $inbox): Response
    {
        $user = $request->user();
        $alokasi = BsBudgetAllocation::with(['account:id,code,name', 'costCenter:id,code,name', 'budget:id,doc_no', 'toBudget.account:id,code,name', 'toBudget.costCenter:id,name', 'approval.steps'])
            ->where('status', 'baru')->orderBy('doc_no')->get();
        $bisa = fn (BsBudgetAllocation $a) => $a->approval ? $inbox->canAct($user, $a->approval)
            : (bool) $user?->canAny(['budget.allocation.approve', 'businesssupport.approval.act']);
        abort_unless($user?->can('budget.allocation.approve') || $alokasi->contains(fn ($a) => $a->approval && $bisa($a)), 403);

        $nomorInvoice = function (BsBudgetAllocation $a): ?string {
            return match ($a->source_type) {
                BsBudgetAllocation::SOURCE_INVOICE => \App\Domain\BusinessSupport\Models\BsApInvoice::query()->whereKey($a->source_id)->value('invoice_number'),
                \App\Domain\BusinessSupport\Models\BsApBudgetRequest::SOURCE => \App\Domain\BusinessSupport\Models\BsApBudgetRequest::query()->whereKey($a->source_id)->value('invoice_number'),
                default => null,
            };
        };
        $rows = $alokasi->map(function ($a) use ($bisa, $inbox, $nomorInvoice) {
            $tahap = $a->approval ? collect($inbox->stages($a->approval))->firstWhere('state', 'current') : null;

            return [
                'id' => $a->id, 'doc_no' => $a->doc_no, 'year' => $a->year, 'date' => $a->alloc_date?->format('Y-m-d'),
                'account' => $a->account?->code.' - '.$a->account?->name, 'cost_center' => $a->costCenter?->name ?? 'Semua',
                'ref' => $a->budget?->doc_no,
                'to_ref' => $a->toBudget?->doc_no,
                'to_account' => $a->toBudget ? $a->toBudget->account?->code.' - '.$a->toBudget->account?->name : null,
                'to_cost_center' => $a->toBudget ? ($a->toBudget->costCenter?->name ?? 'Semua') : null,
                'period' => collect(array_keys($a->months ?? []))->map(fn ($m) => BsBudget::MONTHS[(int) $m - 1])->implode(', ').' '.$a->year,
                'invoice' => $nomorInvoice($a),
                'notes' => $a->notes,
                'amount' => (float) $a->amount, 'total_budget' => (float) $a->total_budget,
                'tahap' => $tahap ? trim($tahap['label'].($tahap['role'] ? ' — '.$tahap['role'] : '')) : null,
                'approval_url' => $a->approval_id ? route('approval-inbox.show', $a->approval_id) : null,
                'lampiran_url' => \App\Domain\BusinessSupport\Services\LampiranPerubahanBudget::url($a),
                'bisa' => $bisa($a),
            ];
        })->values();

        return Inertia::render('BusinessSupport/Budget/Allocations/Approvals', [
            'rows' => $rows, 'canApprove' => $rows->contains('bisa', true),
            'summary' => ['count' => $rows->count(), 'total' => $rows->sum('amount')],
        ]);
    }

    public function approveBatch(Request $request): RedirectResponse
    {
        $v = $request->validate([
            'ids'      => ['required', 'array', 'min:1'],
            'ids.*'    => ['integer', 'exists:bs_budget_allocations,id'],
            'decision' => ['required', 'in:approve,reject'],
        ]);
        $approve = $v['decision'] === 'approve';
        $bolehLama = (bool) $request->user()?->canAny(['budget.allocation.approve', 'businesssupport.approval.act']);
        // Lewat Approval Inbox (ActOnApproval — hak per tahap sama dengan Inbox; SyncPengajuanBudgetInvoice
        // memindah anggarannya). Alokasi tanpa approval_id diputuskan langsung. Per alokasi satu transaksi:
        // yang sisa budget asalnya sudah tidak cukup gagal sendiri dan tetap menunggu.
        $ok = 0;
        $gagal = [];
        foreach (BsBudgetAllocation::with('approval.steps')->whereIn('id', $v['ids'])->where('status', 'baru')->orderBy('id')->get() as $a) {
            try {
                if ($a->approval) {
                    app(\App\Domain\Approval\Actions\ActOnApproval::class)->execute($a->approval, $approve ? 'approve' : 'reject');
                } elseif ($bolehLama) {
                    DB::transaction(fn () => app(BudgetAllocationService::class)->putuskan($a, $approve, $request->user()->id));
                } else {
                    $gagal[] = $a->doc_no.': tidak berhak';

                    continue;
                }
                $ok++;
            } catch (BusinessRuleException $e) {
                $gagal[] = $a->doc_no.': '.$e->getMessage();
            }
        }
        $pesan = $ok ? $ok.' alokasi '.($approve ? 'disetujui — anggaran sudah dipindah.' : 'ditolak.') : '';

        return $gagal ? back()->with($ok ? 'warning' : 'error', trim($pesan.' Gagal: '.implode(' · ', $gagal))) : back()->with('success', $pesan);
    }
}
