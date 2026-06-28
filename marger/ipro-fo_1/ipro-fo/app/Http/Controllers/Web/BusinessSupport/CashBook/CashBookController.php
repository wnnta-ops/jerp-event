<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\CashBook;

use App\Domain\BusinessSupport\Actions\RecordCashTransaction;
use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCashTransaction;
use App\Domain\BusinessSupport\Models\BsJournalLine;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CashBookController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $accId = $request->integer('account_id') ?: null;

        // #81/#82/#83: bila 1 rekening dipilih → tampilkan SELURUH mutasi GL rekening tsb
        // (termasuk pembayaran Kuitansi/Giro/Payroll yang hanya berjurnal), sehingga total atas,
        // kolom Masuk/Keluar, saldo berjalan, & halaman Saldo Rekening konsisten satu basis.
        return $accId
            ? $this->indexByAccount($request, $accId)
            : $this->indexAllAccounts($request);
    }

    /** "Semua Rekening" — register transaksi kas (BsCashTransaction). Saldo berjalan tidak ditampilkan. */
    private function indexAllAccounts(Request $request): Response
    {
        $filtered = fn () => BsCashTransaction::query()
            ->when($request->input('type'), fn ($q, $v) => $q->where('trx_type', $v))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('trx_number', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")->orWhere('payee', 'like', "%{$s}%")))
            ->when($request->input('from'), fn ($q, $v) => $q->whereDate('trx_date', '>=', $v))
            ->when($request->input('to'), fn ($q, $v) => $q->whereDate('trx_date', '<=', $v));

        $rows = $filtered()
            ->with(['cashAccount:id,code,name', 'counterAccount:id,code,name'])
            ->latest('trx_date')->latest('id')
            ->paginate(50)->withQueryString();

        $rows->getCollection()->transform(function (BsCashTransaction $r): BsCashTransaction {
            $amt = (float) $r->amount;
            $r->setAttribute('masuk', $r->trx_type === 'in' ? $amt : 0.0);
            $r->setAttribute('keluar', in_array($r->trx_type, ['out', 'transfer'], true) ? $amt : 0.0);
            $r->setAttribute('running_saldo', null);

            return $r;
        });

        return Inertia::render('BusinessSupport/CashBook/Index', [
            'transactions' => $rows,
            'types'        => BsCashTransaction::TYPES,
            'cashAccounts' => $this->cashAccounts(),
            'filters'      => $request->only(['type', 'search', 'account_id', 'from', 'to']),
            'summary'      => [
                'in'  => (float) (clone $filtered())->where('trx_type', 'in')->sum('amount'),
                // Keluar = Pengeluaran + Transfer (kolom Keluar pada baris juga mencakup transfer → konsisten).
                'out' => (float) (clone $filtered())->whereIn('trx_type', ['out', 'transfer'])->sum('amount'),
            ],
            'glMode'       => false,
        ]);
    }

    /**
     * Per-rekening — sumber data = General Ledger (bs_journal_lines), basis sama dgn balances().
     * Masuk = debit, Keluar = credit, Saldo = kumulatif (debit−credit). Konsisten lintas halaman.
     */
    private function indexByAccount(Request $request, int $accId): Response
    {
        $acc = BsAccount::find($accId, ['id', 'code', 'name']);

        $base = fn () => BsJournalLine::query()
            ->join('bs_journals as j', 'j.id', '=', 'bs_journal_lines.journal_id')
            ->where('j.status', 'posted')
            ->whereNull('j.deleted_at')
            ->where('bs_journal_lines.account_id', $accId)
            ->when($request->input('from'), fn ($q, $v) => $q->whereDate('j.journal_date', '>=', $v))
            ->when($request->input('to'), fn ($q, $v) => $q->whereDate('j.journal_date', '<=', $v))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('j.reference', 'like', "%{$s}%")
                ->orWhere('j.journal_number', 'like', "%{$s}%")
                ->orWhere('j.description', 'like', "%{$s}%")
                ->orWhere('bs_journal_lines.description', 'like', "%{$s}%")))
            // Filter jenis dipetakan ke GL: Masuk → debit>0 ; Keluar/Transfer → credit>0.
            ->when($request->input('type'), fn ($q, $v) => $v === 'in'
                ? $q->where('bs_journal_lines.debit', '>', 0)
                : $q->where('bs_journal_lines.credit', '>', 0));

        // #81 ringkasan account-aware (basis SAMA dgn kolom baris): masuk=SUM(debit), keluar=SUM(credit).
        $sum = $base()->selectRaw('COALESCE(SUM(bs_journal_lines.debit),0) as m, COALESCE(SUM(bs_journal_lines.credit),0) as k')->first();
        $summary = ['in' => (float) ($sum->m ?? 0), 'out' => (float) ($sum->k ?? 0)];

        // Saldo awal sebelum 'from' agar saldo berjalan & rekonsiliasi #83 tetap benar saat difilter periode.
        $opening = 0.0;
        if ($from = $request->input('from')) {
            $opening = (float) BsJournalLine::query()
                ->join('bs_journals as j', 'j.id', '=', 'bs_journal_lines.journal_id')
                ->where('j.status', 'posted')->whereNull('j.deleted_at')
                ->where('bs_journal_lines.account_id', $accId)
                ->whereDate('j.journal_date', '<', $from)
                ->selectRaw('COALESCE(SUM(bs_journal_lines.debit-bs_journal_lines.credit),0) as bal')->value('bal');
        }

        // Saldo berjalan atas SELURUH set terfilter (urut naik), peta per-id agar benar lintas halaman.
        $runningSaldo = [];
        $bal = $opening;
        foreach ($base()->orderBy('j.journal_date')->orderBy('bs_journal_lines.id')
            ->get(['bs_journal_lines.id', 'bs_journal_lines.debit', 'bs_journal_lines.credit']) as $l) {
            $bal += (float) $l->debit - (float) $l->credit;
            $runningSaldo[$l->id] = $bal;
        }

        $rows = $base()
            // Urut NAIK: tanggal paling awal di atas → terbaru di bawah (saldo berjalan terbaca menurun).
            ->orderBy('j.journal_date')->orderBy('bs_journal_lines.id')
            ->select([
                'bs_journal_lines.id', 'bs_journal_lines.debit', 'bs_journal_lines.credit',
                'bs_journal_lines.description as line_description',
                'j.journal_number', 'j.reference', 'j.source', 'j.journal_date', 'j.description as journal_description',
            ])
            ->paginate(50)->withQueryString();

        $accName = $acc?->name;
        $accCode = $acc?->code;
        $rows->getCollection()->transform(function (BsJournalLine $l) use ($runningSaldo, $accName, $accCode): BsJournalLine {
            $debit  = (float) $l->debit;
            $credit = (float) $l->credit;
            $l->setAttribute('masuk', $debit);
            $l->setAttribute('keluar', $credit);
            $l->setAttribute('running_saldo', $runningSaldo[$l->id] ?? null);
            $l->setAttribute('trx_number', $l->reference ?: $l->journal_number);
            $l->setAttribute('trx_date', $l->journal_date);
            $l->setAttribute('trx_type', $debit > 0 ? 'in' : 'out');
            $l->setAttribute('description', $l->line_description ?: $l->journal_description);
            $l->setAttribute('payee', $l->source ? strtoupper((string) $l->source) : null);
            $l->setAttribute('cash_account', ['code' => $accCode, 'name' => $accName]);

            return $l;
        });

        return Inertia::render('BusinessSupport/CashBook/Index', [
            'transactions' => $rows,
            'types'        => BsCashTransaction::TYPES,
            'cashAccounts' => $this->cashAccounts(),
            'filters'      => $request->only(['type', 'search', 'account_id', 'from', 'to']),
            'summary'      => $summary,
            'glMode'       => true,
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        // #148: kunci jenis transaksi sesuai konteks pembuka form.
        // ?type=in → hanya Penerimaan Bank ; ?type=transfer → hanya Transfer Antar Rekening ;
        // ?type=out → hanya Pengeluaran ; tanpa type → semua jenis boleh (default 'in').
        $type = $request->input('type');
        $allowedTypes = in_array($type, array_keys(BsCashTransaction::TYPES), true)
            ? [$type]
            : array_keys(BsCashTransaction::TYPES);

        return Inertia::render('BusinessSupport/CashBook/Form', [
            'types'          => BsCashTransaction::TYPES,
            'cashAccounts'   => $this->cashAccounts(),
            'counterAccounts'=> BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'defaultType'    => $allowedTypes[0] ?? 'in',
            'allowedTypes'   => array_values($allowedTypes),
        ]);
    }

    public function store(Request $request, RecordCashTransaction $action): RedirectResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $v = $request->validate($this->rules(), [], $this->attributes());

        try {
            $trx = $action->execute($v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.cashbook.show', $trx)->with('success', "Transaksi {$trx->trx_number} tercatat — jurnal otomatis terbentuk.");
    }

    public function show(Request $request, BsCashTransaction $cashTransaction): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        return Inertia::render('BusinessSupport/CashBook/Show', [
            'trx'   => $cashTransaction->load([
                'cashAccount:id,code,name', 'counterAccount:id,code,name',
                'lines:id,cash_transaction_id,account_id,amount,description', 'lines.account:id,code,name',
            ]),
            'types' => BsCashTransaction::TYPES,
        ]);
    }

    /** #16: form edit transaksi kas. */
    public function edit(Request $request, BsCashTransaction $cashTransaction): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        return Inertia::render('BusinessSupport/CashBook/Form', [
            'types'           => BsCashTransaction::TYPES,
            'cashAccounts'    => $this->cashAccounts(),
            'counterAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'defaultType'     => $cashTransaction->trx_type,
            // #148: saat edit, kunci jenis ke jenis transaksi yang sudah ada (tidak boleh diubah).
            'allowedTypes'    => [$cashTransaction->trx_type],
            'editing'         => array_merge(
                $cashTransaction->only(['id', 'trx_type', 'trx_date', 'cash_account_id', 'counter_account_id', 'amount', 'payee', 'description', 'reference']),
                ['lines' => $cashTransaction->lines()->get(['account_id', 'amount', 'description'])->toArray()],
            ),
        ]);
    }

    /** #16: simpan perubahan transaksi kas (ganti jurnal lama dengan baru). */
    public function update(Request $request, BsCashTransaction $cashTransaction, RecordCashTransaction $action): RedirectResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $v = $request->validate($this->rules(), [], $this->attributes());

        try {
            $action->update($cashTransaction, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.cashbook.show', $cashTransaction)->with('success', 'Transaksi diperbarui — jurnal diganti otomatis.');
    }

    /** Saldo Rekening — saldo kas/bank dari jurnal terposting (#84: per tanggal "s/d"). */
    /** Hub Cash Book — direktori sub-modul + ringkasan kas/bank. */
    public function hub(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $cash = $this->cashAccounts();
        $saldo = (float) BsJournalLine::query()
            ->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            ->where('bs_journals.status', 'posted')->whereNull('bs_journals.deleted_at')
            ->whereDate('bs_journals.journal_date', '<=', now()->toDateString())
            ->whereIn('bs_journal_lines.account_id', $cash->pluck('id'))
            ->selectRaw('COALESCE(SUM(debit-credit),0) as bal')->value('bal');

        return Inertia::render('BusinessSupport/CashBook/Hub', [
            'summary' => [
                'saldo'           => round($saldo, 2),
                'accounts'        => $cash->count(),
                'trx'             => BsCashTransaction::count(),
                'bon_outstanding' => round((float) \App\Domain\BusinessSupport\Models\BsCashAdvance::where('status', 'outstanding')->sum('amount'), 2),
                'bon_count'       => \App\Domain\BusinessSupport\Models\BsCashAdvance::where('status', 'outstanding')->count(),
                'giro'            => \App\Domain\BusinessSupport\Models\BsGiro::count(),
            ],
        ]);
    }

    /** Print-Out — hub cetak laporan Cash Book. */
    public function printHub(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        return Inertia::render('BusinessSupport/CashBook/PrintHub');
    }

    public function balances(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        // #84: saldo "per tanggal". Default hari ini; param tak valid → fallback hari ini (tidak 500).
        // Catatan: $request->date() MELEMPAR pada input tak terurai, jadi dibungkus rescue().
        $asOf = rescue(fn () => $request->date('as_of')?->toDateString(), null, false) ?? now()->toDateString();

        $cash = $this->cashAccounts();
        $net = BsJournalLine::query()
            ->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            ->where('bs_journals.status', 'posted')
            ->whereNull('bs_journals.deleted_at')                       // selaras dgn index GL (#83)
            ->whereDate('bs_journals.journal_date', '<=', $asOf)        // #84 s/d tanggal
            ->whereIn('bs_journal_lines.account_id', $cash->pluck('id'))
            ->selectRaw('bs_journal_lines.account_id, COALESCE(SUM(debit-credit),0) as bal')
            ->groupBy('bs_journal_lines.account_id')
            ->pluck('bal', 'account_id');

        $rows = $cash->map(fn ($a) => [
            'id' => $a->id, 'code' => $a->code, 'name' => $a->name,
            'balance' => (float) ($net[$a->id] ?? 0),
        ]);

        return Inertia::render('BusinessSupport/CashBook/Balances', [
            'rows'  => $rows->values(),
            'total' => $rows->sum('balance'),
            'asOf'  => $asOf,
        ]);
    }

    /** Rekening Ledger — buku besar mutasi per rekening kas/bank dengan saldo berjalan. */
    public function ledger(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $accountId = $request->integer('account_id') ?: null;
        $from = $request->input('from');
        $to   = $request->input('to');
        $entries = collect();
        $opening = 0.0;

        if ($accountId) {
            $base = fn () => BsJournalLine::query()
                ->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
                ->where('bs_journals.status', 'posted')->whereNull('bs_journals.deleted_at')
                ->where('bs_journal_lines.account_id', $accountId);

            if ($from) {
                $opening = (float) $base()->whereDate('bs_journals.journal_date', '<', $from)
                    ->selectRaw('COALESCE(SUM(debit-credit),0) as n')->value('n');
            }

            $rows = $base()
                ->when($from, fn ($q) => $q->whereDate('bs_journals.journal_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('bs_journals.journal_date', '<=', $to))
                ->orderBy('bs_journals.journal_date')->orderBy('bs_journal_lines.id')
                ->get(['bs_journals.journal_date', 'bs_journals.journal_number', 'bs_journals.reference', 'bs_journals.source', 'bs_journal_lines.description', 'bs_journal_lines.debit', 'bs_journal_lines.credit']);

            $running = $opening;
            $entries = $rows->map(function ($r) use (&$running) {
                $running += (float) $r->debit - (float) $r->credit;

                return [
                    'date' => (string) $r->journal_date, 'ref' => $r->reference ?: $r->journal_number,
                    'source' => $r->source, 'desc' => $r->description,
                    'debit' => (float) $r->debit, 'credit' => (float) $r->credit, 'balance' => round($running, 2),
                ];
            });
        }

        return Inertia::render('BusinessSupport/CashBook/Ledger', [
            'accounts'  => $this->cashAccounts(),
            'accountId' => $accountId,
            'opening'   => round($opening, 2),
            'entries'   => $entries->values(),
            'closing'   => $entries->isNotEmpty() ? $entries->last()['balance'] : round($opening, 2),
            'filters'   => ['from' => $from, 'to' => $to],
        ]);
    }

    /** Kalender Cek Giro — jatuh tempo cek/giro masuk & keluar per bulan. */
    public function giroCalendar(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $month = (string) ($request->input('month') ?: now()->format('Y-m'));
        $start = rescue(fn () => \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->startOfMonth(), now()->startOfMonth(), false);
        $end   = (clone $start)->endOfMonth();
        $month = $start->format('Y-m');

        $masuk = \App\Domain\BusinessSupport\Models\BsGiro::whereBetween('due_date', [$start->toDateString(), $end->toDateString()])->get()
            ->map(fn ($g) => [
                'date' => \Illuminate\Support\Carbon::parse($g->due_date)->toDateString(),
                'arah' => 'Masuk', 'no' => $g->giro_number ?: $g->cheque_number,
                'pihak' => $g->drawer ?: $g->bank_name, 'amount' => (float) $g->amount, 'status' => $g->status,
            ]);

        $keluar = \App\Domain\BusinessSupport\Models\BsOutgoingCheque::whereBetween('cheque_date', [$start->toDateString(), $end->toDateString()])->get()
            ->map(fn ($c) => [
                'date' => \Illuminate\Support\Carbon::parse($c->cheque_date)->toDateString(),
                'arah' => 'Keluar', 'no' => $c->cheque_number,
                'pihak' => $c->payee, 'amount' => (float) $c->amount, 'status' => $c->status,
            ]);

        $items = $masuk->concat($keluar)->sortBy('date')->values();

        return Inertia::render('BusinessSupport/CashBook/GiroCalendar', [
            'month' => $month,
            'items' => $items,
            'total' => round((float) $items->sum('amount'), 2),
        ]);
    }

    /** Approval Pengeluaran Kas & Bank — antrian BKK (out) menunggu persetujuan. */
    public function approvals(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $rows = BsCashTransaction::query()
            ->where('trx_type', 'out')->where('approval_status', 'pending')
            ->with([
                'cashAccount:id,code,name', 'counterAccount:id,code,name',
                // #160: rincian baris untuk pratinjau struktur transaksi inline.
                'lines:id,cash_transaction_id,account_id,amount,description', 'lines.account:id,code,name',
            ])
            ->latest('trx_date')->latest('id')
            ->paginate(50)->withQueryString();

        // Registrasi Pengeluaran Kas & Bank (AP) — Kuitansi Hutang menunggu persetujuan (status 'pending').
        $apRows = \App\Domain\BusinessSupport\Models\BsApKuitansi::query()
            ->where('status', 'pending')
            ->with(['supplier:id,code,name', 'cashAccount:id,code,name', 'lines.invoice:id,invoice_number,description'])
            ->latest('kuitansi_date')->latest('id')
            ->get()
            ->map(fn ($k) => [
                'id'              => $k->id,
                'kuitansi_number' => $k->kuitansi_number,
                'kuitansi_date'   => optional($k->kuitansi_date)->toDateString(),
                'supplier'        => $k->supplier ? $k->supplier->code.' · '.$k->supplier->name : '—',
                'cash_account'    => $k->cashAccount ? ['code' => $k->cashAccount->code, 'name' => $k->cashAccount->name] : null,
                'description'     => $k->description,
                'amount'          => (float) $k->total,
                'lines'           => $k->lines->map(fn ($l) => [
                    'id'             => $l->id,
                    'invoice_number' => $l->invoice?->invoice_number,
                    'description'    => $l->invoice?->description,
                    'amount'         => (float) $l->amount,
                ])->values(),
            ])->values();

        return Inertia::render('BusinessSupport/CashBook/Approvals', [
            'rows'       => $rows,
            'apRows'     => $apRows,
            'canApprove' => (bool) $request->user()?->can('businesssupport.approval.act'),
            'summary'    => [
                'count'    => BsCashTransaction::where('trx_type', 'out')->where('approval_status', 'pending')->count(),
                'total'    => round((float) BsCashTransaction::where('trx_type', 'out')->where('approval_status', 'pending')->sum('amount'), 2),
                'ap_count' => $apRows->count(),
                'ap_total' => round((float) $apRows->sum('amount'), 2),
            ],
        ]);
    }

    public function approve(Request $request, BsCashTransaction $cashTransaction, RecordCashTransaction $action): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        abort_unless($cashTransaction->trx_type === 'out', 422, 'Hanya pengeluaran (BKK) yang perlu disetujui.');

        $cashTransaction->update([
            'approval_status' => 'approved',
            'approved_by'     => $request->user()->id,
            'approved_at'     => now(),
        ]);

        // #159: jurnal Pengeluaran (out) baru diposting ke GL setelah disetujui.
        try {
            $action->postJournal($cashTransaction, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Pengeluaran {$cashTransaction->trx_number} disetujui — jurnal otomatis terbentuk.");
    }

    /** #160: Tolak pengeluaran (BKK) — hanya saat masih pending; tidak berjurnal. */
    public function reject(Request $request, BsCashTransaction $cashTransaction): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        abort_unless($cashTransaction->trx_type === 'out', 422, 'Hanya pengeluaran (BKK) yang perlu disetujui.');
        abort_unless($cashTransaction->approval_status === 'pending', 422, 'Hanya transaksi berstatus menunggu yang bisa ditolak.');

        $cashTransaction->update([
            'approval_status' => 'rejected',
            'approved_by'     => $request->user()->id,
            'approved_at'     => now(),
        ]);

        return back()->with('success', "Pengeluaran {$cashTransaction->trx_number} ditolak.");
    }

    /** #129: Download mutasi rekening (mengikuti filter layar) ke Excel (.xls). */
    public function export(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $accId = $request->integer('account_id') ?: null;
        $e  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');

        if ($accId) {
            // Mutasi 1 rekening (basis GL) — sama dgn indexByAccount, TANPA pagination.
            $acc  = BsAccount::find($accId, ['id', 'code', 'name']);
            $base = fn () => BsJournalLine::query()
                ->join('bs_journals as j', 'j.id', '=', 'bs_journal_lines.journal_id')
                ->where('j.status', 'posted')->whereNull('j.deleted_at')
                ->where('bs_journal_lines.account_id', $accId)
                ->when($request->input('from'), fn ($q, $v) => $q->whereDate('j.journal_date', '>=', $v))
                ->when($request->input('to'), fn ($q, $v) => $q->whereDate('j.journal_date', '<=', $v))
                ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w
                    ->where('j.reference', 'like', "%{$s}%")->orWhere('j.journal_number', 'like', "%{$s}%")
                    ->orWhere('j.description', 'like', "%{$s}%")->orWhere('bs_journal_lines.description', 'like', "%{$s}%")))
                ->when($request->input('type'), fn ($q, $v) => $v === 'in'
                    ? $q->where('bs_journal_lines.debit', '>', 0)
                    : $q->where('bs_journal_lines.credit', '>', 0));

            $opening = 0.0;
            if ($from = $request->input('from')) {
                $opening = (float) BsJournalLine::query()
                    ->join('bs_journals as j', 'j.id', '=', 'bs_journal_lines.journal_id')
                    ->where('j.status', 'posted')->whereNull('j.deleted_at')
                    ->where('bs_journal_lines.account_id', $accId)
                    ->whereDate('j.journal_date', '<', $from)
                    ->selectRaw('COALESCE(SUM(bs_journal_lines.debit-bs_journal_lines.credit),0) as bal')->value('bal');
            }

            $rows = $base()->orderBy('j.journal_date')->orderBy('bs_journal_lines.id')
                ->get(['bs_journal_lines.debit', 'bs_journal_lines.credit', 'bs_journal_lines.description as line_description',
                    'j.journal_number', 'j.reference', 'j.source', 'j.journal_date', 'j.description as journal_description']);

            $h = '<table border="1"><tr><th colspan="7" style="background:#0f766e;color:#fff">Mutasi Rekening: '.$e($acc?->code).' — '.$e($acc?->name).'</th></tr>'
                .'<tr style="background:#e2e8f0"><th>No.</th><th>Tanggal</th><th>Sumber</th><th>Keterangan</th><th>Masuk</th><th>Keluar</th><th>Saldo</th></tr>';
            $bal = $opening;
            foreach ($rows as $r) {
                $bal += (float) $r->debit - (float) $r->credit;
                $h .= '<tr><td>'.$e($r->reference ?: $r->journal_number).'</td><td>'.$e(optional($r->journal_date)->toDateString())
                    .'</td><td>'.$e(strtoupper((string) $r->source)).'</td><td>'.$e($r->line_description ?: $r->journal_description)
                    .'</td><td>'.$rp($r->debit).'</td><td>'.$rp($r->credit).'</td><td>'.$rp($bal).'</td></tr>';
            }
            $h .= '</table>';
            $file = 'Mutasi-'.($acc?->code ?? 'rekening').'-'.now()->format('Ymd-His').'.xls';
        } else {
            // Register "Semua Rekening" (BsCashTransaction).
            $rows = BsCashTransaction::query()
                ->with(['cashAccount:id,code,name'])
                ->when($request->input('type'), fn ($q, $v) => $q->where('trx_type', $v))
                ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('trx_number', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")->orWhere('payee', 'like', "%{$s}%")))
                ->when($request->input('from'), fn ($q, $v) => $q->whereDate('trx_date', '>=', $v))
                ->when($request->input('to'), fn ($q, $v) => $q->whereDate('trx_date', '<=', $v))
                ->latest('trx_date')->latest('id')->get();
            $h = '<table border="1"><tr style="background:#0f766e;color:#fff"><th>No.</th><th>Tanggal</th><th>Jenis</th><th>Rekening</th><th>Keterangan/Pihak</th><th>Masuk</th><th>Keluar</th></tr>';
            foreach ($rows as $r) {
                $masuk  = $r->trx_type === 'in' ? (float) $r->amount : 0;
                $keluar = in_array($r->trx_type, ['out', 'transfer'], true) ? (float) $r->amount : 0;
                $h .= '<tr><td>'.$e($r->trx_number).'</td><td>'.$e(optional($r->trx_date)->toDateString()).'</td><td>'.$e(BsCashTransaction::TYPES[$r->trx_type] ?? $r->trx_type)
                    .'</td><td>'.$e($r->cashAccount?->name).'</td><td>'.$e($r->description).($r->payee ? ' · '.$e($r->payee) : '').'</td><td>'.$rp($masuk).'</td><td>'.$rp($keluar).'</td></tr>';
            }
            $h .= '</table>';
            $file = 'Mutasi-semua-rekening-'.now()->format('Ymd-His').'.xls';
        }

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'.$h.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /** #130: Cetak bukti transaksi kas (BKM/BKK/Transfer) — auto-print, layout eProperty + logo. */
    public function print(Request $request, BsCashTransaction $cashTransaction): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $cashTransaction->load(['cashAccount:id,code,name', 'counterAccount:id,code,name', 'lines.account:id,code,name']);
        $createdBy = $cashTransaction->created_by ? \App\Models\User::find($cashTransaction->created_by)?->name : null;

        return response($this->renderCashPrint($cashTransaction, $request->user()?->name, $createdBy))
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Bukti Pengeluaran/Penerimaan Kas/Bank — layout mengikuti format eProperty (logo + grid + tanda tangan). */
    private function renderCashPrint(BsCashTransaction $trx, ?string $printedBy = null, ?string $createdBy = null): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        // Nominal mengikuti lampiran: "Rp. 1,000,000.00" (negatif → dalam tanda kurung).
        $amt = function ($n): string {
            $neg = (float) $n < 0;

            return 'Rp. '.($neg ? '(' : '').number_format(abs((float) $n), 2, '.', ',').($neg ? ')' : '');
        };

        $coName = (string) config('business_support.company.name', 'PT. MITRA USAHA PROPERTINDO');
        $addr   = trim((string) config('business_support.company.address', ''));
        // Default melengkapi alamat MUP penuh agar sesuai lampiran; alamat khusus via config tetap menimpa.
        if ($addr === '' || $addr === 'Sidoarjo, Jawa Timur') {
            $addr = 'Jl. Kahuripan Raya Ruko Avenue No 27 - 30, Sidoarjo, Jawa Timur';
        }
        $aParts   = explode(',', $addr, 2);
        $addrHtml = $e(trim($aParts[0])).(isset($aParts[1]) ? '<br>'.$e(trim($aParts[1])) : '');
        $logo     = '/images/mup-logo.jpeg';

        $titles = [
            'in'       => 'BUKTI<br>PENERIMAAN KAS/BANK',
            'out'      => 'BUKTI<br>PENGELUARAN KAS/BANK',
            'transfer' => 'BUKTI<br>TRANSFER ANTAR REKENING',
        ];
        $title = $titles[$trx->trx_type] ?? 'BUKTI<br>TRANSAKSI KAS/BANK';

        $sourceOfFund = trim($e($trx->cashAccount?->code).' | '.$e($trx->cashAccount?->name), ' |');
        $cqbg    = $trx->reference ? $e($trx->reference) : '-';
        $reffNo  = $e($trx->trx_number);
        $dateStr = optional($trx->trx_date)->format('d-m-Y') ?: '-';

        $lines = $trx->lines->count()
            ? $trx->lines
            : collect([(object) ['account' => $trx->counterAccount, 'amount' => $trx->amount, 'description' => $trx->description]]);
        $lineRows = '';
        foreach ($lines as $l) {
            $lineRows .= '<tr><td>'.$e($l->account?->code).'</td><td>'.$e($l->account?->name).'</td>'
                .'<td class="c"></td><td>'.$e($l->description).'</td><td class="r">'.$amt($l->amount).'</td></tr>';
        }

        $terbilang    = $e(strtoupper(trim(preg_replace('/\s+/', ' ', terbilang((int) round((float) $trx->amount))))).' RUPIAH');
        $totalBayar   = $amt($trx->amount);
        $deskripsi    = $e($trx->description);
        $kasirName    = $createdBy ? '<span class="nm">'.$e($createdBy).'</span>' : '';
        $diterimaName = $trx->payee ? '<span class="nm">'.$e($trx->payee).'</span>' : '';
        $printDate    = now()->format('d-m-Y H.i.s');
        $printUser    = $printedBy ? $e($printedBy) : '';
        $docNo        = $e($trx->trx_number);

        $css = '@page{size:A4;margin:10mm}*{box-sizing:border-box}'
            .'body{font-family:Arial,"Segoe UI",sans-serif;font-size:11px;color:#1f2937;margin:0}'
            .'table{border-collapse:collapse}'
            .'.hdr{width:100%;margin-bottom:10px}.hdr td{vertical-align:middle}'
            .'.logo{width:58px;height:58px;object-fit:contain}'
            .'.co{padding-left:10px}.co .nm{font-weight:bold;font-size:13px;line-height:1.2}.co .ad{font-size:11px;line-height:1.35;margin-top:2px}'
            .'.titlebox{border:1px solid #9aa0a6;border-radius:12px;padding:14px 8px;text-align:center;font-weight:bold;font-size:16px;line-height:1.25}'
            .'.gridwrap{border:1px solid #9aa0a6;border-radius:10px;overflow:hidden;margin-bottom:10px}'
            .'.info{width:100%}.info td{padding:7px 10px;border:1px solid #dbe0e6;vertical-align:top;font-size:11px}'
            .'.info td.lbl{width:115px;white-space:nowrap}.info td.val{word-break:break-word}'
            .'.info tr:first-child td{border-top:0}.info tr:last-child td{border-bottom:0}.info td:first-child{border-left:0}.info td:last-child{border-right:0}'
            .'.lines{width:100%;margin-bottom:10px;font-size:11px}.lines th,.lines td{border:1px solid #b9bfc6;padding:6px 8px;vertical-align:top}'
            .'.lines th{text-align:center;font-weight:bold}.lines .r{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}.lines .c{text-align:center}'
            .'.tt{width:100%;margin-bottom:10px}.tt td{vertical-align:top}'
            .'.rbox{border:1px solid #9aa0a6;border-radius:10px;padding:8px 12px;font-size:11px}'
            .'.sign{width:100%;margin-top:6px}.sign td{vertical-align:top;padding:0}'
            .'.sigcell{border:1px solid #333;height:98px;position:relative;padding:6px 8px}'
            .'.role{font-size:10.5px;line-height:1.3}.sigline{position:absolute;left:8px;right:8px;bottom:7px;border-top:1px dotted #555;padding-top:3px;min-height:15px}'
            .'.nm{font-weight:bold;font-size:10.5px}.pinfo{padding:6px 10px;font-size:10.5px;line-height:1.55;vertical-align:top}'
            .'@media print{.noprint{display:none}}';

        $sig = fn (string $role, string $name = '') => '<div class="sigcell"><div class="role">'.$role.'</div><div class="sigline">'.$name.'</div></div>';

        return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>'.$docNo.'</title><style>'.$css.'</style></head><body>'
            .'<table class="hdr"><tr>'
                .'<td style="width:60%"><table><tr><td><img class="logo" src="'.$logo.'" alt="MUP"></td>'
                    .'<td class="co"><div class="nm">'.$e($coName).'</div><div class="ad">'.$addrHtml.'</div></td></tr></table></td>'
                .'<td style="width:40%"><div class="titlebox">'.$title.'</div></td>'
            .'</tr></table>'
            .'<div class="gridwrap"><table class="info">'
                .'<tr><td class="lbl">Source of Fund :</td><td class="val">'.$sourceOfFund.'</td>'
                    .'<td class="lbl">Reff No :</td><td class="val">'.$reffNo.'</td></tr>'
                .'<tr><td class="lbl">No. CQ/BG :</td><td class="val">'.$cqbg.'</td>'
                    .'<td class="lbl">Date :</td><td class="val">'.$dateStr.'</td></tr>'
            .'</table></div>'
            .'<table class="lines"><thead><tr>'
                .'<th style="width:14%">Account No</th><th style="width:24%">Account Name</th><th style="width:8%">Dept</th>'
                .'<th>Remark/Description</th><th style="width:20%">Amount</th></tr></thead>'
                .'<tbody>'.$lineRows.'</tbody></table>'
            .'<table class="tt"><tr>'
                .'<td style="width:60%"><div class="rbox">Terbilang : <b>'.$terbilang.'</b></div></td>'
                .'<td style="width:3%"></td>'
                .'<td><div class="rbox">Total Bayar : <b>'.$totalBayar.'</b></div></td>'
            .'</tr></table>'
            .'<div class="rbox" style="margin-bottom:14px">Deskripsi : '.$deskripsi.'</div>'
            .'<table class="sign"><tr>'
                .'<td style="width:15%">'.$sig('Kasir<br>Finance', $kasirName).'</td>'
                .'<td style="width:15%">'.$sig('Diperiksa Oleh<br>Controller').'</td>'
                .'<td style="width:15%">'.$sig('Diperiksa Oleh<br>Finance').'</td>'
                .'<td style="width:15%">'.$sig('Diperiksa Oleh<br>Akunting').'</td>'
                .'<td style="width:15%">'.$sig('Disetujui Oleh<br>Direksi / GM').'</td>'
                .'<td class="pinfo">Tgl. Print : <b>'.$printDate.'</b><br>User : <b>'.$printUser.'</b><br>Print# : <b>1</b><br>Otorisasi :</td>'
            .'</tr><tr>'
                .'<td>'.$sig('Diterima Oleh', $diterimaName).'</td><td colspan="5"></td>'
            .'</tr></table>'
            .'<button class="noprint" onclick="window.print()" style="margin-top:14px;padding:6px 14px">Cetak</button>'
            .'<script>window.onload=function(){setTimeout(function(){window.print()},350)}</script></body></html>';
    }

    /** Aturan validasi transaksi kas (multi-baris akun lawan #15). */
    private function rules(): array
    {
        // #161: Transfer antar rekening wajib TEPAT 1 baris (rekening tujuan tunggal).
        $isTransfer = request()->input('trx_type') === 'transfer';

        return [
            'trx_type'           => ['required', 'in:in,out,transfer'],
            'trx_date'           => ['required', 'date'],
            'cash_account_id'    => ['required', 'integer', 'exists:bs_accounts,id'],
            'payee'              => ['nullable', 'string', 'max:150'],
            'description'        => ['required', 'string', 'max:255'],
            'reference'          => ['nullable', 'string', 'max:150'],
            'lines'              => $isTransfer ? ['required', 'array', 'min:1', 'max:1'] : ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'integer', 'exists:bs_accounts,id', 'different:cash_account_id'],
            'lines.*.amount'     => ['required', 'numeric', 'min:0.01'],
            'lines.*.description'=> ['nullable', 'string', 'max:255'],
        ];
    }

    private function attributes(): array
    {
        return [
            'lines'              => 'rincian akun',
            'lines.*.account_id' => 'akun lawan',
            'lines.*.amount'     => 'nominal',
        ];
    }

    /** Rekening kas/bank = akun postable di bawah KAS DAN SETARA KAS (kode 101010…). */
    private function cashAccounts()
    {
        return BsAccount::where('is_active', true)->where('is_postable', true)
            ->where('code', 'like', '101010%')
            ->orderBy('code')->get(['id', 'code', 'name']);
    }
}
