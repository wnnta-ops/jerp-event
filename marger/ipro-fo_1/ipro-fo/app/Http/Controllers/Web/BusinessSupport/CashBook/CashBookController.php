<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\CashBook;

use App\Domain\BusinessSupport\Actions\RecordCashTransaction;
use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCashTransaction;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsJournalLine;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CashBookController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('cashbook.transaction.view'), 403);

        $accId = $request->integer('account_id') ?: null;

        // #81/#82/#83: bila 1 rekening dipilih → tampilkan SELURUH mutasi GL rekening tsb
        // (termasuk pembayaran Kuitansi/Giro/Payroll yang hanya berjurnal), sehingga total atas,
        // kolom Masuk/Keluar, saldo berjalan, & halaman Saldo Rekening konsisten satu basis.
        return $accId
            ? $this->indexByAccount($request, $accId)
            : $this->indexAllAccounts($request);
    }

    /** "Semua Rekening" — register transaksi kas (BsCashTransaction) + pelunasan hutang (AP). Saldo berjalan tidak ditampilkan. */
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
            ->get();

        $isTransferView = $request->input('type') === 'transfer';

        if ($isTransferView) {
            // #7: tampilkan KEDUA sisi mutasi transfer (keluar di pengirim + masuk di penerima).
            // Tiap transaksi transfer dipecah jadi 2 baris sintetis tanpa mengubah penyimpanan/jurnal.
            $rows = $rows->flatMap(function (BsCashTransaction $r): array {
                $amt    = (float) $r->amount;
                $sender = $r->cashAccount;
                $recv   = $r->counterAccount;

                $out = clone $r;
                $out->setAttribute('row_key', $r->id.'-out');
                $out->setAttribute('trx_id', $r->id);
                $out->setAttribute('rekening_label', $sender?->name);
                $out->setAttribute('masuk', 0.0);
                $out->setAttribute('keluar', $amt);
                $out->setAttribute('running_saldo', null);
                $out->setAttribute('description', trim((string) $r->description.' → ke '.($recv?->name ?? '—')));

                $in = clone $r;
                $in->setAttribute('row_key', $r->id.'-in');
                $in->setAttribute('trx_id', $r->id);
                $in->setAttribute('rekening_label', $recv?->name);
                $in->setAttribute('masuk', $amt);
                $in->setAttribute('keluar', 0.0);
                $in->setAttribute('running_saldo', null);
                $in->setAttribute('description', trim((string) $r->description.' ← dari '.($sender?->name ?? '—')));

                return [$out, $in];
            });
        } else {
            $rows->transform(function (BsCashTransaction $r): BsCashTransaction {
                $amt = (float) $r->amount;
                $r->setAttribute('masuk', $r->trx_type === 'in' ? $amt : 0.0);
                $r->setAttribute('keluar', in_array($r->trx_type, ['out', 'transfer'], true) ? $amt : 0.0);
                $r->setAttribute('running_saldo', null);
                $r->setAttribute('row_key', 'trx-'.$r->id);

                return $r;
            });
        }

        // Pelunasan hutang (AP) hanya berjurnal — tidak punya baris BsCashTransaction — sehingga
        // dulu tak pernah tampil di register "Semua rekening" (hanya terlihat saat 1 rekening
        // dipilih / mode GL). Padahal itu kas keluar sungguhan. Baris sintetis di bawah menutup
        // celah tsb untuk layar Kas Keluar & Pengeluaran Kas & Bank (keduanya type=out).
        $apRows = $isTransferView ? collect() : $this->apPaymentRows($request);
        $rows = $rows->concat($apRows)
            ->sortByDesc(fn ($r) => [(string) ($r->trx_date instanceof \Illuminate\Support\Carbon ? $r->trx_date->toDateString() : $r->trx_date), (int) $r->id])
            ->values();

        // Paginasi manual: sumbernya dua (transaksi kas + pelunasan AP), tak bisa lagi paginate() di query.
        $perPage = 50;
        $page    = max(1, (int) $request->integer('page', 1));
        $paged   = new \Illuminate\Pagination\LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        $apOut = (float) $apRows->sum('keluar');

        // #7: untuk view Transfer, masuk=keluar=SUM(amount transfer) → arus bersih 0 (mutasi internal).
        $summary = $isTransferView
            ? (function () use ($filtered) {
                $sum = (float) (clone $filtered())->where('trx_type', 'transfer')->sum('amount');

                return ['in' => $sum, 'out' => $sum];
            })()
            : [
                'in'  => (float) (clone $filtered())->where('trx_type', 'in')->sum('amount'),
                // Keluar = Pengeluaran + Transfer (kolom Keluar pada baris juga mencakup transfer → konsisten)
                // + pelunasan hutang, agar total atas cocok dengan baris yang tampil.
                'out' => (float) (clone $filtered())->whereIn('trx_type', ['out', 'transfer'])->sum('amount') + $apOut,
            ];

        return Inertia::render('BusinessSupport/CashBook/Index', [
            'transactions' => $paged,
            'types'        => BsCashTransaction::TYPES,
            'cashAccounts' => $this->cashAccounts(),
            'filters'      => $request->only(['type', 'search', 'account_id', 'from', 'to']),
            'summary'      => $summary,
            'glMode'       => false,
        ]);
    }

    /**
     * Pelunasan hutang (bs_ap_payments) sebagai baris "Kas Keluar" sintetis untuk register
     * "Semua rekening". Tidak menyentuh penyimpanan/jurnal — murni tampilan.
     *
     * HANYA yang benar-benar mengeluarkan kas: bs_ap_payments.cash_account_id bisa berisi akun
     * "BG-Cek Beredar" (kewajiban) untuk pelunasan lewat penyerahan warkat — kasnya baru keluar
     * saat warkat dicairkan, jadi baris seperti itu BUKAN kas keluar dan sengaja dilewati.
     * Baris yang sudah punya BsCashTransaction sendiri juga dilewati agar tidak terhitung dua kali.
     */
    private function apPaymentRows(Request $request)
    {
        $type = $request->input('type');
        if ($type !== null && $type !== 'out') {
            return collect(); // pelunasan hutang selalu kas KELUAR
        }

        $cashIds = $this->cashAccounts()->pluck('id');

        return \App\Domain\BusinessSupport\Models\BsApPayment::query()
            ->with(['invoice:id,invoice_number,supplier_id', 'invoice.supplier:id,name', 'cashAccount:id,code,name'])
            ->whereIn('cash_account_id', $cashIds)
            // Jangan terhitung dua kali bila jurnal yang sama juga punya baris transaksi kas.
            // Subquery, BUKAN whereNotIn(pluck seluruh journal_id): daftar itu tumbuh tanpa batas
            // dan akan menembus batas ±65.535 placeholder MySQL (error 1390) → seluruh register &
            // ekspornya mati. NOT EXISTS juga otomatis benar untuk journal_id NULL (tidak ada yang
            // cocok → baris tetap ikut), tanpa perlu whereNull terpisah.
            ->whereNotExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('bs_cash_transactions')
                ->whereColumn('bs_cash_transactions.journal_id', 'bs_ap_payments.journal_id')
                ->whereNull('bs_cash_transactions.deleted_at'))
            ->when($request->input('from'), fn ($q, $v) => $q->whereDate('payment_date', '>=', $v))
            ->when($request->input('to'), fn ($q, $v) => $q->whereDate('payment_date', '<=', $v))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('reference', 'like', "%{$s}%")
                ->orWhereHas('invoice', fn ($i) => $i->where('invoice_number', 'like', "%{$s}%"))
                ->orWhereHas('invoice.supplier', fn ($i) => $i->where('name', 'like', "%{$s}%"))))
            ->latest('payment_date')->latest('id')
            ->get()
            ->map(function ($p) {
                $inv = $p->invoice;
                // Bentuk baris disamakan dgn BsCashTransaction agar layar tak perlu tahu asalnya.
                $r = new BsCashTransaction();
                $r->setAttribute('id', $p->id);
                $r->setAttribute('row_key', 'ap-'.$p->id);           // beda ruang dari 'trx-<id>'
                $r->setAttribute('trx_id', null);                     // bukan transaksi kas → tak bisa dibuka/dicetak
                $r->setAttribute('is_ap_payment', true);
                $r->setAttribute('trx_number', $inv?->invoice_number ?? ('AP-'.$p->id));
                $r->setAttribute('trx_date', $p->payment_date);
                $r->setAttribute('trx_type', 'out');
                $r->setAttribute('rekening_label', $p->cashAccount?->name);
                $r->setAttribute('description', 'Pelunasan hutang '.($inv?->invoice_number ?? ''));
                $r->setAttribute('payee', $inv?->supplier?->name);
                $r->setAttribute('masuk', 0.0);
                $r->setAttribute('keluar', (float) $p->amount);
                $r->setAttribute('running_saldo', null);

                return $r;
            });
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
        abort_unless($request->user()?->can('cashbook.transaction.create'), 403);

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
            'counterAccounts'=> BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'type', 'cost_center_optional']),
            // Cost center per baris — dimensi yang menautkan pengeluaran ke Budget Control.
            'costCenters'    => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'defaultType'    => $allowedTypes[0] ?? 'in',
            'allowedTypes'   => array_values($allowedTypes),
        ]);
    }

    public function store(Request $request, RecordCashTransaction $action): RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.transaction.create'), 403);

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
        abort_unless($request->user()?->can('cashbook.transaction.view'), 403);

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
        abort_unless($request->user()?->can('cashbook.transaction.update'), 403);

        return Inertia::render('BusinessSupport/CashBook/Form', [
            'types'           => BsCashTransaction::TYPES,
            'cashAccounts'    => $this->cashAccounts(),
            'counterAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'type', 'cost_center_optional']),
            'costCenters'     => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'defaultType'     => $cashTransaction->trx_type,
            // #148: saat edit, kunci jenis ke jenis transaksi yang sudah ada (tidak boleh diubah).
            'allowedTypes'    => [$cashTransaction->trx_type],
            // trx_date DITULIS ULANG sebagai string Y-m-d.
            //
            // Model::only() mengambil nilai lewat getAttribute(), jadi yang keluar adalah objek
            // Carbon — dan json_encode() atas Carbon memakai serialisasi Carbon sendiri (ISO
            // UTC), MELEWATI format cast pada model. Di zona Asia/Jakarta (UTC+7), tanggal
            // 2026-08-16 terkirim sebagai "2026-08-15T17:00:00Z" dan layar membacanya
            // 15 Agustus — tanggal mundur sehari setiap kali dokumen dibuka untuk diedit.
            'editing'         => array_merge(
                $cashTransaction->only(['id', 'trx_type', 'cash_account_id', 'counter_account_id', 'amount', 'payee', 'description', 'reference']),
                ['trx_date' => $cashTransaction->trx_date?->toDateString()],
                // cost_center_id ikut dibaca: tanpa itu, membuka transaksi lama untuk disunting
                // akan mengosongkan cost centernya diam-diam, lalu menyimpannya kembali tanpa
                // dimensi — realisasi anggarannya hilang tanpa satu pun galat.
                ['lines' => $cashTransaction->lines()->get(['account_id', 'cost_center_id', 'amount', 'description'])->toArray()],
            ),
        ]);
    }

    /** #16: simpan perubahan transaksi kas (ganti jurnal lama dengan baru). */
    public function update(Request $request, BsCashTransaction $cashTransaction, RecordCashTransaction $action): RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.transaction.update'), 403);

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
        abort_unless($request->user()?->can('cashbook.hub.view'), 403);

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
        abort_unless($request->user()?->can('cashbook.print.view'), 403);

        return Inertia::render('BusinessSupport/CashBook/PrintHub');
    }

    public function balances(Request $request): Response
    {
        abort_unless($request->user()?->can('cashbook.saldo.view'), 403);

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
        abort_unless($request->user()?->can('cashbook.ledger.view'), 403);

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
                // bs_journals.description ikut diambil: keterangan baris jurnal sering hanya
                // menyebut nomor dokumen ("Transfer bon sementara BON/2608/0006"), sedangkan
                // keterangan KEPALA jurnal-lah yang menerangkan perkaranya — mis. "Pembayaran
                // Bon Sementara — Kasbon olahraga (Andi Wijaya — Manager Finance)".
                ->get(['bs_journal_lines.id', 'bs_journal_lines.journal_id', 'bs_journals.journal_date', 'bs_journals.journal_number', 'bs_journals.reference', 'bs_journals.source', 'bs_journals.description as journal_description', 'bs_journal_lines.description', 'bs_journal_lines.debit', 'bs_journal_lines.credit']);

            // Anotasi per-mutasi: tag kategori arus kas + pembagian cluster (di-load sekali).
            $lineIds = $rows->pluck('id');
            $cfTags = \App\Domain\BusinessSupport\Models\BsLedgerCfTag::whereIn('journal_line_id', $lineIds)->get()->keyBy('journal_line_id');
            $splits = \App\Domain\BusinessSupport\Models\BsLedgerClusterSplit::whereIn('journal_line_id', $lineIds)->get()->groupBy('journal_line_id');

            // Peta baris ledger → transaksi kas, untuk cetak bukti massal dari layar ini.
            // Kaitannya lewat bs_cash_transactions.journal_id (bukan cocok-cocokan nomor referensi).
            // Mutasi non-kas (saldo awal, memorial/jurnal manual, AP, bon sementara) TIDAK punya
            // bukti kas/bank → trx_id null → layar menonaktifkan checkbox-nya.
            $trxByJournal = BsCashTransaction::whereIn('journal_id', $rows->pluck('journal_id')->filter()->unique()->all())
                ->pluck('id', 'journal_id');

            $running = $opening;
            $entries = $rows->map(function ($r) use (&$running, $cfTags, $splits, $trxByJournal) {
                $running += (float) $r->debit - (float) $r->credit;
                $tag = $cfTags[$r->id] ?? null;

                // Keterangan kepala jurnal hanya dikirim bila BERBEDA dari keterangan barisnya —
                // kalau sama persis, mencetaknya dua kali cuma menggandakan kalimat yang sama.
                $ketBaris  = trim((string) $r->description);
                $ketJurnal = trim((string) $r->journal_description);

                return [
                    'id' => $r->id,
                    'journal_id' => $r->journal_id,   // tujuan pranala nomor referensi
                    'trx_id' => $trxByJournal[$r->journal_id] ?? null,
                    'journal_id' => $r->journal_id, // #3 — buka Detail Jurnal dari baris Rekening Ledger
                    'date' => (string) $r->journal_date, 'ref' => $r->reference ?: $r->journal_number,
                    'source' => $r->source, 'desc' => $ketBaris,
                    'journal_desc' => ($ketJurnal !== '' && $ketJurnal !== $ketBaris) ? $ketJurnal : null,
                    'debit' => (float) $r->debit, 'credit' => (float) $r->credit, 'balance' => round($running, 2),
                    'cf_direct_category_id' => $tag?->cf_direct_category_id,
                    'cf_indirect_category_id' => $tag?->cf_indirect_category_id,
                    // Pembagian per KATEGORI cash flow; cluster tidak lagi dipakai di layar ini.
                    'clusters' => ($splits[$r->id] ?? collect())->map(fn ($s) => ['cf_category_id' => $s->cf_category_id, 'amount' => (float) $s->amount, 'note' => $s->note])->values(),
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
            // Pilihan kategori arus kas (dari Setup GL) + cluster untuk anotasi per-mutasi.
            'cfDirect'  => \App\Domain\BusinessSupport\Models\BsCashFlowCategory::where('kind', 'direct')->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'code', 'name', 'activity']),
            'cfIndirect' => \App\Domain\BusinessSupport\Models\BsCashFlowCategory::where('kind', 'indirect')->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'code', 'name', 'activity']),
            // Kategori arus kas (modul Cash Flow) — pilihan per baris pembagian; inilah yang
            // membuat mutasi ini ikut terhitung pada Report Cashflow.
            'cfCategories' => \App\Domain\BusinessSupport\Models\BsCfCategory::where('is_active', true)
                ->orderBy('sort_order')->orderBy('id')->get(['id', 'code', 'name', 'section', 'group_name']),
        ]);
    }

    /** Simpan tag kategori arus kas (Direct/Indirect) untuk satu mutasi Rekening Ledger. */
    public function ledgerCf(Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.ledger.view'), 403);
        $v = $request->validate([
            'journal_line_id'         => ['required', 'integer', 'exists:bs_journal_lines,id'],
            'cf_direct_category_id'   => ['nullable', 'integer', 'exists:bs_cash_flow_categories,id'],
            'cf_indirect_category_id' => ['nullable', 'integer', 'exists:bs_cash_flow_categories,id'],
        ]);
        \App\Domain\BusinessSupport\Models\BsLedgerCfTag::updateOrCreate(
            ['journal_line_id' => $v['journal_line_id']],
            ['cf_direct_category_id' => $v['cf_direct_category_id'] ?? null, 'cf_indirect_category_id' => $v['cf_indirect_category_id'] ?? null],
        );

        return back()->with('success', 'Kategori arus kas mutasi disimpan.');
    }

    /** Simpan pembagian cluster untuk satu mutasi Rekening Ledger (replace-all baris cluster). */
    public function ledgerClusters(Request $request): \Illuminate\Http\RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.ledger.view'), 403);
        $v = $request->validate([
            'journal_line_id'     => ['required', 'integer', 'exists:bs_journal_lines,id'],
            'splits'              => ['array'],
            // Cluster TIDAK lagi diminta — pembagian ditentukan oleh kategori cash flow saja.
            // Kategori jadi WAJIB: tanpa itu pembagian tidak menggolongkan apa pun dan hanya
            // akan jadi angka menggantung yang tidak pernah muncul di Report Cashflow.
            'splits.*.cf_category_id' => ['required', 'integer', 'exists:bs_cf_categories,id'],
            // Nominal tidak boleh negatif: nilai minus bisa dipakai menutupi selisih secara semu
            // sehingga total terlihat pas padahal pembagiannya tidak masuk akal.
            'splits.*.amount'     => ['required', 'numeric', 'min:0'],
            'splits.*.note'       => ['nullable', 'string', 'max:255'],
        ], [
            // Aplikasi ini belum memuat terjemahan validasi bawaan — tanpa pesan ini
            // operator hanya melihat "validation.required".
            'splits.*.cf_category_id.required' => 'Setiap baris pembagian harus punya kategori cash flow.',
            'splits.*.cf_category_id.exists'   => 'Kategori cash flow yang dipilih tidak ditemukan.',
            'splits.*.amount.required'         => 'Nominal pembagian wajib diisi.',
            'splits.*.amount.min'              => 'Nominal pembagian tidak boleh minus.',
        ]);

        // PENJAGA UTAMA: total pembagian cash flow WAJIB sama dengan nilai mutasi baris jurnalnya.
        // Tanpa ini, laporan arus kas per cluster bisa menyimpang dari mutasi kas yang sebenarnya.
        // Daftar kosong tetap diizinkan — artinya pembagian dihapus.
        $splits = $v['splits'] ?? [];
        if ($splits !== []) {
            $line = \App\Domain\BusinessSupport\Models\BsJournalLine::find($v['journal_line_id']);
            // Cast ke float DULU baru dipilih: kolom decimal dikembalikan sebagai string "0.00"
            // yang bernilai TRUTHY di PHP, sehingga `$line->debit ?: $line->credit` akan salah
            // memilih 0 untuk mutasi sisi kredit.
            $debit    = round((float) ($line?->debit ?? 0), 2);
            $credit   = round((float) ($line?->credit ?? 0), 2);
            $mutation = $debit > 0 ? $debit : $credit;
            $total    = round((float) array_sum(array_map(static fn ($s) => (float) $s['amount'], $splits)), 2);
            if (abs($total - $mutation) >= 0.01) {
                $rp = static fn ($x) => 'Rp '.number_format((float) $x, 2, ',', '.');

                throw \Illuminate\Validation\ValidationException::withMessages([
                    'splits' => 'Total pembagian cash flow ('.$rp($total).') harus sama dengan nilai mutasi jurnal ('.$rp($mutation).'). Selisih '.$rp(abs($total - $mutation)).'.',
                ]);
            }
        }

        $tenantId = tenant_id() ?? default_tenant_id();
        \DB::transaction(function () use ($v, $tenantId): void {
            \App\Domain\BusinessSupport\Models\BsLedgerClusterSplit::where('journal_line_id', $v['journal_line_id'])->forceDelete();
            foreach (($v['splits'] ?? []) as $s) {
                \App\Domain\BusinessSupport\Models\BsLedgerClusterSplit::create([
                    'tenant_id'       => $tenantId,
                    'journal_line_id' => $v['journal_line_id'],
                    'cluster_id'      => null,   // tidak lagi dipakai; kolomnya disimpan untuk data lama
                    'cf_category_id'  => (int) $s['cf_category_id'],
                    'amount'          => round((float) $s['amount'], 2),
                    'note'            => $s['note'] ?? null,
                ]);
            }
        });

        return back()->with('success', 'Pembagian cash flow mutasi disimpan.');
    }

    /** Kalender Cek Giro — jatuh tempo cek/giro masuk & keluar per bulan. */
    public function giroCalendar(Request $request): Response
    {
        abort_unless($request->user()?->can('cashbook.giro-calendar.view'), 403);

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
        abort_unless($request->user()?->can('cashbook.transaction.approve'), 403);

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
        $apKuitansi = \App\Domain\BusinessSupport\Models\BsApKuitansi::query()
            ->where('status', 'pending')
            ->with(['supplier:id,code,name', 'cashAccount:id,code,name',
                'lines.invoice:id,invoice_number,description,source_type,source_id'])
            ->latest('kuitansi_date')->latest('id')
            ->get();

        $spkPerInvoice = $this->spkPerInvoice($apKuitansi);

        $apRows = $apKuitansi
            ->map(fn ($k) => [
                'id'              => $k->id,
                'kuitansi_number' => $k->kuitansi_number,
                'kuitansi_date'   => optional($k->kuitansi_date)->toDateString(),
                'supplier'        => $k->supplier ? $k->supplier->code.' · '.$k->supplier->name : '—',
                'cash_account'    => $k->cashAccount ? ['code' => $k->cashAccount->code, 'name' => $k->cashAccount->name] : null,
                'description'     => $k->description,
                'amount'          => (float) $k->total,
                // Nomor SPK yang dibayar kuitansi ini — dari posting AP-nya, bukan diketik.
                // Satu kuitansi bisa membayar beberapa invoice sekaligus, jadi yang
                // dipulangkan DAFTAR: menampilkan yang pertama saja menyembunyikan sisanya,
                // dan orang menyetujui pembayaran yang dikiranya untuk satu SPK.
                'spk'             => $k->lines->map(fn ($l) => $spkPerInvoice[$l->ap_invoice_id] ?? null)
                    ->filter()->unique()->values(),
                'lines'           => $k->lines->map(fn ($l) => [
                    'id'             => $l->id,
                    'invoice_number' => $l->invoice?->invoice_number,
                    'description'    => $l->invoice?->description,
                    'spk'            => $spkPerInvoice[$l->ap_invoice_id] ?? null,
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

    /**
     * Nomor SPK per invoice AP — id invoice => nomor SPK.
     *
     * Invoice yang lahir dari posting SPK menyimpan source_type 'spk' dengan source_id
     * menunjuk BARIS PROGRESS-nya, sedangkan nomor SPK ada di work order induknya. Karena
     * itu perlu satu lompatan; dilakukan SEKALI untuk seluruh halaman, bukan sekali per
     * baris.
     *
     * Invoice dari sumber lain (PO, refund, manual) memang tidak punya SPK dan tidak muncul
     * di peta ini — layar menampilkannya sebagai kosong, bukan menebak.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Domain\BusinessSupport\Models\BsApKuitansi>  $kuitansi
     * @return array<int, string>
     */
    private function spkPerInvoice($kuitansi): array
    {
        $invoice = $kuitansi->flatMap(fn ($k) => $k->lines->pluck('invoice'))
            ->filter(fn ($i) => $i !== null && $i->source_type === 'spk' && $i->source_id !== null);

        if ($invoice->isEmpty()) {
            return [];
        }

        $spkPerProgress = \App\Domain\BusinessSupport\Models\BsWorkOrderProgress::query()
            ->join('bs_work_orders', 'bs_work_orders.id', '=', 'bs_work_order_progress.work_order_id')
            ->whereIn('bs_work_order_progress.id', $invoice->pluck('source_id')->unique())
            ->pluck('bs_work_orders.spk_number', 'bs_work_order_progress.id');

        return $invoice->mapWithKeys(fn ($i) => [
            (int) $i->id => $spkPerProgress[(int) $i->source_id] ?? null,
        ])->filter()->all();
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

    /** #13 — Setujui BEBERAPA pengeluaran sekaligus (jurnal diposting per transaksi). */
    public function approveBulk(Request $request, RecordCashTransaction $action): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $v = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:bs_cash_transactions,id'],
        ]);
        $ok = 0;
        $skip = 0;
        $fail = [];
        foreach (array_unique($v['ids']) as $id) {
            $t = BsCashTransaction::find($id);
            if (! $t || $t->trx_type !== 'out' || $t->approval_status !== 'pending') {
                $skip++;
                continue;
            }
            $t->update(['approval_status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
            try {
                $action->postJournal($t, $request->user()->id);
                $ok++;
            } catch (BusinessRuleException $e) {
                $fail[] = $t->trx_number;
            }
        }
        $msg = "$ok pengeluaran disetujui.".($skip ? " $skip dilewati (bukan pending)." : '')
            .($fail ? ' Gagal posting jurnal: '.implode(', ', array_slice($fail, 0, 8)).'.' : '');

        return back()->with($ok ? 'success' : ($fail ? 'error' : 'warning'), $msg);
    }

    /** #13 — Tolak BEBERAPA pengeluaran sekaligus. */
    public function rejectBulk(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $v = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:bs_cash_transactions,id'],
        ]);
        $ok = 0;
        $skip = 0;
        foreach (array_unique($v['ids']) as $id) {
            $t = BsCashTransaction::find($id);
            if (! $t || $t->trx_type !== 'out' || $t->approval_status !== 'pending') {
                $skip++;
                continue;
            }
            $t->update(['approval_status' => 'rejected', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
            $ok++;
        }

        return back()->with($ok ? 'success' : 'warning', "$ok pengeluaran ditolak.".($skip ? " $skip dilewati." : ''));
    }

    /** #129: Download mutasi rekening (mengikuti filter layar) ke Excel (.xls). */
    public function export(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('cashbook.transaction.export'), 403);

        $accId = $request->integer('account_id') ?: null;
        $ids = $this->selectedIds($request);
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
                ->get(['bs_journal_lines.id', 'bs_journal_lines.debit', 'bs_journal_lines.credit', 'bs_journal_lines.description as line_description',
                    'j.journal_number', 'j.reference', 'j.source', 'j.journal_date', 'j.description as journal_description']);

            $h = '<table border="1"><tr><th colspan="7" style="background:#0f766e;color:#fff">Mutasi Rekening: '.$e($acc?->code).' — '.$e($acc?->name).($ids ? ' (baris terpilih)' : '').'</th></tr>'
                .'<tr style="background:#e2e8f0"><th>No.</th><th>Tanggal</th><th>Sumber</th><th>Keterangan</th><th>Masuk</th><th>Keluar</th><th>Saldo</th></tr>';
            $bal = $opening;
            foreach ($rows as $r) {
                // Saldo diakumulasi dari SELURUH mutasi terfilter; centang hanya menyaring baris yang
                // DICETAK. Kolom Saldo baris terpilih karena itu tetap sama persis dengan di layar.
                $bal += (float) $r->debit - (float) $r->credit;
                if ($ids && ! in_array((int) $r->id, $ids, true)) {
                    continue;
                }
                $h .= '<tr><td>'.$e($r->reference ?: $r->journal_number).'</td><td>'.$e(optional($r->journal_date)->toDateString())
                    .'</td><td>'.$e(strtoupper((string) $r->source)).'</td><td>'.$e($r->line_description ?: $r->journal_description)
                    .'</td><td>'.$rp($r->debit).'</td><td>'.$rp($r->credit).'</td><td>'.$rp($bal).'</td></tr>';
            }
            $h .= '</table>';
            $file = 'Mutasi-'.($acc?->code ?? 'rekening').($ids ? '-terpilih' : '').'-'.now()->format('Ymd-His').'.xls';
        } else {
            // Register "Semua Rekening" (BsCashTransaction).
            $rows = BsCashTransaction::query()
                ->with(['cashAccount:id,code,name', 'counterAccount:id,code,name'])
                ->when($ids, fn ($q) => $q->whereIn('id', $ids))
                ->when($request->input('type'), fn ($q, $v) => $q->where('trx_type', $v))
                ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('trx_number', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")->orWhere('payee', 'like', "%{$s}%")))
                ->when($request->input('from'), fn ($q, $v) => $q->whereDate('trx_date', '>=', $v))
                ->when($request->input('to'), fn ($q, $v) => $q->whereDate('trx_date', '<=', $v))
                ->latest('trx_date')->latest('id')->get();
            $isTransferView = $request->input('type') === 'transfer';
            // Pelunasan hutang (AP) ikut, sama seperti di layar — kecuali saat mencetak baris
            // tercentang (centang hanya bisa mengenai transaksi kas).
            if (! $isTransferView && ! $ids) {
                $rows = $rows->concat($this->apPaymentRows($request));
            }
            $h = '<table border="1"><tr style="background:#0f766e;color:#fff"><th>No.</th><th>Tanggal</th><th>Jenis</th><th>Rekening</th><th>Keterangan/Pihak</th><th>Masuk</th><th>Keluar</th></tr>';
            foreach ($rows as $r) {
                $jenis = BsCashTransaction::TYPES[$r->trx_type] ?? $r->trx_type;
                $no    = $e($r->trx_number);
                $tgl   = $e(optional($r->trx_date)->toDateString());
                if ($isTransferView) {
                    // #7: dua sisi transfer (keluar pengirim + masuk penerima) konsisten dengan layar.
                    $amt    = (float) $r->amount;
                    $sender = $r->cashAccount?->name;
                    $recv   = $r->counterAccount?->name;
                    $h .= '<tr><td>'.$no.'</td><td>'.$tgl.'</td><td>'.$e($jenis).'</td><td>'.$e($sender)
                        .'</td><td>'.$e(trim((string) $r->description.' → ke '.($recv ?? '—'))).'</td><td>'.$rp(0).'</td><td>'.$rp($amt).'</td></tr>';
                    $h .= '<tr><td>'.$no.'</td><td>'.$tgl.'</td><td>'.$e($jenis).'</td><td>'.$e($recv)
                        .'</td><td>'.$e(trim((string) $r->description.' ← dari '.($sender ?? '—'))).'</td><td>'.$rp($amt).'</td><td>'.$rp(0).'</td></tr>';
                } else {
                    // Baris pelunasan AP sintetis membawa masuk/keluar & rekening_label sendiri
                    // (tanpa kolom amount / relasi cashAccount) — pakai itu bila ada.
                    // getAttributes() = array mentah; getAttribute() akan MELEMPAR untuk atribut
                    // yang tidak ada karena Model::shouldBeStrict() aktif di luar produksi.
                    $raw    = $r->getAttributes();
                    $isAp   = (bool) ($raw['is_ap_payment'] ?? false);
                    $masuk  = $isAp ? 0 : ($r->trx_type === 'in' ? (float) $r->amount : 0);
                    $keluar = $isAp ? (float) ($raw['keluar'] ?? 0) : (in_array($r->trx_type, ['out', 'transfer'], true) ? (float) $r->amount : 0);
                    $jenis  = $isAp ? 'Pelunasan Hutang (AP)' : $jenis;
                    $rek    = $isAp ? (string) ($raw['rekening_label'] ?? '') : (string) $r->cashAccount?->name;
                    $h .= '<tr><td>'.$no.'</td><td>'.$tgl.'</td><td>'.$e($jenis)
                        .'</td><td>'.$e($rek).'</td><td>'.$e($r->description).($r->payee ? ' · '.$e($r->payee) : '').'</td><td>'.$rp($masuk).'</td><td>'.$rp($keluar).'</td></tr>';
                }
            }
            $h .= '</table>';
            $file = 'Mutasi-semua-rekening'.($ids ? '-terpilih' : '').'-'.now()->format('Ymd-His').'.xls';
        }

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'.$h.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /**
     * Baris yang dicentang di layar Cash Book (?ids=1,2,3). Kosong = "semua sesuai filter" (perilaku lama).
     *
     * Makna id mengikuti mode layar: tanpa account_id → id BsCashTransaction; dengan account_id
     * (mode GL) → id BsJournalLine. Karena itu layar WAJIB mengosongkan centang saat filter berubah.
     */
    private function selectedIds(Request $request): array
    {
        $raw = $request->input('ids');
        $ids = is_array($raw) ? $raw : array_filter(explode(',', (string) $raw), fn ($v) => trim($v) !== '');

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** Bungkus respons Excel (.xls) — pola HTML table ms-excel (#129). */
    private function xlsResponse(string $bodyHtml, string $file): \Illuminate\Http\Response
    {
        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'.$bodyHtml.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /** Bungkus halaman PDF (HTML A4 auto-print) — pola reportPdf() CashAdvance. */
    private function pdfPage(string $title, string $subtitle, string $bodyHtml): \Illuminate\Http\Response
    {
        $e   = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $co  = (string) config('business_support.company.name', 'PT. MITRA USAHA PROPERTINDO');
        $now = now()->translatedFormat('d F Y H:i');

        $html = '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>'.$e($title).'</title><style>
            @page{size:A4;margin:14mm}*{box-sizing:border-box}body{font-family:"Segoe UI",Arial,sans-serif;font-size:11px;color:#1f2937;margin:0}
            h1{font-size:16px;margin:0 0 2px;color:#0f766e}.muted{color:#6b7280;font-weight:normal;font-size:10px}
            .head{border-bottom:2px solid #0f766e;padding-bottom:6px;margin-bottom:10px;display:flex;justify-content:space-between}
            table{border-collapse:collapse;width:100%;margin:4px 0;font-size:10px}th,td{border:1px solid #cbd5e1;padding:4px 6px;text-align:left}
            thead th{background:#f1f5f9}.r{text-align:right;font-variant-numeric:tabular-nums}.c{text-align:center}tfoot td{font-weight:bold;background:#f8fafc}
            @media print{.noprint{display:none}}</style></head><body>
            <div class="head"><div><h1>'.$e($title).'</h1><div class="muted">'.$e($co).($subtitle !== '' ? ' · '.$e($subtitle) : '').'</div></div><div class="muted" style="text-align:right">Cetak: '.$e($now).'</div></div>'
            .$bodyHtml.
            '<button class="noprint" onclick="window.print()" style="margin-top:12px;padding:6px 14px">Cetak / Simpan PDF</button>
            <script>window.onload=function(){setTimeout(function(){window.print()},300)}</script></body></html>';

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** #129/#9: PDF pasangan dari export() — mutasi rekening (1 rekening / Semua Rekening) siap-cetak. */
    public function exportPdf(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('cashbook.transaction.export'), 403);

        $accId = $request->integer('account_id') ?: null;
        $ids = $this->selectedIds($request);
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
                ->get(['bs_journal_lines.id', 'bs_journal_lines.debit', 'bs_journal_lines.credit', 'bs_journal_lines.description as line_description',
                    'j.journal_number', 'j.reference', 'j.source', 'j.journal_date', 'j.description as journal_description']);

            $body = '<table><thead><tr><th>No.</th><th>Tanggal</th><th>Sumber</th><th>Keterangan</th><th class="r">Masuk</th><th class="r">Keluar</th><th class="r">Saldo</th></tr></thead><tbody>';
            $bal = $opening;
            $shown = 0;
            foreach ($rows as $r) {
                // Saldo diakumulasi dari SELURUH mutasi terfilter (lihat export()); centang hanya
                // menyaring baris yang dicetak agar kolom Saldo tetap cocok dengan layar.
                $bal += (float) $r->debit - (float) $r->credit;
                if ($ids && ! in_array((int) $r->id, $ids, true)) {
                    continue;
                }
                $shown++;
                $body .= '<tr><td>'.$e($r->reference ?: $r->journal_number).'</td><td>'.$e(optional($r->journal_date)->toDateString())
                    .'</td><td>'.$e(strtoupper((string) $r->source)).'</td><td>'.$e($r->line_description ?: $r->journal_description)
                    .'</td><td class="r">'.$rp($r->debit).'</td><td class="r">'.$rp($r->credit).'</td><td class="r">'.$rp($bal).'</td></tr>';
            }
            if ($shown === 0) $body .= '<tr><td colspan="7" class="c muted">Tidak ada mutasi.</td></tr>';
            $body .= '</tbody></table>';

            return $this->pdfPage('MUTASI REKENING', trim(trim((string) $acc?->code.' — '.(string) $acc?->name, ' —').($ids ? ' · '.$shown.' baris terpilih' : '')), $body);
        }

        // Register "Semua Rekening" (BsCashTransaction). #7: dua sisi bila type=transfer.
        $rows = BsCashTransaction::query()
            ->with(['cashAccount:id,code,name', 'counterAccount:id,code,name'])
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->when($request->input('type'), fn ($q, $v) => $q->where('trx_type', $v))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('trx_number', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")->orWhere('payee', 'like', "%{$s}%")))
            ->when($request->input('from'), fn ($q, $v) => $q->whereDate('trx_date', '>=', $v))
            ->when($request->input('to'), fn ($q, $v) => $q->whereDate('trx_date', '<=', $v))
            ->latest('trx_date')->latest('id')->get();
        $isTransferView = $request->input('type') === 'transfer';
        // Pelunasan hutang (AP) ikut, sama seperti di layar (lihat indexAllAccounts).
        if (! $isTransferView && ! $ids) {
            $rows = $rows->concat($this->apPaymentRows($request));
        }

        $body = '<table><thead><tr><th>No.</th><th>Tanggal</th><th>Jenis</th><th>Rekening</th><th>Keterangan/Pihak</th><th class="r">Masuk</th><th class="r">Keluar</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $jenis = BsCashTransaction::TYPES[$r->trx_type] ?? $r->trx_type;
            $no    = $e($r->trx_number);
            $tgl   = $e(optional($r->trx_date)->toDateString());
            if ($isTransferView) {
                $amt    = (float) $r->amount;
                $sender = $r->cashAccount?->name;
                $recv   = $r->counterAccount?->name;
                $body .= '<tr><td>'.$no.'</td><td>'.$tgl.'</td><td>'.$e($jenis).'</td><td>'.$e($sender)
                    .'</td><td>'.$e(trim((string) $r->description.' → ke '.($recv ?? '—'))).'</td><td class="r">'.$rp(0).'</td><td class="r">'.$rp($amt).'</td></tr>';
                $body .= '<tr><td>'.$no.'</td><td>'.$tgl.'</td><td>'.$e($jenis).'</td><td>'.$e($recv)
                    .'</td><td>'.$e(trim((string) $r->description.' ← dari '.($sender ?? '—'))).'</td><td class="r">'.$rp($amt).'</td><td class="r">'.$rp(0).'</td></tr>';
            } else {
                // Baris pelunasan AP sintetis membawa masuk/keluar & rekening_label sendiri.
                // getAttributes() = array mentah; getAttribute() akan MELEMPAR untuk atribut
                // yang tidak ada karena Model::shouldBeStrict() aktif di luar produksi.
                $raw    = $r->getAttributes();
                $isAp   = (bool) ($raw['is_ap_payment'] ?? false);
                $masuk  = $isAp ? 0 : ($r->trx_type === 'in' ? (float) $r->amount : 0);
                $keluar = $isAp ? (float) ($raw['keluar'] ?? 0) : (in_array($r->trx_type, ['out', 'transfer'], true) ? (float) $r->amount : 0);
                $jenis  = $isAp ? 'Pelunasan Hutang (AP)' : $jenis;
                $rek    = $isAp ? (string) ($raw['rekening_label'] ?? '') : (string) $r->cashAccount?->name;
                $body .= '<tr><td>'.$no.'</td><td>'.$tgl.'</td><td>'.$e($jenis)
                    .'</td><td>'.$e($rek).'</td><td>'.$e($r->description).($r->payee ? ' · '.$e($r->payee) : '').'</td><td class="r">'.$rp($masuk).'</td><td class="r">'.$rp($keluar).'</td></tr>';
            }
        }
        if ($rows->isEmpty()) $body .= '<tr><td colspan="7" class="c muted">Tidak ada transaksi.</td></tr>';
        $body .= '</tbody></table>';

        return $this->pdfPage('MUTASI SEMUA REKENING', $ids ? $rows->count().' transaksi terpilih' : '', $body);
    }

    /** #9: query mutasi 1 rekening (basis GL, saldo berjalan) untuk Excel/PDF Ledger — reuse logika ledger(). */
    private function ledgerExport(Request $request): array
    {
        $accountId = $request->integer('account_id') ?: null;
        $from = $request->input('from');
        $to   = $request->input('to');
        $acc  = $accountId ? BsAccount::find($accountId, ['id', 'code', 'name']) : null;
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
                    'desc' => $r->description, 'debit' => (float) $r->debit, 'credit' => (float) $r->credit, 'balance' => round($running, 2),
                ];
            });
        }

        return [
            'acc' => $acc, 'entries' => $entries,
            'opening' => round($opening, 2),
            'closing' => $entries->isNotEmpty() ? $entries->last()['balance'] : round($opening, 2),
        ];
    }

    /** #9: Rekening Ledger → Excel (.xls). */
    public function ledgerExcel(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('cashbook.ledger.view'), 403);

        $d  = $this->ledgerExport($request);
        $e  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');

        $h = '<table border="1"><tr><th colspan="6" style="background:#0f766e;color:#fff">Rekening Ledger: '.$e($d['acc']?->code).' — '.$e($d['acc']?->name).'</th></tr>'
            .'<tr style="background:#e2e8f0"><th>Tanggal</th><th>Referensi</th><th>Keterangan</th><th>Debit</th><th>Kredit</th><th>Saldo</th></tr>'
            .'<tr><td colspan="5">Saldo Awal</td><td>'.$rp($d['opening']).'</td></tr>';
        foreach ($d['entries'] as $en) {
            $h .= '<tr><td>'.$e($en['date']).'</td><td>'.$e($en['ref']).'</td><td>'.$e($en['desc'])
                .'</td><td>'.$rp($en['debit']).'</td><td>'.$rp($en['credit']).'</td><td>'.$rp($en['balance']).'</td></tr>';
        }
        $h .= '<tr style="background:#e2e8f0"><td colspan="5"><b>Saldo Akhir</b></td><td><b>'.$rp($d['closing']).'</b></td></tr></table>';

        return $this->xlsResponse($h, 'Rekening-Ledger-'.($d['acc']?->code ?? 'rekening').'-'.now()->format('Ymd-His').'.xls');
    }

    /** #9: Rekening Ledger → PDF (HTML A4 auto-print). */
    public function ledgerPdf(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('cashbook.ledger.view'), 403);

        $d  = $this->ledgerExport($request);
        $e  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');

        $body = '<table><thead><tr><th>Tanggal</th><th>Referensi</th><th>Keterangan</th><th class="r">Debit</th><th class="r">Kredit</th><th class="r">Saldo</th></tr></thead><tbody>'
            .'<tr><td colspan="5">Saldo Awal</td><td class="r">'.$rp($d['opening']).'</td></tr>';
        foreach ($d['entries'] as $en) {
            $body .= '<tr><td>'.$e($en['date']).'</td><td>'.$e($en['ref']).'</td><td>'.$e($en['desc'])
                .'</td><td class="r">'.($en['debit'] ? $rp($en['debit']) : '').'</td><td class="r">'.($en['credit'] ? $rp($en['credit']) : '').'</td><td class="r">'.$rp($en['balance']).'</td></tr>';
        }
        $body .= '</tbody><tfoot><tr><td colspan="5">Saldo Akhir</td><td class="r">'.$rp($d['closing']).'</td></tr></tfoot></table>';

        return $this->pdfPage('REKENING LEDGER', trim((string) $d['acc']?->code.' — '.(string) $d['acc']?->name, ' —'), $body);
    }

    /** #9: data Saldo Rekening (per tanggal) untuk Excel/PDF — reuse logika balances(). */
    private function balancesExport(Request $request): array
    {
        $asOf = rescue(fn () => $request->date('as_of')?->toDateString(), null, false) ?? now()->toDateString();

        $cash = $this->cashAccounts();
        $net = BsJournalLine::query()
            ->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            ->where('bs_journals.status', 'posted')->whereNull('bs_journals.deleted_at')
            ->whereDate('bs_journals.journal_date', '<=', $asOf)
            ->whereIn('bs_journal_lines.account_id', $cash->pluck('id'))
            ->selectRaw('bs_journal_lines.account_id, COALESCE(SUM(debit-credit),0) as bal')
            ->groupBy('bs_journal_lines.account_id')
            ->pluck('bal', 'account_id');

        $rows = $cash->map(fn ($a) => ['code' => $a->code, 'name' => $a->name, 'balance' => (float) ($net[$a->id] ?? 0)]);

        return ['asOf' => $asOf, 'rows' => $rows, 'total' => (float) $rows->sum('balance')];
    }

    /** #9: Saldo Rekening → Excel (.xls). */
    public function balancesExcel(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('cashbook.saldo.view'), 403);

        $d  = $this->balancesExport($request);
        $e  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');

        $h = '<table border="1"><tr><th colspan="3" style="background:#0f766e;color:#fff">Saldo Rekening s/d '.$e($d['asOf']).'</th></tr>'
            .'<tr style="background:#e2e8f0"><th>Kode</th><th>Rekening</th><th>Saldo</th></tr>';
        foreach ($d['rows'] as $r) {
            $h .= '<tr><td>'.$e($r['code']).'</td><td>'.$e($r['name']).'</td><td>'.$rp($r['balance']).'</td></tr>';
        }
        $h .= '<tr style="background:#e2e8f0"><td colspan="2"><b>Total Kas &amp; Bank</b></td><td><b>'.$rp($d['total']).'</b></td></tr></table>';

        return $this->xlsResponse($h, 'Saldo-Rekening-'.now()->format('Ymd-His').'.xls');
    }

    /** #9: Saldo Rekening → PDF (HTML A4 auto-print). */
    public function balancesPdf(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('cashbook.saldo.view'), 403);

        $d  = $this->balancesExport($request);
        $e  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');

        $body = '<table><thead><tr><th>Kode</th><th>Rekening</th><th class="r">Saldo</th></tr></thead><tbody>';
        foreach ($d['rows'] as $r) {
            $body .= '<tr><td>'.$e($r['code']).'</td><td>'.$e($r['name']).'</td><td class="r">'.$rp($r['balance']).'</td></tr>';
        }
        if ($d['rows']->isEmpty()) $body .= '<tr><td colspan="3" class="c muted">Belum ada rekening.</td></tr>';
        $body .= '</tbody><tfoot><tr><td colspan="2">Total Kas &amp; Bank</td><td class="r">'.$rp($d['total']).'</td></tr></tfoot></table>';

        return $this->pdfPage('SALDO REKENING', 'Saldo s/d '.$d['asOf'], $body);
    }

    /** #9: data Kalender Cek Giro (per bulan) untuk Excel/PDF — reuse logika giroCalendar(). */
    private function giroCalendarExport(Request $request): array
    {
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

        return ['month' => $month, 'items' => $items, 'total' => round((float) $items->sum('amount'), 2)];
    }

    /** #9: Kalender Cek Giro → Excel (.xls). */
    public function giroCalendarExcel(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('cashbook.giro-calendar.view'), 403);

        $d  = $this->giroCalendarExport($request);
        $e  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');

        $h = '<table border="1"><tr><th colspan="6" style="background:#0f766e;color:#fff">Kalender Cek Giro — '.$e($d['month']).'</th></tr>'
            .'<tr style="background:#e2e8f0"><th>Tanggal</th><th>Arah</th><th>No.</th><th>Pihak</th><th>Status</th><th>Nominal</th></tr>';
        foreach ($d['items'] as $it) {
            $h .= '<tr><td>'.$e($it['date']).'</td><td>'.$e($it['arah']).'</td><td>'.$e($it['no']).'</td><td>'.$e($it['pihak'])
                .'</td><td>'.$e($it['status']).'</td><td>'.$rp($it['amount']).'</td></tr>';
        }
        $h .= '<tr style="background:#e2e8f0"><td colspan="5"><b>Total</b></td><td><b>'.$rp($d['total']).'</b></td></tr></table>';

        return $this->xlsResponse($h, 'Kalender-Cek-Giro-'.$d['month'].'-'.now()->format('Ymd-His').'.xls');
    }

    /** #9: Kalender Cek Giro → PDF (HTML A4 auto-print). */
    public function giroCalendarPdf(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('cashbook.giro-calendar.view'), 403);

        $d  = $this->giroCalendarExport($request);
        $e  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');

        $body = '<table><thead><tr><th>Tanggal</th><th>Arah</th><th>No.</th><th>Pihak</th><th>Status</th><th class="r">Nominal</th></tr></thead><tbody>';
        foreach ($d['items'] as $it) {
            $body .= '<tr><td>'.$e($it['date']).'</td><td>'.$e($it['arah']).'</td><td>'.$e($it['no']).'</td><td>'.$e($it['pihak'])
                .'</td><td>'.$e($it['status']).'</td><td class="r">'.$rp($it['amount']).'</td></tr>';
        }
        if ($d['items']->isEmpty()) $body .= '<tr><td colspan="6" class="c muted">Tidak ada cek/giro jatuh tempo.</td></tr>';
        $body .= '</tbody><tfoot><tr><td colspan="5">Total</td><td class="r">'.$rp($d['total']).'</td></tr></tfoot></table>';

        return $this->pdfPage('KALENDER CEK GIRO', 'Bulan '.$d['month'], $body);
    }

    /** #130: Cetak bukti transaksi kas (BKM/BKK/Transfer) — auto-print, layout eProperty + logo. */
    public function print(Request $request, BsCashTransaction $cashTransaction): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('cashbook.transaction.print'), 403);

        $cashTransaction->load(['cashAccount:id,code,name', 'counterAccount:id,code,name', 'lines.account:id,code,name']);
        $createdBy = $cashTransaction->created_by ? \App\Models\User::find($cashTransaction->created_by)?->name : null;

        return response($this->cashPrintDoc(
            (string) $cashTransaction->trx_number,
            $this->cashPrintSheet($cashTransaction, $request->user()?->name, $createdBy),
        ))->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Cetak bukti MASSAL (checkbox di layar Cash Book) — 1 dokumen, 1 bukti per halaman.
     * Format tiap lembar IDENTIK dengan cetak satuan karena memakai cashPrintSheet() yang sama.
     */
    public function printBulk(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('cashbook.transaction.print'), 403);

        $ids = $this->selectedIds($request);
        abort_if($ids === [], 400, 'Belum ada transaksi yang dicentang.');

        $rows = BsCashTransaction::query()
            ->whereIn('id', $ids)
            ->with(['cashAccount:id,code,name', 'counterAccount:id,code,name', 'lines.account:id,code,name'])
            ->orderBy('trx_date')->orderBy('id')
            ->get();
        abort_if($rows->isEmpty(), 404, 'Transaksi tidak ditemukan.');

        // Nama pembuat di-preload sekali (hindari N+1 saat mencetak puluhan bukti).
        $names = \App\Models\User::whereIn('id', $rows->pluck('created_by')->filter()->unique()->all())->pluck('name', 'id');

        $inner = $rows
            ->map(fn (BsCashTransaction $t): string => $this->cashPrintSheet(
                $t,
                $request->user()?->name,
                $t->created_by ? ($names[$t->created_by] ?? null) : null,
            ))
            ->implode('');

        return response($this->cashPrintDoc('Bukti Kas/Bank ('.$rows->count().')', $inner))
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Bungkus dokumen bukti (CSS + auto-print). $inner = satu atau banyak <section class="sheet">. */
    private function cashPrintDoc(string $title, string $inner): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>'.$e($title).'</title>'
            .'<style>'.$this->cashPrintCss().'</style></head><body>'.$inner
            .'<button class="noprint" onclick="window.print()" style="margin-top:14px;padding:6px 14px">Cetak</button>'
            .'<script>window.onload=function(){setTimeout(function(){window.print()},350)}</script></body></html>';
    }

    /** Bukti Pengeluaran/Penerimaan Kas/Bank — layout mengikuti format eProperty (logo + grid + tanda tangan). */
    private function cashPrintSheet(BsCashTransaction $trx, ?string $printedBy = null, ?string $createdBy = null): string
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

        $sig = fn (string $role, string $name = '') => '<div class="sigcell"><div class="role">'.$role.'</div><div class="sigline">'.$name.'</div></div>';

        return '<section class="sheet">'
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
            .'</section>';
    }

    /** CSS bukti kas — dipakai bersama cetak satuan & massal. */
    private function cashPrintCss(): string
    {
        return '@page{size:A4;margin:10mm}*{box-sizing:border-box}'
            .'body{font-family:Arial,"Segoe UI",sans-serif;font-size:11px;color:#1f2937;margin:0}'
            .'table{border-collapse:collapse}'
            // Cetak massal: 1 bukti = 1 halaman; lembar terakhir tidak menyisakan halaman kosong.
            .'.sheet{page-break-after:always}.sheet:last-of-type{page-break-after:auto}'
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
            // nullable di sini, WAJIB-nya ditegakkan di RecordCashTransaction::normalizeLines().
            // Kewajibannya bergantung pada AKUN barisnya (akun bertanda cost_center_optional
            // memang tidak menanggung cost center), dan aturan validasi Laravel tidak bisa
            // melihat akun baris yang sama tanpa closure yang justru menyembunyikan aturannya
            // dari tempat jurnal benar-benar dibentuk.
            'lines.*.cost_center_id' => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            'lines.*.amount'     => ['required', 'numeric', 'min:0.01'],
            'lines.*.description'=> ['nullable', 'string', 'max:255'],
        ];
    }

    private function attributes(): array
    {
        return [
            'lines'              => 'rincian akun',
            'lines.*.account_id' => 'akun lawan',
            'lines.*.cost_center_id' => 'cost center',
            'lines.*.amount'     => 'nominal',
        ];
    }

    /**
     * Rekening kas/bank = akun postable di bawah KAS DAN SETARA KAS.
     * Mencakup 10101 (kas, bank, giro) DAN 10108 (kas & setara kas yang dibatasi /
     * dana escrow bank) — agar seluruh akun kas/bank tampil & sinkron dengan Buku Besar GL.
     */
    private function cashAccounts()
    {
        return BsAccount::where('is_active', true)->where('is_postable', true)
            ->where(fn ($q) => $q->where('code', 'like', '10101%')->orWhere('code', 'like', '10108%'))
            ->orderBy('code')->get(['id', 'code', 'name']);
    }
}
