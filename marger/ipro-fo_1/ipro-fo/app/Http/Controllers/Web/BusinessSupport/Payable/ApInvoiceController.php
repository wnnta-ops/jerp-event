<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Payable;

use App\Domain\BusinessSupport\Actions\PayApInvoice;
use App\Domain\BusinessSupport\Actions\RegisterApInvoice;
use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApCategory;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsApPayment;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsDepartment;
use App\Domain\BusinessSupport\Models\BsLedger;
use App\Domain\BusinessSupport\Models\BsLocation;
use App\Domain\BusinessSupport\Models\BsSip;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Domain\Master\Unit\Models\Unit;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ApInvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        // Semua invoice dikirim ke klien; filter per-kolom & ringkasan dihitung di sisi klien
        // agar kotak summary mengikuti hasil filter. Total & Sisa = nett (setelah PPN & PPh).
        $rows = BsApInvoice::query()
            ->with(['supplier:id,name', 'customer:id,full_name'])
            ->select(['id', 'invoice_number', 'invoice_date', 'receive_date', 'due_date', 'supplier_id', 'customer_id', 'source_type', 'refund_source', 'description', 'berkas_notes', 'total', 'net_payable', 'paid_amount', 'status'])
            ->latest('invoice_date')->latest('id')
            ->get()
            ->map(fn ($r) => [
                'id'             => $r->id,
                'invoice_number' => $r->invoice_number,
                'invoice_date'   => $r->invoice_date?->toDateString(),
                'receive_date'   => $r->receive_date?->toDateString(),
                'due_date'       => $r->due_date?->toDateString(),  // utk ringkasan jatuh tempo
                'supplier'       => $r->supplier ? ['name' => $r->supplier->name] : null,
                'customer'       => $r->customer ? ['name' => $r->customer->full_name] : null, // refund: lawan = customer
                'source_type'    => $r->source_type,    // po / spk / refund / null(manual)
                'refund_source'  => $r->refund_source,  // SPR / SPRS / TRANSFER_ANONIM
                'description'    => $r->description,
                'berkas_notes'   => $r->berkas_notes,
                'status'         => $r->status,
                'payable'        => round($r->payable_amount, 2),   // nett dibayar ke supplier (total − PPh dipotong)
                'sisa'           => round($r->outstanding, 2),       // nett − terbayar
            ]);

        return Inertia::render('BusinessSupport/Payable/Index', [
            'invoices' => $rows,
        ]);
    }

    /** Hub Account Payable — direktori sub-modul + ringkasan hutang. */
    public function hub(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        $openTotal = (float) BsApInvoice::where('status', 'open')->sum('total');
        $openPaid  = (float) BsApInvoice::where('status', 'open')->sum('paid_amount');
        $overdueQ  = BsApInvoice::where('status', 'open')->whereNotNull('due_date')->whereDate('due_date', '<', now()->toDateString());
        $dueSoonQ  = BsApInvoice::where('status', 'open')->whereNotNull('due_date')->whereDate('due_date', '<=', now()->addDays(30)->toDateString());

        return Inertia::render('BusinessSupport/Payable/Hub', [
            'summary' => [
                'outstanding' => round($openTotal - $openPaid, 2),
                'overdue'     => round((float) (clone $overdueQ)->sum('total') - (float) (clone $overdueQ)->sum('paid_amount'), 2),
                'due_soon'    => round((float) (clone $dueSoonQ)->sum('total') - (float) (clone $dueSoonQ)->sum('paid_amount'), 2),
                'count_open'  => BsApInvoice::where('status', 'open')->count(),
                'count_total' => BsApInvoice::count(),
                'suppliers'   => \App\Domain\BusinessSupport\Models\BsSupplier::count(),
            ],
        ]);
    }

    /** Print-Out — hub cetak laporan hutang. */
    public function printHub(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        return Inertia::render('BusinessSupport/Payable/PrintHub');
    }

    /** Landing per sub-modul AP (grup BO) — detail item muncul di halaman ini. */
    public function section(Request $request, string $section): Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);
        $titles = [
            'general'      => 'General Function',
            'setup'        => 'Setup',
            'master'       => 'Master',
            'query'        => 'Query',
            'generate'     => 'Generate Invoice',
            'penyesuaian'  => 'Penyesuaian Invoice',
            'posting-po'   => 'Posting Invoice PO',
            'posting-spk'  => 'Posting Invoice SPK',
            'saldoawal'    => 'Saldo Awal',
        ];
        abort_unless(isset($titles[$section]), 404);

        return Inertia::render('BusinessSupport/Payable/Section', [
            'section' => $section,
            'title'   => $titles[$section],
        ]);
    }

    /** Export daftar AP ke Excel (.xls) — mengikuti filter status/search. */
    public function export(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        $rows = BsApInvoice::query()
            ->with('supplier:id,code,name')
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('supplier_id'), fn ($q, $v) => $q->where('supplier_id', $v))   // #135
            ->when($request->input('date_from'), fn ($q, $v) => $q->whereDate('invoice_date', '>=', $v)) // periode laporan
            ->when($request->input('date_to'), fn ($q, $v) => $q->whereDate('invoice_date', '<=', $v))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('invoice_number', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")))
            ->latest('invoice_date')->latest('id')->get();

        $e  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
        $today = \Illuminate\Support\Carbon::today();
        $h  = '<table border="1"><tr style="background:#0f766e;color:#fff"><th>No Invoice</th><th>Tanggal</th><th>Tgl Diterima</th><th>Jatuh Tempo</th><th>Aging Hutang</th><th>Aging Jatuh Tempo</th><th>Supplier</th><th>Keterangan</th><th>Status</th><th>Total</th><th>Terbayar</th><th>Sisa</th></tr>';
        foreach ($rows as $r) {
            $sisa = (float) $r->total - (float) $r->paid_amount;
            // Aging Hutang = jumlah hari sejak tanggal diterima (receive_date) sampai hari ini (>=0).
            $agingHutang = $r->receive_date
                ? (string) (int) $r->receive_date->copy()->startOfDay()->diffInDays($today, false)
                : '-';
            // Aging Jatuh Tempo = hari lewat jatuh tempo = hari ini − due_date (positif = telat).
            $agingJt = $r->due_date
                ? (string) (int) $r->due_date->copy()->startOfDay()->diffInDays($today, false)
                : '-';
            $h .= '<tr><td>'.$e($r->invoice_number).'</td><td>'.$e($r->invoice_date?->toDateString()).'</td><td>'.$e($r->receive_date?->toDateString()).'</td><td>'.$e($r->due_date?->toDateString()).'</td><td>'.$e($agingHutang).'</td><td>'.$e($agingJt).'</td><td>'.$e($r->supplier?->code).' '.$e($r->supplier?->name).'</td><td>'.$e($r->description).'</td><td>'.$e($r->status).'</td><td>'.$rp($r->total).'</td><td>'.$rp($r->paid_amount).'</td><td>'.$rp($sisa).'</td></tr>';
        }
        $h .= '</table>';

        $file = 'Account-Payable-'.now()->format('Ymd-His').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'.$h.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /** Laporan Invoice Hutang — pilih periode tanggal & supplier dulu, tarik data (preview), lalu unduh .xls. */
    public function report(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        $dateFrom   = $request->input('date_from') ?: null;
        $dateTo     = $request->input('date_to') ?: null;
        $supplierId = $request->integer('supplier_id') ?: null;
        $pulled     = $request->boolean('pull'); // data hanya ditarik setelah user pilih periode & klik Tampilkan

        $rows = collect();
        if ($pulled) {
            $today = \Illuminate\Support\Carbon::today();
            $rows = BsApInvoice::with('supplier:id,code,name')
                ->when($dateFrom, fn ($q, $v) => $q->whereDate('invoice_date', '>=', $v))
                ->when($dateTo, fn ($q, $v) => $q->whereDate('invoice_date', '<=', $v))
                ->when($supplierId, fn ($q, $v) => $q->where('supplier_id', $v))
                ->latest('invoice_date')->latest('id')->get()
                ->map(fn ($r) => [
                    'invoice_no'   => $r->invoice_number,
                    'invoice_date' => $r->invoice_date?->toDateString(),
                    'receive_date' => $r->receive_date?->toDateString(),
                    'due_date'     => $r->due_date?->toDateString(),
                    'aging_hutang' => $r->receive_date ? (int) $r->receive_date->copy()->startOfDay()->diffInDays($today, false) : null,
                    'aging_jt'     => $r->due_date ? (int) $r->due_date->copy()->startOfDay()->diffInDays($today, false) : null,
                    'supplier'     => trim(($r->supplier?->code ?? '').' '.($r->supplier?->name ?? '')),
                    'description'  => $r->description,
                    'status'       => $r->status,
                    'total'        => round((float) $r->total, 2),
                    'paid'         => round((float) $r->paid_amount, 2),
                    'sisa'         => round((float) $r->total - (float) $r->paid_amount, 2),
                ])->values();
        }

        return Inertia::render('BusinessSupport/Payable/InvoiceReport', [
            'rows'      => $rows,
            'suppliers' => BsSupplier::orderBy('name')->get(['id', 'code', 'name']),
            'filters'   => ['date_from' => $dateFrom, 'date_to' => $dateTo, 'supplier_id' => $supplierId],
            'pulled'    => $pulled,
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        // Unit + customer (dari booking terbaru per unit) untuk tag invoice — cari cepat by kode unit / nama customer.
        $unitCustomer = DB::table('bookings')
            ->join('customers', 'bookings.customer_id', '=', 'customers.id')
            ->whereNull('bookings.deleted_at')
            ->orderByDesc('bookings.id')
            ->get(['bookings.unit_id', 'bookings.customer_id', 'customers.full_name'])
            ->unique('unit_id')->keyBy('unit_id');
        $units = Unit::orderBy('unit_code')->get(['id', 'unit_code'])->map(fn ($u) => [
            'id' => $u->id, 'code' => $u->unit_code, 'name' => $unitCustomer[$u->id]->full_name ?? null,
            'customer_id' => $unitCustomer[$u->id]->customer_id ?? null, 'customer_name' => $unitCustomer[$u->id]->full_name ?? null,
        ]);

        // #113: akun PPh + default akun per jenis PPh (dipetakan by kode COA) — auto-isi di form, tetap bisa diubah.
        $pphAccounts = BsAccount::where('is_active', true)->where('is_postable', true)
            ->where('code', 'like', '2010404%')->orderBy('code')->get(['id', 'code', 'name']);

        return Inertia::render('BusinessSupport/Payable/Form', [
            'suppliers'  => BsSupplier::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'category']),
            'units'      => $units,
            'categories' => BsApCategory::where('is_active', true)->with(['debitAccount:id,code,name', 'payableAccount:id,code,name'])->orderBy('name')->get()
                ->map(fn ($c) => [
                    'id' => $c->id, 'code' => $c->code, 'name' => $c->name,
                    'debit' => $c->debitAccount?->code.' - '.$c->debitAccount?->name,
                    'payable' => $c->payableAccount?->code.' - '.$c->payableAccount?->name,
                    'debit_id' => $c->debit_account_id, 'debit_code' => $c->debitAccount?->code, 'debit_name' => $c->debitAccount?->name,
                    'payable_id' => $c->payable_account_id, 'payable_code' => $c->payableAccount?->code, 'payable_name' => $c->payableAccount?->name,
                ]),
            'pphAccounts' => $pphAccounts,
            'pphDefaults' => [   // #113 jenis PPh → akun default (HUTANG PPh 21/23/4(2))
                'pph21'  => $pphAccounts->firstWhere('code', '20104040402')?->id,
                'pph23'  => $pphAccounts->firstWhere('code', '20104040403')?->id,
                'pph4_2' => $pphAccounts->firstWhere('code', '20104040401')?->id,
            ],
            'ppnAccount'  => BsAccount::where('is_active', true)->where('code', config('business_support.gl_accounts.ppn_in'))->first(['id', 'code', 'name']),
            // Debit "Biaya PPh" default utk PPh ditanggung (bisa diganti di struktur jurnal).
            'pphBorneExpense' => BsAccount::where('code', config('business_support.gl_accounts.pph_borne_expense'))->first(['id', 'code', 'name']),
            'accounts'    => BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name']),
            'costCenters' => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'departments' => BsDepartment::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'ledgers'     => BsLedger::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'locations'   => BsLocation::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'trxTypes'    => BsApInvoice::TRX_TYPES,
        ]);
    }

    public function store(Request $request, RegisterApInvoice $action): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        $v = $request->validate([
            'supplier_id'         => ['required', 'integer', 'exists:bs_suppliers,id'],
            'unit_id'             => ['nullable', 'integer', 'exists:units,id'],
            'customer_id'         => ['nullable', 'integer', 'exists:customers,id'],
            'category_id'         => ['required', 'integer', 'exists:bs_ap_categories,id'],
            'trx_type'            => ['required', 'in:normal,denda,saldo_awal'],
            'supplier_invoice_no' => ['required', 'string', 'max:80'],
            'manual_no'           => ['nullable', 'string', 'max:80'],
            'invoice_date'        => ['required', 'date'],
            'receive_date'        => ['nullable', 'date'],
            'due_date'            => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'term_days'           => ['required', 'integer', 'min:1', 'max:3650'], // wajib: jatuh tempo (hari)
            'description'         => ['required', 'string', 'max:255'],
            'transfer_detail'     => ['nullable', 'string', 'max:255'],
            'kurs'                => ['nullable', 'numeric', 'min:0'],
            'dpp'                 => ['required', 'numeric', 'min:0.01'],
            'ppn_mode'            => ['required', 'in:none,with'],
            'ppn_pct'             => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ppn_base'            => ['nullable', 'numeric', 'min:0'],          // #140 nilai objek PPN
            'pph_mode'            => ['required', 'in:none,withheld,borne'], // borne = PPh ditanggung (sebelumnya tertolak validasi)
            'pph_type'            => ['nullable', 'in:pph21,pph23,pph4_2'],   // #94
            'pph_pct'             => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pph_base'            => ['nullable', 'numeric', 'min:0'],         // #92 nilai objek PPh
            'pph_account_id'      => ['nullable', 'integer', 'exists:bs_accounts,id'],
            'lines'                  => ['nullable', 'array'],
            'lines.*.description'    => ['required_with:lines', 'string', 'max:200'],
            'lines.*.qty'            => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_price'     => ['nullable', 'numeric', 'min:0'],
            'lines.*.amount'         => ['required_with:lines', 'numeric', 'min:0'],
            'schedules'              => ['nullable', 'array'],
            'schedules.*.due_date'   => ['required_with:schedules', 'date'],
            'schedules.*.amount'     => ['required_with:schedules', 'numeric', 'min:0.01'],
            'journal_lines'               => ['nullable', 'array'],
            'journal_lines.*.account_id'     => ['nullable', 'integer', 'exists:bs_accounts,id'],
            'journal_lines.*.cost_center_id' => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            'journal_lines.*.department_id'  => ['nullable', 'integer', 'exists:bs_departments,id'],
            'journal_lines.*.ledger_id'      => ['nullable', 'integer', 'exists:bs_ledgers,id'],
            'journal_lines.*.location_id'    => ['nullable', 'integer', 'exists:bs_locations,id'],
            'journal_lines.*.currency'       => ['nullable', 'string', 'max:8'],
            'journal_lines.*.kurs'           => ['nullable', 'numeric', 'min:0'],
            'journal_lines.*.description'    => ['nullable', 'string', 'max:255'],
            'journal_lines.*.debit'       => ['nullable', 'numeric', 'min:0'],
            'journal_lines.*.credit'      => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $inv = $action->execute($v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        // Langsung tampilkan halaman cetak Memo Internal setelah simpan.
        // Inertia::location = full-page visit ke respons HTML cetak (otomatis memunculkan dialog print);
        // ini cara resmi mengarahkan request Inertia ke halaman non-Inertia, dan bebas popup-blocker.
        // Tombol "Kembali ke Invoice" ada di halaman memo untuk balik ke detail (status, Bayar Hutang, dll).
        return Inertia::location(route('bs.ap.memo', $inv->id));
    }

    public function show(Request $request, BsApInvoice $invoice): Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        $invoice->load([
            'supplier:id,code,name', 'category:id,code,name',
            'unit:id,unit_code', 'customer:id,full_name',
            'lines', 'schedules' => fn ($q) => $q->orderBy('installment_no'),
            'payments' => fn ($q) => $q->latest('payment_date')->latest('id'),
        ]);

        // Memo penghapusan hutang yang menyelesaikan (sebagian) invoice ini — bukan pembayaran tunai.
        $writeoffs = \App\Domain\BusinessSupport\Models\BsApMemorial::where('ap_invoice_id', $invoice->id)
            ->where('trx_type', 'penghapusan')
            ->with('journal:id,journal_number')
            ->orderBy('memorial_date')->orderBy('id')
            ->get(['id', 'memorial_number', 'memorial_date', 'amount', 'description', 'journal_id'])
            ->map(fn ($m) => [
                'memorial_number' => $m->memorial_number,
                'memorial_date'   => $m->memorial_date?->format('Y-m-d'),
                'amount'          => (float) $m->amount,
                'reason'          => $m->description, // alasan penghapusan hutang
                'journal_id'      => $m->journal_id,  // tautan ke jurnal General Ledger
                'journal_number'  => $m->journal?->journal_number,
            ]);

        // Struktur jurnal pendaftaran invoice (Dr Akun + PPN Masukan / Cr Hutang + PPh).
        // Ditarik dari journal_id invoice agar tampil di rincian AP.INV — penting untuk audit.
        $journal = $invoice->journal_id
            ? \App\Domain\BusinessSupport\Models\BsJournal::with(['lines' => fn ($q) => $q->orderBy('id'), 'lines.account:id,code,name'])->find($invoice->journal_id)
            : null;
        $journalData = null;
        if ($journal) {
            $ccCodes   = \Illuminate\Support\Facades\DB::table('bs_cost_centers')->pluck('code', 'id');
            $deptCodes = \Illuminate\Support\Facades\DB::table('bs_departments')->pluck('code', 'id');
            $journalData = [
                'id'     => $journal->id,
                'number' => $journal->journal_number,
                'date'   => $journal->journal_date ? \Illuminate\Support\Carbon::parse($journal->journal_date)->toDateString() : null,
                'lines'  => $journal->lines->map(function ($l) use ($ccCodes, $deptCodes) {
                    $dim = collect([$ccCodes[$l->cost_center_id] ?? null, $deptCodes[$l->department_id] ?? null])->filter()->implode(' · ');

                    return [
                        'account_code' => $l->account?->code,
                        'account_name' => $l->account?->name,
                        'dimensi'      => $dim !== '' ? $dim : null,
                        'description'  => $l->description,
                        'debit'        => (float) $l->debit,
                        'credit'       => (float) $l->credit,
                    ];
                })->values(),
                'total_debit'  => round((float) $journal->lines->sum('debit'), 2),
                'total_credit' => round((float) $journal->lines->sum('credit'), 2),
            ];
        }

        return Inertia::render('BusinessSupport/Payable/Show', [
            'invoice'       => array_merge($invoice->toArray(), ['payable_amount' => $invoice->payable_amount]),
            'journal'       => $journalData,
            'outstanding'   => (float) $invoice->outstanding,
            'cashAccounts'  => BsAccount::where('is_active', true)->where('is_postable', true)->where('code', 'like', '101010%')->orderBy('code')->get(['id', 'code', 'name']),
            'defaultCash'   => $this->accId('cash'),
            'writeoffs'     => $writeoffs,
            'writeoffTotal' => round((float) $writeoffs->sum('amount'), 2),
        ]);
    }

    /** Simpan / hapus catatan kelengkapan berkas (free-text) pada rincian AP.INV. */
    public function updateBerkasNotes(Request $request, BsApInvoice $invoice): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:5000']]);
        $invoice->update(['berkas_notes' => ($data['notes'] ?? '') !== '' ? $data['notes'] : null]);

        return back(303);
    }

    /** #51: Cetak bukti hutang supplier + riwayat pembayaran (auto-print). */
    public function print(Request $request, BsApInvoice $invoice): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        $invoice->load(['supplier:id,code,name', 'lines', 'payments' => fn ($q) => $q->orderBy('payment_date')->orderBy('id')]);
        $accNames = BsAccount::whereIn('id', $invoice->payments->pluck('cash_account_id')->filter()->unique())->pluck('name', 'id');

        return response($this->renderInvoicePrint($invoice, $accNames))->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function renderInvoicePrint(BsApInvoice $invoice, $accNames): string
    {
        $e   = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp  = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
        $co  = (string) config('business_support.company.name', 'PT. Mitra Usaha Propertindo');
        $now = now()->translatedFormat('d F Y H:i');

        $payable = (float) $invoice->payable_amount;
        $paid    = (float) $invoice->paid_amount;
        $sisa    = round($payable - $paid, 2);
        $lunas   = $sisa <= 0.01;

        // #115: rincian pajak (DPP, PPN, PPh dipungut) untuk print-out.
        $dpp        = (float) $invoice->dpp;
        $ppnAmt     = (float) $invoice->ppn_amount;
        $pphAmt     = (float) $invoice->pph_amount;
        $grossTotal = (float) $invoice->total > 0 ? (float) $invoice->total : $dpp + $ppnAmt; // DPP + PPN
        $pct        = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
        $pphLabels  = ['pph21' => 'PPh Pasal 21', 'pph23' => 'PPh Pasal 23', 'pph4_2' => 'PPh Pasal 4(2)'];
        $pphLabel   = $pphLabels[$invoice->pph_type] ?? 'PPh';

        $sumRows = '<tr><td>DPP</td><td class="r">'.$rp($dpp).'</td></tr>';
        if ($invoice->ppn_mode === 'with' || $ppnAmt > 0) {
            $sumRows .= '<tr><td>PPN '.$pct($invoice->ppn_pct).'%</td><td class="r">'.$rp($ppnAmt).'</td></tr>';
        }
        $sumRows .= '<tr><td>Total (DPP + PPN)</td><td class="r">'.$rp($grossTotal).'</td></tr>';
        if ($invoice->pph_mode === 'borne' && $pphAmt > 0) {
            // Ditanggung → PPh beban perusahaan, TIDAK dipotong dari supplier (net = total).
            $sumRows .= '<tr><td>'.$e($pphLabel).' '.$pct($invoice->pph_pct).'% (ditanggung)</td><td class="r">'.$rp($pphAmt).'</td></tr>';
        } elseif ($invoice->pph_mode === 'withheld' || $pphAmt > 0) {
            $sumRows .= '<tr><td>'.$e($pphLabel).' '.$pct($invoice->pph_pct).'% (dipotong)</td><td class="r">- '.$rp($pphAmt).'</td></tr>';
        }
        $sumRows .= '<tr class="net"><td>Nilai Hutang (Net)</td><td class="r">'.$rp($payable).'</td></tr>'
            .'<tr><td>Telah Dibayar</td><td class="r">'.$rp($paid).'</td></tr>'
            .'<tr class="tot"><td>Sisa</td><td class="r">'.$rp(max(0, $sisa)).'</td></tr>';

        $lineRows = '';
        foreach ($invoice->lines as $l) {
            $lineRows .= '<tr><td>'.$e($l->description).'</td><td class="r">'.$rp($l->amount ?? $l->total ?? 0).'</td></tr>';
        }
        if ($lineRows === '') {
            $lineRows = '<tr><td>'.$e($invoice->description).'</td><td class="r">'.$rp($payable).'</td></tr>';
        }

        $payRows = '';
        foreach ($invoice->payments as $p) {
            $payRows .= '<tr><td>'.$e(optional($p->payment_date)->translatedFormat('d/m/Y')).'</td><td>'
                .$e($accNames[$p->cash_account_id] ?? '—').'</td><td>'.$e($p->reference).'</td><td class="r">'.$rp($p->amount).'</td></tr>';
        }
        $payBlock = $payRows !== ''
            ? '<h3>Riwayat Pembayaran</h3><table><thead><tr><th>Tanggal</th><th>Dari Kas/Bank</th><th>Referensi</th><th class="r">Jumlah</th></tr></thead><tbody>'.$payRows.'</tbody></table>'
            : '<p class="muted">Belum ada pembayaran.</p>';

        $badge = $lunas
            ? '<span class="badge lunas">LUNAS</span>'
            : '<span class="badge sisa">SISA '.$rp($sisa).'</span>';

        return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>'.$e($invoice->invoice_number).'</title><style>
            @page{size:A4;margin:16mm}*{box-sizing:border-box}body{font-family:"Segoe UI",Arial,sans-serif;font-size:12px;color:#1f2937;margin:0}
            h1{font-size:17px;margin:0 0 2px;color:#b91c1c}h3{font-size:12px;margin:14px 0 4px;color:#991b1b}
            .head{border-bottom:2px solid #b91c1c;padding-bottom:8px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:flex-start}
            .muted{color:#6b7280;font-size:11px}table{border-collapse:collapse;width:100%;margin:4px 0;font-size:11px}
            th,td{border:1px solid #cbd5e1;padding:6px 8px;text-align:left}.r{text-align:right;font-variant-numeric:tabular-nums}
            dl{display:grid;grid-template-columns:130px 1fr;gap:3px 8px;margin:6px 0}dt{color:#6b7280}
            .sum{margin-top:8px;width:300px;margin-left:auto}.sum td{border:none;padding:2px 4px}.sum .tot{font-weight:bold;border-top:1px solid #cbd5e1}.sum .net{font-weight:600;border-top:1px solid #e5e7eb}
            .badge{display:inline-block;padding:2px 10px;border-radius:9px;font-size:11px;font-weight:bold}
            .lunas{background:#dcfce7;color:#166534}.sisa{background:#fef3c7;color:#92400e}
            .sign{margin-top:32px;display:flex;justify-content:space-between;font-size:11px;text-align:center}
            @media print{.noprint{display:none}}</style></head><body>
            <div class="head"><div><h1>BUKTI HUTANG SUPPLIER</h1><div class="muted">'.$e($co).'</div></div>
            <div class="muted" style="text-align:right">No: '.$e($invoice->invoice_number).'<br>Cetak: '.$e($now).'<br>'.$badge.'</div></div>
            <dl><dt>Supplier</dt><dd>'.$e($invoice->supplier?->name).'</dd>
            <dt>Tanggal Invoice</dt><dd>'.$e(optional($invoice->invoice_date)->translatedFormat('d F Y')).'</dd>
            <dt>Jatuh Tempo</dt><dd>'.$e(optional($invoice->due_date)->translatedFormat('d F Y')).'</dd>
            <dt>Keterangan</dt><dd>'.$e($invoice->description).'</dd></dl>
            <h3>Rincian</h3><table><thead><tr><th>Keterangan</th><th class="r">Jumlah</th></tr></thead><tbody>'.$lineRows.'</tbody></table>
            <table class="sum">'.$sumRows.'</table>
            '.$payBlock.'
            <div class="sign"><div>Dibuat,<br><br><br>(__________)</div><div>Diperiksa,<br><br><br>(__________)</div><div>Finance,<br><br><br>(__________)</div></div>
            <button class="noprint" onclick="window.print()" style="margin-top:14px;padding:6px 14px">Cetak</button>
            <script>window.onload=function(){setTimeout(function(){window.print()},300)}</script></body></html>';
    }

    /** Cetak "Memo Internal" + "Bukti Permohonan Pembayaran" (layout eProperty MI) di akhir registrasi. */
    public function memo(Request $request, BsApInvoice $invoice): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        $invoice->load([
            'supplier:id,code,name,bank_name,bank_account_no,bank_account_name',
            'category.payableAccount:id,code,name',
            'lines',
        ]);

        return response($this->renderMemoPrint($invoice, $request->user()))
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function renderMemoPrint(BsApInvoice $invoice, $user): string
    {
        $e   = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp  = fn ($n) => 'Rp. '.number_format((float) $n, 2, ',', '.');
        $pct = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');

        $dpp = (float) $invoice->dpp;
        $ppn = (float) $invoice->ppn_amount;
        $pph = (float) $invoice->pph_amount;
        $net = (float) $invoice->payable_amount;

        $sup     = $invoice->supplier;
        $payAcc  = $invoice->category?->payableAccount;
        $created = $e(now()->translatedFormat('d-m-Y H.i.s'));
        $eNo     = $e($invoice->invoice_number);
        $eTgl    = $e(optional($invoice->invoice_date)->translatedFormat('d F Y'));
        $eDesc   = $e($invoice->description);
        $eDari   = $e($user?->name ?? '-');
        $eSupNm  = $e($sup?->name ?? '-');
        $eBankNm = $e($sup?->bank_name ?: '-');
        $eBankRek= $e($sup?->bank_account_no ?: '-');
        $eBankAn = $e($sup?->bank_account_name ?: ($sup?->name ?: '-'));
        $eBankLbl= 'Bank'.($sup?->bank_name ? ' ('.$e($sup->bank_name).')' : '');
        $ePayAcc = $e($payAcc?->code ?: '-');
        $fDpp = $rp($dpp); $fPpn = $rp($ppn); $fPph = $rp($pph); $fNet = $rp($net);
        $ppnPct = $pct($invoice->ppn_pct);
        $eWords = $e(strtoupper(trim(preg_replace('/\s+/', ' ', terbilang((int) round($net))))).' RUPIAH');

        // Penyetuju Memo Internal AP (sesuai contoh): kiri Rizki Setyawardana, kanan Rachmat Suhendra.
        $eSign1 = $e('Rizki Setyawardana');
        $eSign2 = $e('Rachmat Suhendra');
        $eMeng1 = $e(BsSip::SIGNERS['moch_winarto'] ?? 'Moch Winarto');
        $eMeng2 = $e(BsSip::SIGNERS['boy_arifin'] ?? 'Boy Arifin');
        $logo   = '/images/mup-logo.jpeg'; // logo MUP (public/images) — dimuat sebelum window.onload print

        // Baris item: No | Nama Barang | Harga Sat | Jml Harga
        $itemRows = '';
        if ($invoice->lines->count()) {
            foreach ($invoice->lines as $i => $l) {
                $itemRows .= '<tr><td class="c">'.($i + 1).'</td><td>'.$e($l->description).'</td><td class="r">'.$rp($l->unit_price ?: $l->amount).'</td><td class="r">'.$rp($l->amount).'</td></tr>';
            }
        } else {
            $itemRows = '<tr><td class="c">1</td><td>'.$eDesc.'</td><td class="r">'.$fDpp.'</td><td class="r">'.$fDpp.'</td></tr>';
        }

        // #BPP — checkbox OTOMATIS dari data invoice (sisanya kosong utk dicentang manual).
        $hasRek      = trim((string) ($sup?->bank_account_no ?? '')) !== '';        // ada nomor rekening?
        $hasPph      = in_array($invoice->pph_mode, ['withheld', 'borne'], true);   // ada PPh dipungut/ditanggung?
        $cbKas       = $hasRek ? '&nbsp;' : 'X';   // Dibayar Via: KAS bila TANPA rekening
        $cbBank      = $hasRek ? 'X' : '&nbsp;';   // Dibayar Via: Bank/transfer bila ADA rekening
        $cbTransfer  = $hasRek ? 'X' : '&nbsp;';   // Cara bayar: Transfer bila ada rekening
        $cbTunai     = $hasRek ? '&nbsp;' : 'X';   // Cara bayar: Tunai bila KAS (tanpa rekening)
        $cbPekerjaan = $hasPph ? 'X' : '&nbsp;';   // Type: Pekerjaan bila ada PPh
        $cbMaterial  = $hasPph ? '&nbsp;' : 'X';   // Type: Material bila tanpa PPh
        $cbPnjLgsg   = 'X';                         // Lampiran: selalu silang Penunjukan Langsung
        $backUrl     = route('bs.ap.show', $invoice->id);

        // Departemen sesuai user login: User.employee_nip → BsEmployee.nik → BsHrDepartment.name.
        // Departemen = dari Employee yang tertaut ke user login (NIP = NIK, atau cocok nama).
        // Bila employee belum punya Departemen, pakai Jabatan (position) sebagai unit; selain itu '-'.
        $eDept = '-';
        $emp = null;
        if (! empty($user?->employee_nip)) {
            $emp = \App\Domain\BusinessSupport\Models\BsEmployee::with('department:id,name')
                ->where('nik', $user->employee_nip)->first();
        }
        if (! $emp && ! empty($user?->name)) {
            $emp = \App\Domain\BusinessSupport\Models\BsEmployee::with('department:id,name')
                ->where('name', $user->name)->first();
        }
        if ($emp) {
            $eDept = $e($emp->department?->name ?: ($emp->position ?: '-'));
        }

        // #BPP — tabel No. Account / Uraian / Jumlah mengikuti Struktur Jurnal:
        //   • semua akun jurnal KECUALI Utang Usaha Supplier (akun payable),
        //   • Debit bernilai PLUS, Kredit bernilai MINUS,
        //   • nilai Utang Usaha Supplier dijadikan TOTAL akhir (net dibayar ke supplier).
        $payableId = (int) ($invoice->payable_account_id ?? 0);
        $jlines = $invoice->journal_id
            ? \App\Domain\BusinessSupport\Models\BsJournalLine::with('account:id,code,name')
                ->where('journal_id', $invoice->journal_id)->orderBy('id')->get()
            : collect();
        $payableLine = $jlines->first(fn ($l) => (int) $l->account_id === $payableId);
        $totalPay    = $payableLine ? ((float) $payableLine->credit - (float) $payableLine->debit) : $net;
        // Nilai minus diberi tanda '-'; nilai positif (debit) tanpa tanda '+'.
        $signFmt     = fn (float $n) => ($n < 0 ? '- Rp. ' : 'Rp. ').number_format(abs($n), 2, ',', '.');

        $bppRows  = '';
        $bppCount = 0;
        foreach ($jlines as $l) {
            if ((int) $l->account_id === $payableId) {
                continue; // Utang Usaha Supplier → masuk ke baris Total
            }
            $amt = (float) $l->debit > 0 ? (float) $l->debit : -(float) $l->credit; // Debit + , Kredit −
            $bppRows .= '<tr><td>'.$e($l->account?->code ?: '-').'</td><td>'.$e($l->description ?: $invoice->description).'</td><td class="r">'.$signFmt($amt).'</td></tr>';
            $bppCount++;
        }
        if ($bppCount === 0) { // fallback data lama tanpa jurnal: baris hutang tunggal.
            $bppRows = '<tr><td>'.$ePayAcc.'</td><td>'.$eDesc.'</td><td class="r">'.$rp($net).'</td></tr>';
            $bppCount = 1;
            $totalPay = $net;
        }
        for ($k = $bppCount; $k < 6; $k++) { // padding baris kosong agar tabel rapi
            $bppRows .= '<tr class="empty"><td>&nbsp;</td><td></td><td></td></tr>';
        }
        $fTotalPay = $rp($totalPay);

        return <<<HTML
<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Memo Internal {$eNo}</title><style>
@page{size:A4;margin:13mm}*{box-sizing:border-box}
body{font-family:"Times New Roman",Georgia,serif;font-size:12px;color:#111;margin:0;line-height:1.4}
.page{page-break-after:always}.page:last-of-type{page-break-after:auto}
.created{font-size:9px;color:#555;text-align:right;margin-bottom:2px}
.ttl{text-align:center;font-weight:bold;font-size:15px;text-decoration:underline;letter-spacing:.5px}
.sub{text-align:center;font-size:12px;margin:0 0 10px}
.meta{border-collapse:collapse;font-size:12px}.meta td{padding:1px 3px;vertical-align:top}
.meta .lbl{width:130px}.meta .sep{width:8px}
p{margin:7px 0;text-align:justify}
table.grid{width:100%;border-collapse:collapse;margin:5px 0;font-size:11px}
table.grid th,table.grid td{border:1px solid #333;padding:4px 6px;vertical-align:top}
table.grid th{background:#f0f0f0;text-align:center}
.r{text-align:right;font-variant-numeric:tabular-nums}.c{text-align:center}
table.sum{float:right;width:280px;border-collapse:collapse;margin:2px 0 6px;font-size:11px}
table.sum td{padding:2px 6px;border:1px solid #333}table.sum .tot{font-weight:bold;background:#f0f0f0}
.bx{display:inline-block;width:11px;height:11px;border:1px solid #333;text-align:center;line-height:11px;font-size:9px;font-weight:bold}
.signs{margin-top:26px}
.srow{display:flex;justify-content:space-between;margin-bottom:6px}
.srow.ctr{justify-content:center;gap:70px;margin-top:10px}
.scol{width:31%;text-align:center}.sp{height:88px}.nm{font-weight:bold}
.sign2 td{height:18px}.sign2 .sph td{height:55px}table.sign2{table-layout:fixed}
.logo{width:62px;height:62px;object-fit:contain}
.phead{width:100%;border-collapse:collapse;margin-bottom:4px}.phead td{vertical-align:middle;border:0;padding:0}
.empty td{height:15px}
@media print{.noprint{display:none}}
</style></head><body>

<div class="page">
  <div class="created">Dicetak: {$created}</div>
  <div style="text-align:center"><img class="logo" src="{$logo}" alt="MUP"></div>
  <div class="ttl">MEMO INTERNAL</div>
  <div class="sub">No : {$eNo}</div>
  <table class="meta">
    <tr><td class="lbl">Kepada Yth</td><td class="sep">:</td><td>RS</td></tr>
    <tr><td class="lbl">Tembusan</td><td class="sep">:</td><td>BA, MW</td></tr>
    <tr><td class="lbl">Dari</td><td class="sep">:</td><td>{$eDari}</td></tr>
    <tr><td class="lbl">Hari / Tanggal</td><td class="sep">:</td><td>{$eTgl}</td></tr>
    <tr><td class="lbl">Perihal</td><td class="sep">:</td><td>{$eDesc}</td></tr>
  </table>
  <p>Dengan hormat,<br>Sehubungan dengan keperluan {$eDesc}. Maka kami mengajukan permohonan pembayaran sebesar {$fNet} ({$eWords}). Dengan rincian sebagai berikut :</p>
  <table class="grid">
    <thead><tr><th style="width:32px">No.</th><th>Nama Barang</th><th style="width:130px">HARGA SAT. (Rp)</th><th style="width:130px">JML HARGA</th></tr></thead>
    <tbody>{$itemRows}</tbody>
  </table>
  <table class="sum">
    <tr><td>Sub Total</td><td class="r">{$fDpp}</td></tr>
    <tr><td>PPN {$ppnPct}%</td><td class="r">{$fPpn}</td></tr>
    <tr><td>PPH</td><td class="r">{$fPph}</td></tr>
    <tr class="tot"><td>TOTAL</td><td class="r">{$fNet}</td></tr>
  </table>
  <div style="clear:both"></div>
  <p style="margin-top:10px">Pembayaran dilakukan melalui transfer Rekening :</p>
  <table class="meta">
    <tr><td class="lbl">No. Rekening</td><td class="sep">:</td><td>{$eBankRek}</td></tr>
    <tr><td class="lbl">Bank</td><td class="sep">:</td><td>{$eBankNm}</td></tr>
    <tr><td class="lbl">Atas Nama</td><td class="sep">:</td><td>{$eBankAn}</td></tr>
  </table>
  <p>Demikian ini kami sampaikan, atas perhatiannya kami ucapkan terima kasih.</p>
  <div class="signs">
    <div class="srow">
      <div class="scol" style="width:34%">Hormat Kami,<div class="sp"></div><div class="nm">{$eDari}</div></div>
      <div style="width:60%">
        <div style="text-align:center">Menyetujui,</div>
        <div class="sp"></div>
        <div style="display:flex;justify-content:space-around;gap:12px">
          <div class="nm" style="flex:1;text-align:center">{$eSign1}</div>
          <div class="nm" style="flex:1;text-align:center">{$eSign2}</div>
        </div>
      </div>
    </div>
    <div class="srow" style="margin-top:40px">
      <div class="scol" style="width:34%">&nbsp;</div>
      <div style="width:60%">
        <div style="text-align:center">Mengetahui,</div>
        <div class="sp"></div>
        <div style="display:flex;justify-content:space-around;gap:12px">
          <div class="nm" style="flex:1;text-align:center">{$eMeng1}</div>
          <div class="nm" style="flex:1;text-align:center">{$eMeng2}</div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="page">
  <div class="created">Dicetak: {$created}</div>
  <table class="phead"><tr>
    <td style="width:70px"><img class="logo" src="{$logo}" alt="MUP"></td>
    <td><div class="ttl">BUKTI PERMOHONAN PEMBAYARAN</div></td>
    <td style="width:70px"></td>
  </tr></table>
  <table class="meta" style="margin-top:8px">
    <tr><td class="lbl">Diajukan Tanggal</td><td class="sep">:</td><td>{$eTgl}</td></tr>
    <tr><td class="lbl">Dibayarkan Kepada</td><td class="sep">:</td><td>{$eSupNm}</td></tr>
    <tr><td class="lbl">Departemen</td><td class="sep">:</td><td>{$eDept}</td></tr>
  </table>
  <table class="grid">
    <colgroup><col style="width:25%"><col style="width:25%"><col style="width:25%"><col style="width:25%"></colgroup>
    <thead><tr><th>Dibayar Via</th><th>Type Pengeluaran</th><th>Cara Pembayaran Bank</th><th>Lampiran</th></tr></thead>
    <tbody><tr>
      <td><span class="bx">{$cbKas}</span> KAS<br><span class="bx">{$cbBank}</span> {$eBankLbl}<br>No. Rekening : {$eBankRek}</td>
      <td><span class="bx">{$cbPekerjaan}</span> Pekerjaan<br><span class="bx">{$cbMaterial}</span> Material</td>
      <td><span class="bx">{$cbTunai}</span> Tunai<br><span class="bx">{$cbTransfer}</span> Transfer</td>
      <td><span class="bx">{$cbPnjLgsg}</span> Penunjukan Langsung<br><span class="bx">&nbsp;</span> Tender<br><span class="bx">&nbsp;</span> Non Pembelian</td>
    </tr></tbody>
  </table>
  <table class="grid">
    <thead><tr><th style="width:135px">No. Account</th><th>Uraian Pembayaran</th><th style="width:150px">Jumlah</th></tr></thead>
    <tbody>{$bppRows}</tbody>
    <tfoot>
      <tr>
        <td>No. Cek / BG : -</td>
        <td>No. Voucher : {$eNo}</td>
        <td class="r" style="font-weight:bold;background:#f0f0f0">Total : {$fTotalPay}</td>
      </tr>
    </tfoot>
  </table>
  <table class="meta">
    <tr><td class="lbl">Terbilang</td><td class="sep">:</td><td>{$eWords}</td></tr>
  </table>
  <table class="grid sign2" style="margin-top:18px;text-align:center">
    <colgroup><col style="width:14.28%"><col style="width:14.28%"><col style="width:14.28%"><col style="width:14.28%"><col style="width:14.28%"><col style="width:14.28%"><col style="width:14.32%"></colgroup>
    <tr><td>Diajukan Oleh,</td><td>Di Verifikasi Oleh,</td><td colspan="3">Di Setujui Oleh,</td><td>Di Bayarkan Oleh,</td><td>Di Terima Oleh,</td></tr>
    <tr class="sph"><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
    <tr><td>SPV/Manager Dept</td><td>Manager Accounting</td><td>Manager Finance</td><td>General Manager</td><td>Direktur</td><td>General Cashier</td><td>Penerima</td></tr>
  </table>
  <div class="noprint" style="margin-top:14px">
    <button onclick="window.print()" style="padding:6px 14px">Cetak</button>
    <a href="{$backUrl}" style="margin-left:10px;padding:6px 14px;border:1px solid #ccc;text-decoration:none;color:#333;border-radius:4px">&larr; Kembali ke Invoice</a>
  </div>
</div>

<script>window.onload=function(){setTimeout(function(){window.print()},300)}</script>
</body></html>
HTML;
    }

    public function pay(Request $request, BsApInvoice $invoice, PayApInvoice $action): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        $v = $request->validate([
            'payment_date'    => ['required', 'date'],
            'cash_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            'amount'          => ['required', 'numeric', 'min:0.01'],
            'reference'       => ['nullable', 'string', 'max:150'],
        ]);

        try {
            $action->execute($invoice, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.ap.show', $invoice)->with('success', 'Pembayaran hutang tercatat — jurnal otomatis terbentuk.');
    }

    /** Aging Hutang — umur hutang per supplier (Summary) atau per invoice (Detail). */
    public function aging(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        $view   = $request->input('view') === 'detail' ? 'detail' : 'summary';
        $pulled = $request->boolean('pull'); // data baru ditarik setelah klik "Tampilkan" → hindari load berat
        $dateFrom = $request->input('date_from') ?: null;
        $dateTo   = $request->input('date_to') ?: null;
        $asOfStr  = $dateTo ?: now()->toDateString();

        $rows    = [];
        $details = [];
        $totals  = ['current' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'd90p' => 0.0, 'total' => 0.0];
        if ($pulled) {
            [$rowsC, $totals, $details, $asOf, $dateFrom] = $this->computeAging($dateTo, $dateFrom);
            $rows    = array_values($rowsC);
            $asOfStr = $asOf->toDateString();
        }

        return Inertia::render('BusinessSupport/Payable/Aging', [
            'view'     => $view,
            'rows'     => $rows,
            'totals'   => $totals,
            'details'  => $details,
            'dateFrom' => $dateFrom,
            'dateTo'   => $asOfStr,
            'pulled'   => $pulled,
        ]);
    }

    /**
     * Perhitungan aging hutang — dipakai layar & export (#139).
     * date_to = tanggal acuan aging (default hari ini); date_from = batas bawah tanggal invoice (opsional).
     *
     * @return array{0:array<int,array<string,mixed>>,1:array<string,float>,2:array<int,array<string,mixed>>,3:\Illuminate\Support\Carbon,4:?string}
     */
    private function computeAging(?string $dateTo = null, ?string $dateFrom = null): array
    {
        $asOf  = $dateTo ? \Illuminate\Support\Carbon::parse($dateTo)->startOfDay() : now()->startOfDay();
        $from  = $dateFrom ?: null;
        $rows  = [];
        $details = [];
        $totals = ['current' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'd90p' => 0.0, 'total' => 0.0];
        $bucketLabel = ['current' => 'Belum JT', 'd30' => '1–30', 'd60' => '31–60', 'd90' => '61–90', 'd90p' => '>90'];

        // Basis NET & cutoff per tanggal acuan IDENTIK dgn Kartu Hutang: outstanding = net_payable
        // (setelah PPN & PPh) − Σ(pembayaran s/d tanggal acuan). TANPA filter status (status = kondisi
        // KINI, bukan posisi per-tanggal) → angka per-supplier sama persis dgn Saldo Hutang Kartu.
        $asOfStr = $asOf->toDateString();
        BsApInvoice::with(['supplier:id,code,name', 'payments' => fn ($q) => $q->whereDate('payment_date', '<=', $asOfStr)])
            ->when($from, fn ($q, $v) => $q->whereDate('invoice_date', '>=', $v))
            ->whereDate('invoice_date', '<=', $asOfStr)
            ->get()->each(function ($inv) use (&$rows, &$details, &$totals, $asOf, $bucketLabel): void {
                $paidToDate = (float) $inv->payments->sum('amount');
                $out = round((float) $inv->payable_amount - $paidToDate, 2); // NET outstanding per tanggal acuan
                if ($out <= 0.009) {
                    return;
                }
                $ref     = $inv->due_date ?? $inv->invoice_date;
                $overdue = $ref && $ref->lt($asOf) ? (int) $ref->diffInDays($asOf) : 0;
                $bucket  = $overdue <= 0 ? 'current' : ($overdue <= 30 ? 'd30' : ($overdue <= 60 ? 'd60' : ($overdue <= 90 ? 'd90' : 'd90p')));

                $key = $inv->supplier_id;
                $rows[$key] ??= ['name' => $inv->supplier?->name ?? '—', 'current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90p' => 0, 'total' => 0];
                $rows[$key][$bucket] += $out;
                $rows[$key]['total'] += $out;
                $totals[$bucket]     += $out;
                $totals['total']     += $out;

                $details[] = [
                    'supplier'    => $inv->supplier?->name ?? '—',
                    'invoice_no'  => $inv->invoice_number,
                    'due_date'    => $ref?->toDateString(),
                    'overdue'     => $overdue,
                    'bucket'      => $bucketLabel[$bucket],
                    'outstanding' => round($out, 2),
                ];
            });

        usort($details, fn ($a, $b) => $b['overdue'] <=> $a['overdue']);

        return [$rows, $totals, $details, $asOf, $from];
    }

    /** #139: Export Aging Hutang ke Excel (.xls) — ringkasan per supplier + detail per invoice. */
    public function agingExportExcel(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        [$rows, $totals, $details, $asOf, $dateFrom] = $this->computeAging($request->input('date_to'), $request->input('date_from'));

        $e  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');

        $period = ($dateFrom ? $e($dateFrom).' s/d ' : 'sampai ').$e($asOf->toDateString());

        $sum = '<table border="1"><tr><td colspan="7" style="font-weight:bold">AGING HUTANG (per '.$period.')</td></tr>'
            .'<tr style="background:#0f766e;color:#fff"><th>Supplier</th><th>Belum JT</th><th>1-30</th><th>31-60</th><th>61-90</th><th>&gt;90</th><th>Total</th></tr>';
        foreach (array_values($rows) as $r) {
            $sum .= '<tr><td>'.$e($r['name']).'</td><td>'.$rp($r['current']).'</td><td>'.$rp($r['d30']).'</td><td>'.$rp($r['d60']).'</td><td>'.$rp($r['d90']).'</td><td>'.$rp($r['d90p']).'</td><td>'.$rp($r['total']).'</td></tr>';
        }
        $sum .= '<tr style="font-weight:bold;background:#e2e8f0"><td>TOTAL</td><td>'.$rp($totals['current']).'</td><td>'.$rp($totals['d30']).'</td><td>'.$rp($totals['d60']).'</td><td>'.$rp($totals['d90']).'</td><td>'.$rp($totals['d90p']).'</td><td>'.$rp($totals['total']).'</td></tr></table><br>';

        $det = '<table border="1"><tr style="background:#0f766e;color:#fff"><th>Supplier</th><th>No Invoice</th><th>Jatuh Tempo</th><th>Hari</th><th>Bucket</th><th>Outstanding</th></tr>';
        foreach ($details as $d) {
            $det .= '<tr><td>'.$e($d['supplier']).'</td><td>'.$e($d['invoice_no']).'</td><td>'.$e($d['due_date']).'</td><td>'.$e($d['overdue']).'</td><td>'.$e($d['bucket']).'</td><td>'.$rp($d['outstanding']).'</td></tr>';
        }
        $det .= '</table>';

        $file = 'Aging-Hutang-'.now()->format('Ymd-His').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'.$sum.$det.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /** #139: Cetak Aging Hutang (HTML siap-cetak / PDF) — auto-print. */
    public function agingExportPdf(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        [$rows, $totals, $details, $asOf, $dateFrom] = $this->computeAging($request->input('date_to'), $request->input('date_from'));

        $e   = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp  = fn ($n) => number_format((float) $n, 0, ',', '.');
        $co  = (string) config('business_support.company.name', 'PT. Mitra Usaha Propertindo');
        $now = now()->translatedFormat('d F Y H:i');
        $period = ($dateFrom ? $e($dateFrom).' s/d ' : 'sampai ').$e($asOf->translatedFormat('d F Y'));

        $sumRows = '';
        foreach (array_values($rows) as $r) {
            $sumRows .= '<tr><td>'.$e($r['name']).'</td><td class="r">'.$rp($r['current']).'</td><td class="r">'.$rp($r['d30']).'</td><td class="r">'.$rp($r['d60']).'</td><td class="r">'.$rp($r['d90']).'</td><td class="r">'.$rp($r['d90p']).'</td><td class="r">'.$rp($r['total']).'</td></tr>';
        }
        if ($sumRows === '') {
            $sumRows = '<tr><td colspan="7" class="muted" style="text-align:center">Tidak ada hutang outstanding.</td></tr>';
        }
        $totRow = '<tr class="tot"><td>TOTAL</td><td class="r">'.$rp($totals['current']).'</td><td class="r">'.$rp($totals['d30']).'</td><td class="r">'.$rp($totals['d60']).'</td><td class="r">'.$rp($totals['d90']).'</td><td class="r">'.$rp($totals['d90p']).'</td><td class="r">'.$rp($totals['total']).'</td></tr>';

        $detRows = '';
        foreach ($details as $d) {
            $detRows .= '<tr><td>'.$e($d['supplier']).'</td><td>'.$e($d['invoice_no']).'</td><td>'.$e($d['due_date']).'</td><td class="r">'.$e($d['overdue']).'</td><td>'.$e($d['bucket']).'</td><td class="r">'.$rp($d['outstanding']).'</td></tr>';
        }
        $detBlock = $detRows !== ''
            ? '<h3>Detail per Invoice</h3><table><thead><tr><th>Supplier</th><th>No Invoice</th><th>Jatuh Tempo</th><th class="r">Hari</th><th>Bucket</th><th class="r">Outstanding</th></tr></thead><tbody>'.$detRows.'</tbody></table>'
            : '';

        $html = '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Aging Hutang</title><style>
            @page{size:A4 landscape;margin:14mm}*{box-sizing:border-box}body{font-family:"Segoe UI",Arial,sans-serif;font-size:11px;color:#1f2937;margin:0}
            h1{font-size:16px;margin:0 0 2px;color:#b91c1c}h3{font-size:12px;margin:14px 0 4px;color:#991b1b}
            .head{border-bottom:2px solid #b91c1c;padding-bottom:8px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:flex-start}
            .muted{color:#6b7280;font-size:10px}table{border-collapse:collapse;width:100%;margin:4px 0;font-size:10px}
            th,td{border:1px solid #cbd5e1;padding:4px 6px;text-align:left}.r{text-align:right;font-variant-numeric:tabular-nums}
            thead th{background:#f1f5f9}.tot{font-weight:bold;background:#e2e8f0}
            @media print{.noprint{display:none}}</style></head><body>
            <div class="head"><div><h1>AGING HUTANG (Account Payable)</h1><div class="muted">'.$e($co).'</div></div>
            <div class="muted" style="text-align:right">Periode: '.$period.'<br>Cetak: '.$e($now).'</div></div>
            <h3>Ringkasan per Supplier</h3>
            <table><thead><tr><th>Supplier</th><th class="r">Belum JT</th><th class="r">1–30</th><th class="r">31–60</th><th class="r">61–90</th><th class="r">&gt;90</th><th class="r">Total</th></tr></thead>
            <tbody>'.$sumRows.'</tbody><tfoot>'.$totRow.'</tfoot></table>
            '.$detBlock.'
            <button class="noprint" onclick="window.print()" style="margin-top:14px;padding:6px 14px">Cetak</button>
            <script>window.onload=function(){setTimeout(function(){window.print()},300)}</script></body></html>';

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Kartu Hutang — mutasi per supplier (Detail) atau rekap per supplier (Summary). */
    public function card(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        $view = $request->input('view') === 'summary' ? 'summary' : 'detail';
        $supplierId = $request->integer('supplier_id') ?: null;
        // #138: filter periode — invoice by invoice_date, pembayaran by payment_date.
        $dateFrom = $request->input('date_from') ?: null;
        $dateTo   = $request->input('date_to') ?: null;
        $entries        = collect();
        $openingBalance = 0.0;

        // KUNCI sinkron Detail↔Summary: keduanya pakai basis NET (payable_amount = net_payable =
        // total − PPh, sudah termasuk PPN) & cutoff yang sama (s/d date_to). Saldo akhir Detail
        // === Outstanding Summary untuk supplier yang sama.
        if ($supplierId) {
            $invoices = BsApInvoice::where('supplier_id', $supplierId)
                ->when($dateTo, fn ($q, $v) => $q->whereDate('invoice_date', '<=', $v)) // posisi hutang s/d date_to
                ->with(['payments' => fn ($q) => $q->when($dateTo, fn ($w, $v) => $w->whereDate('payment_date', '<=', $v))])
                ->get();

            $raw = collect();
            foreach ($invoices as $inv) {
                // Tagihan = NET (net_payable, setelah PPN & PPh) — bukan gross total.
                $raw->push(['date' => $inv->invoice_date?->format('Y-m-d'), 'ref' => $inv->invoice_number, 'desc' => $inv->description, 'debit' => 0.0, 'credit' => round((float) $inv->payable_amount, 2)]);
                foreach ($inv->payments as $p) {
                    // Bayar = nominal yang benar-benar dibayar (NET kas).
                    $raw->push(['date' => $p->payment_date?->format('Y-m-d'), 'ref' => $inv->invoice_number, 'desc' => 'Pembayaran'.($p->reference ? ' · '.$p->reference : ''), 'debit' => round((float) $p->amount, 2), 'credit' => 0.0]);
                }
            }
            $raw = $raw->sortBy('date')->values();

            // Saldo Awal = akumulasi NET sebelum date_from (hutang yang terbawa ke periode).
            $window = $raw;
            if ($dateFrom) {
                $openingBalance = round((float) $raw->filter(fn ($e) => $e['date'] !== null && $e['date'] < $dateFrom)->sum(fn ($e) => $e['credit'] - $e['debit']), 2);
                $window = $raw->filter(fn ($e) => $e['date'] === null || $e['date'] >= $dateFrom)->values();
            }

            $running = $openingBalance;
            $entries = $window->map(function ($e) use (&$running) {
                $running = round($running + $e['credit'] - $e['debit'], 2); // hutang +saat invoice, −saat bayar
                return [...$e, 'balance' => $running];
            });
        }

        // Summary: rekap per supplier dgn basis & cutoff IDENTIK dengan Detail → Outstanding == Saldo Hutang.
        $summary = collect();
        if ($view === 'summary') {
            $invs = BsApInvoice::with(['supplier:id,code,name', 'payments' => fn ($q) => $q->when($dateTo, fn ($w, $v) => $w->whereDate('payment_date', '<=', $v))])
                ->when($supplierId, fn ($q, $v) => $q->where('supplier_id', $v))
                ->when($dateTo, fn ($q, $v) => $q->whereDate('invoice_date', '<=', $v))
                ->get();
            $summary = $invs->groupBy('supplier_id')
                ->map(function ($g) {
                    $tertagih = round((float) $g->sum(fn ($i) => (float) $i->payable_amount), 2);          // NET tagihan
                    $terbayar = round((float) $g->sum(fn ($i) => (float) $i->payments->sum('amount')), 2); // NET dibayar s/d date_to
                    return [
                        'name'        => $g->first()->supplier?->name ?? '—',
                        'invoiced'    => $tertagih,
                        'paid'        => $terbayar,
                        'outstanding' => round($tertagih - $terbayar, 2),
                    ];
                })
                ->filter(fn ($r) => $r['outstanding'] > 0.009)
                ->sortByDesc('outstanding')->values();
        }

        $closing = $entries->isNotEmpty() ? (float) $entries->last()['balance'] : $openingBalance;

        return Inertia::render('BusinessSupport/Payable/Card', [
            'view'           => $view,
            'suppliers'      => BsSupplier::orderBy('name')->get(['id', 'code', 'name']),
            'supplierId'     => $supplierId,
            'dateFrom'       => $dateFrom,
            'dateTo'         => $dateTo,
            'entries'        => $entries,
            'openingBalance' => round((float) $openingBalance, 2),
            'summary'        => $summary,
            'outstanding'    => round((float) $closing, 2), // = Saldo Akhir Detail (sinkron dgn Outstanding Summary)
        ]);
    }

    /** Kartu Pembayaran Hutang — riwayat pembayaran (BsApPayment) per supplier. */
    public function paymentCard(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        $supplierId = $request->integer('supplier_id') ?: null;
        $bySupplier = fn ($q) => $q->whereHas('invoice', fn ($w) => $w->where('supplier_id', $supplierId));

        $payments = BsApPayment::query()
            ->with(['invoice:id,invoice_number,supplier_id', 'invoice.supplier:id,code,name'])
            ->when($supplierId, $bySupplier)
            ->orderByDesc('payment_date')->orderByDesc('id')
            ->paginate(50)->withQueryString();

        return Inertia::render('BusinessSupport/Payable/PaymentCard', [
            'payments'   => $payments,
            'suppliers'  => BsSupplier::orderBy('name')->get(['id', 'code', 'name']),
            'supplierId' => $supplierId,
            'total'      => round((float) BsApPayment::query()->when($supplierId, $bySupplier)->sum('amount'), 2),
        ]);
    }

    /** Data Kartu Pembayaran Hutang (seluruh riwayat, tanpa paginasi) — dipakai layar export. */
    private function paymentCardData(?int $supplierId): array
    {
        $bySupplier = fn ($q) => $q->whereHas('invoice', fn ($w) => $w->where('supplier_id', $supplierId));
        $payments = BsApPayment::query()
            ->with(['invoice:id,invoice_number,supplier_id', 'invoice.supplier:id,code,name'])
            ->when($supplierId, $bySupplier)
            ->orderByDesc('payment_date')->orderByDesc('id')->get();
        $supplierName = $supplierId ? (BsSupplier::find($supplierId)?->name ?? '-') : 'Semua Supplier';

        return [$payments, round((float) $payments->sum('amount'), 2), $supplierName];
    }

    /** Export Kartu Pembayaran Hutang ke Excel (.xls). */
    public function paymentCardExportExcel(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);
        [$payments, $total, $supplierName] = $this->paymentCardData($request->integer('supplier_id') ?: null);

        $e  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');

        $h = '<table border="1"><tr><td colspan="5" style="font-weight:bold">KARTU PEMBAYARAN HUTANG — '.$e($supplierName).'</td></tr>'
            .'<tr style="background:#0f766e;color:#fff"><th>Tanggal Bayar</th><th>Supplier</th><th>No Invoice</th><th>Referensi</th><th>Nominal</th></tr>';
        foreach ($payments as $p) {
            $h .= '<tr><td>'.$e(optional($p->payment_date)->toDateString()).'</td><td>'.$e($p->invoice?->supplier?->name).'</td><td>'.$e($p->invoice?->invoice_number).'</td><td>'.$e($p->reference).'</td><td>'.$rp($p->amount).'</td></tr>';
        }
        $h .= '<tr style="font-weight:bold;background:#e2e8f0"><td colspan="4">TOTAL</td><td>'.$rp($total).'</td></tr></table>';

        $file = 'Kartu-Pembayaran-Hutang-'.now()->format('Ymd-His').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'.$h.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /** Cetak Kartu Pembayaran Hutang (HTML siap-cetak / PDF) — auto-print. */
    public function paymentCardExportPdf(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);
        [$payments, $total, $supplierName] = $this->paymentCardData($request->integer('supplier_id') ?: null);

        $e   = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp  = fn ($n) => number_format((float) $n, 0, ',', '.');
        $co  = (string) config('business_support.company.name', 'PT. Mitra Usaha Propertindo');
        $now = now()->translatedFormat('d F Y H:i');

        $rows = '';
        foreach ($payments as $p) {
            $rows .= '<tr><td>'.$e(optional($p->payment_date)->translatedFormat('d M Y')).'</td><td>'.$e($p->invoice?->supplier?->name).'</td><td>'.$e($p->invoice?->invoice_number).'</td><td>'.$e($p->reference).'</td><td class="r">'.$rp($p->amount).'</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="muted" style="text-align:center">Belum ada pembayaran.</td></tr>';
        }
        $totRow = '<tr class="tot"><td colspan="4">TOTAL</td><td class="r">'.$rp($total).'</td></tr>';

        $html = '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Kartu Pembayaran Hutang</title><style>
            @page{size:A4;margin:14mm}*{box-sizing:border-box}body{font-family:"Segoe UI",Arial,sans-serif;font-size:11px;color:#1f2937;margin:0}
            h1{font-size:16px;margin:0 0 2px;color:#b91c1c}
            .head{border-bottom:2px solid #b91c1c;padding-bottom:8px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:flex-start}
            .muted{color:#6b7280;font-size:10px}table{border-collapse:collapse;width:100%;margin:4px 0;font-size:10px}
            th,td{border:1px solid #cbd5e1;padding:4px 6px;text-align:left}.r{text-align:right;font-variant-numeric:tabular-nums}
            thead th{background:#f1f5f9}.tot{font-weight:bold;background:#e2e8f0}
            @media print{.noprint{display:none}}</style></head><body>
            <div class="head"><div><h1>KARTU PEMBAYARAN HUTANG</h1><div class="muted">'.$e($co).'</div></div>
            <div class="muted" style="text-align:right">Supplier: '.$e($supplierName).'<br>Cetak: '.$e($now).'</div></div>
            <table><thead><tr><th>Tanggal Bayar</th><th>Supplier</th><th>No Invoice</th><th>Referensi</th><th class="r">Nominal</th></tr></thead>
            <tbody>'.$rows.'</tbody><tfoot>'.$totRow.'</tfoot></table>
            <button class="noprint" onclick="window.print()" style="margin-top:14px;padding:6px 14px">Cetak</button>
            <script>window.onload=function(){setTimeout(function(){window.print()},300)}</script></body></html>';

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function accId(string $key): ?int
    {
        $code = config("business_support.gl_accounts.$key");

        return $code ? BsAccount::where('code', $code)->value('id') : null;
    }
}
