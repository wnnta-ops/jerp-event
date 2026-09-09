<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Payable;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Services\ApMemorialService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Penghapusan Nilai Hutang — write-off sisa hutang invoice ke akun lawan
 * (mis. Pendapatan Lain). Dr Hutang / Cr Lawan.
 */
class PenghapusanController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('ap.penghapusan.view'), 403);

        $rows = BsApInvoice::where('status', 'open')->with('supplier:id,code,name')
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('invoice_number', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")))
            ->latest('invoice_date')->get()
            ->map(fn ($i) => [
                'id' => $i->id, 'invoice_number' => $i->invoice_number, 'supplier' => $i->supplier?->name,
                'description' => $i->description, 'outstanding' => (float) $i->outstanding, 'due_date' => $i->due_date?->format('Y-m-d'),
            ])->filter(fn ($i) => $i['outstanding'] > 0.009)->values();

        return Inertia::render('BusinessSupport/Payable/Penghapusan', [
            'invoices'       => $rows,
            'filters'        => $request->only('search'),
            'counterAccounts'=> BsAccount::where('is_active', true)->where('is_postable', true)->whereIn('type', ['income', 'expense'])->orderBy('code')->get(['id', 'code', 'name']),
            'defaultCounter' => BsAccount::where('code', '40201010101')->value('id'), // Penjualan diluar usaha (pendapatan lain)
        ]);
    }

    /**
     * Laporan Penghapusan Hutang — rincian & history seluruh penghapusan nilai hutang,
     * tertaut ke jurnal General Ledger. Filter (tanggal/invoice/supplier/keterangan/nilai) di sisi klien.
     */
    public function report(Request $request): Response
    {
        abort_unless($request->user()?->can('ap.penghapusan.view'), 403);

        $rows = \App\Domain\BusinessSupport\Models\BsApMemorial::where('trx_type', 'penghapusan')
            ->with(['invoice:id,invoice_number,description', 'supplier:id,name', 'counterAccount:id,code,name', 'journal:id,journal_number'])
            ->latest('memorial_date')->latest('id')->get()
            ->map(fn ($m) => [
                'id'                  => $m->id,
                'memorial_number'     => $m->memorial_number,
                'memorial_date'       => $m->memorial_date?->toDateString(),
                'invoice_id'          => $m->ap_invoice_id,
                'invoice_number'      => $m->invoice?->invoice_number,
                'invoice_description' => $m->invoice?->description, // keterangan invoice hutang
                'supplier'            => $m->supplier?->name,
                'amount'              => (float) $m->amount,
                'reason'              => $m->description,           // alasan penghapusan hutang
                'counter_account'     => $m->counterAccount ? $m->counterAccount->code.' — '.$m->counterAccount->name : null,
                'journal_id'          => $m->journal_id,
                'journal_number'      => $m->journal?->journal_number,
            ]);

        return Inertia::render('BusinessSupport/Payable/PenghapusanReport', [
            'rows'  => $rows,
            'total' => round((float) $rows->sum('amount'), 2),
        ]);
    }

    public function store(Request $request, BsApInvoice $invoice, ApMemorialService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        $v = $request->validate([
            'counter_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            'amount'             => ['required', 'numeric', 'min:0.01'],
            'memorial_date'      => ['required', 'date'],
            'description'        => ['required', 'string', 'max:255'],
        ]);

        try {
            $svc->create([
                'memorial_date'      => $v['memorial_date'],
                'supplier_id'        => $invoice->supplier_id,
                'ap_invoice_id'      => $invoice->id,
                'trx_type'           => 'penghapusan',
                'counter_account_id' => $v['counter_account_id'],
                'amount'             => $v['amount'],
                'description'        => $v['description'],
                'is_writeoff'        => true,
            ], $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.ap.penghapusan.index')->with('success', "Sisa hutang {$invoice->invoice_number} dihapus — jurnal otomatis (Dr Hutang / Cr Lawan).");
    }
}
