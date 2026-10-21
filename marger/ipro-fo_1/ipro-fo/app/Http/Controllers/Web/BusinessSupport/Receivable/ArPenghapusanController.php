<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Receivable;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsArInvoice;
use App\Domain\BusinessSupport\Services\ArMemorialService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Penghapusan Piutang — write-off sisa piutang invoice ke akun lawan
 * (mis. Kerugian Penghapusan Piutang / bad debt). Dr Lawan / Cr Piutang.
 */
class ArPenghapusanController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('ar.penghapusan.view'), 403);

        $rows = BsArInvoice::where('status', 'open')->with('customer:id,code,full_name')
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('invoice_number', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")))
            ->latest('invoice_date')->get()
            ->map(fn ($i) => [
                'id' => $i->id, 'invoice_number' => $i->invoice_number, 'customer' => $i->customer?->full_name,
                'description' => $i->description, 'outstanding' => (float) $i->outstanding, 'due_date' => $i->due_date?->format('Y-m-d'),
            ])->filter(fn ($i) => $i['outstanding'] > 0.009)->values();

        return Inertia::render('BusinessSupport/Receivable/Penghapusan', [
            'invoices'        => $rows,
            'filters'         => $request->only('search'),
            'counterAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)->whereIn('type', ['expense', 'income'])->orderBy('code')->get(['id', 'code', 'name']),
            'defaultCounter'  => BsAccount::where('code', '50302020229')->value('id'), // Kerugian Penghapusan Piutang
        ]);
    }

    public function store(Request $request, BsArInvoice $invoice, ArMemorialService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['ar.penghapusan.create', 'businesssupport.approval.act']), 403);

        $v = $request->validate([
            'counter_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            'amount'             => ['required', 'numeric', 'min:0.01'],
            'memorial_date'      => ['required', 'date'],
            'description'        => ['required', 'string', 'max:255'],
        ]);

        try {
            $svc->create([
                'memorial_date'      => $v['memorial_date'],
                'customer_id'        => $invoice->customer_id,
                'ar_invoice_id'      => $invoice->id,
                'trx_type'           => 'penghapusan',
                'counter_account_id' => $v['counter_account_id'],
                'amount'             => $v['amount'],
                'description'        => $v['description'],
                'is_writeoff'        => true,
            ], $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.ar.penghapusan.index')->with('success', "Sisa piutang {$invoice->invoice_number} dihapus — jurnal otomatis (Dr Lawan / Cr Piutang).");
    }
}
