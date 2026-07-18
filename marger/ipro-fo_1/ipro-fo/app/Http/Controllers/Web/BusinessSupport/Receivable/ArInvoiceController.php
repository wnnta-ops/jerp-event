<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Receivable;

use App\Domain\BusinessSupport\Actions\PayArInvoice;
use App\Domain\BusinessSupport\Actions\RegisterArInvoice;
use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsArCategory;
use App\Domain\BusinessSupport\Models\BsArInvoice;
use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Sales\Booking\Models\Booking;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ArInvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        $rows = BsArInvoice::query()
            // Rincian disamakan dengan layar "Sudah Booked Revenue": selain data akuntansi,
            // barisnya harus menunjukkan SPR + unit yang ditagih. Keduanya turunan booking.
            ->with(['customer:id,code,full_name', 'booking:id,booking_number,unit_id', 'booking.unit:id,unit_code,unit_type_id', 'booking.unit.unitType:id,name'])
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('invoice_number', 'like', "%{$s}%")
                ->orWhere('description', 'like', "%{$s}%")
                ->orWhereHas('booking', fn ($b) => $b->where('booking_number', 'like', "%{$s}%"))
                ->orWhereHas('booking.unit', fn ($u) => $u->where('unit_code', 'like', "%{$s}%"))))
            ->latest('invoice_date')->latest('id')
            ->paginate(50)->withQueryString();

        $rows->getCollection()->transform(fn (BsArInvoice $r) => $this->row($r));

        return Inertia::render('BusinessSupport/Receivable/Index', [
            'invoices' => $rows,
            'filters'  => $request->only(['status', 'search']),
            'summary'  => [
                // Basis HARUS sama dengan kolom "Sisa" per baris (accessor outstanding), yaitu
                // net_receivable − dibayar. Memakai total − dibayar membuat kartu tidak pernah
                // sama dengan penjumlahan kolom di bawahnya pada invoice ber-PPh.
                // COALESCE: data lama bisa punya net_receivable = 0, di situ total yang berlaku.
                'open'        => (float) BsArInvoice::where('status', 'open')
                    ->selectRaw('COALESCE(SUM(CASE WHEN net_receivable > 0 THEN net_receivable ELSE total END) - SUM(paid_amount), 0) AS sisa')
                    ->value('sisa'),
                'count_open'  => BsArInvoice::where('status', 'open')->count(),
                'count_total' => BsArInvoice::count(),
            ],
        ]);
    }

    /**
     * Satu baris daftar Invoice Piutang — rincian disamakan dengan layar "Sudah Booked Revenue"
     * (SPR + unit + nilai), ditambah kolom akuntansi milik AR (DPP/PPN/sisa).
     *
     * @return array<string, mixed>
     */
    private function row(BsArInvoice $r): array
    {
        $unit = $r->booking?->unit;

        return [
            'id'             => $r->id,
            'invoice_number' => $r->invoice_number,
            'invoice_date'   => optional($r->invoice_date)->toDateString(),
            'due_date'       => optional($r->due_date)->toDateString(),
            'customer'       => $r->customer?->full_name,
            'customer_code'  => $r->customer?->code,
            'spr'            => $r->booking?->booking_number,
            'unit'           => $unit?->unit_code,
            'unit_type'      => $unit?->unitType?->name,
            'description'    => $r->description,
            'dpp'            => (float) $r->dpp,
            'ppn'            => (float) $r->ppn_amount,
            'total'          => (float) $r->total,
            // Sisa memakai accessor model (net_receivable − dibayar), BUKAN total − dibayar:
            // untuk invoice ber-PPh, yang ditagih ke customer adalah net_receivable.
            'outstanding'    => $r->outstanding,
            'paid_amount'    => (float) $r->paid_amount,
            'status'         => $r->status,
        ];
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        return Inertia::render('BusinessSupport/Receivable/Form', [
            'customers'  => Customer::orderBy('full_name')->limit(1000)->get(['id', 'code', 'full_name']),
            'categories' => BsArCategory::where('is_active', true)->with(['incomeAccount:id,code,name', 'receivableAccount:id,code,name'])->orderBy('name')->get()
                ->map(fn ($c) => [
                    'id' => $c->id, 'code' => $c->code, 'name' => $c->name,
                    'income' => $c->incomeAccount?->code.' - '.$c->incomeAccount?->name,
                    'receivable' => $c->receivableAccount?->code.' - '.$c->receivableAccount?->name,
                ]),
            'pphAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)->where('code', 'like', '1010502%')->orderBy('code')->get(['id', 'code', 'name']),
            'trxTypes'    => BsArInvoice::TRX_TYPES,
            // Daftar SPR untuk dikaitkan ke invoice (opsional). Label memuat unit + customer supaya
            // petugas tidak perlu menghafal nomor SPR.
            'bookings'    => Booking::query()
                ->with(['unit:id,unit_code', 'customer:id,full_name'])
                ->latest('booking_date')->limit(1000)->get()
                ->map(fn (Booking $b) => [
                    'id'          => $b->id,
                    'code'        => $b->booking_number,
                    'name'        => trim(implode(' · ', array_filter([$b->unit?->unit_code, $b->customer?->full_name]))),
                    'customer_id' => $b->customer_id,
                ]),
        ]);
    }

    public function store(Request $request, RegisterArInvoice $action): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        $v = $request->validate([
            'customer_id'         => ['required', 'integer', 'exists:customers,id'],
            // SPR opsional: invoice denda / saldo awal / tagihan non-unit memang tidak punya SPR.
            // Bila diisi, barisnya ikut terhitung di Rekonsiliasi Piutang per SPR.
            'booking_id'          => ['nullable', 'integer', 'exists:bookings,id'],
            'category_id'         => ['required', 'integer', 'exists:bs_ar_categories,id'],
            'trx_type'            => ['required', 'in:normal,denda,saldo_awal'],
            'customer_ref_no'     => ['nullable', 'string', 'max:80'],
            'manual_no'           => ['nullable', 'string', 'max:80'],
            'invoice_date'        => ['required', 'date'],
            'receive_date'        => ['nullable', 'date'],
            'due_date'            => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'term_days'           => ['nullable', 'integer', 'min:0', 'max:3650'],
            'description'         => ['required', 'string', 'max:255'],
            'transfer_detail'     => ['nullable', 'string', 'max:255'],
            'kurs'                => ['nullable', 'numeric', 'min:0'],
            'dpp'                 => ['required', 'numeric', 'min:0.01'],
            'ppn_mode'            => ['required', 'in:none,with'],
            'ppn_pct'             => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pph_mode'            => ['required', 'in:none,withheld'],
            'pph_pct'             => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pph_account_id'      => ['nullable', 'integer', 'exists:bs_accounts,id'],
            'lines'                  => ['nullable', 'array'],
            'lines.*.description'    => ['required_with:lines', 'string', 'max:200'],
            'lines.*.qty'            => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_price'     => ['nullable', 'numeric', 'min:0'],
            'lines.*.amount'         => ['required_with:lines', 'numeric', 'min:0'],
            'schedules'              => ['nullable', 'array'],
            'schedules.*.due_date'   => ['required_with:schedules', 'date'],
            'schedules.*.amount'     => ['required_with:schedules', 'numeric', 'min:0.01'],
        ]);

        try {
            $inv = $action->execute($v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.ar.show', $inv)->with('success', "Tagihan piutang {$inv->invoice_number} tercatat — jurnal otomatis terbentuk.");
    }

    public function show(Request $request, BsArInvoice $invoice): Response
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        $invoice->load([
            'customer:id,code,full_name', 'category:id,code,name',
            'lines', 'schedules' => fn ($q) => $q->orderBy('installment_no'),
            'payments' => fn ($q) => $q->latest('payment_date')->latest('id'),
        ]);

        return Inertia::render('BusinessSupport/Receivable/Show', [
            'invoice'      => array_merge($invoice->toArray(), ['receivable_amount' => $invoice->receivable_amount]),
            'outstanding'  => (float) $invoice->outstanding,
            'cashAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)->where('code', 'like', '101010%')->orderBy('code')->get(['id', 'code', 'name']),
            'defaultCash'  => $this->accId('cash'),
        ]);
    }

    public function pay(Request $request, BsArInvoice $invoice, PayArInvoice $action): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ar'), 403);

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

        return redirect()->route('bs.ar.show', $invoice)->with('success', 'Penerimaan piutang tercatat — jurnal otomatis terbentuk.');
    }

    /** Reschedule jatuh tempo — jadwal ulang sisa piutang (tanpa jurnal). */
    public function reschedule(Request $request, BsArInvoice $invoice, \App\Domain\BusinessSupport\Actions\RescheduleArInvoice $action): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        $v = $request->validate([
            'schedules'            => ['required', 'array', 'min:1'],
            'schedules.*.due_date' => ['required', 'date'],
            'schedules.*.amount'   => ['required', 'numeric', 'min:0.01'],
            'note'                 => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $action->execute($invoice, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.ar.show', $invoice)->with('success', 'Jadwal jatuh tempo piutang berhasil dijadwal ulang.');
    }

    /** Aging Piutang — umur piutang per customer dalam bucket. */
    public function aging(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        $today  = now()->startOfDay();
        $rows   = [];
        $totals = ['current' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'd90p' => 0.0, 'total' => 0.0];

        BsArInvoice::with('customer:id,code,full_name')->where('status', 'open')->get()->each(function ($inv) use (&$rows, &$totals, $today): void {
            $out = (float) $inv->outstanding;
            if ($out <= 0.009) {
                return;
            }
            $ref     = $inv->due_date ?? $inv->invoice_date;
            $overdue = $ref && $ref->lt($today) ? $ref->diffInDays($today) : 0;
            $bucket  = $overdue <= 0 ? 'current' : ($overdue <= 30 ? 'd30' : ($overdue <= 60 ? 'd60' : ($overdue <= 90 ? 'd90' : 'd90p')));

            $key = $inv->customer_id;
            $rows[$key] ??= ['name' => $inv->customer?->full_name ?? '—', 'current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90p' => 0, 'total' => 0];
            $rows[$key][$bucket] += $out;
            $rows[$key]['total'] += $out;
            $totals[$bucket]     += $out;
            $totals['total']     += $out;
        });

        return Inertia::render('BusinessSupport/Receivable/Aging', [
            'rows'   => array_values($rows),
            'totals' => $totals,
        ]);
    }

    /** Kartu Piutang — mutasi per customer dengan saldo berjalan. */
    public function card(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        $customerId = $request->integer('customer_id') ?: null;
        $entries = collect();

        if ($customerId) {
            $invoices = BsArInvoice::where('customer_id', $customerId)->with('payments')->get();
            foreach ($invoices as $inv) {
                $entries->push(['date' => $inv->invoice_date?->format('Y-m-d'), 'ref' => $inv->invoice_number, 'desc' => $inv->description, 'debit' => (float) $inv->total, 'credit' => 0]);
                foreach ($inv->payments as $p) {
                    $entries->push(['date' => $p->payment_date?->format('Y-m-d'), 'ref' => $inv->invoice_number, 'desc' => 'Penerimaan'.($p->reference ? ' · '.$p->reference : ''), 'debit' => 0, 'credit' => (float) $p->amount]);
                }
            }
            $running = 0.0;
            $entries = $entries->sortBy('date')->values()->map(function ($e) use (&$running) {
                $running += $e['debit'] - $e['credit']; // piutang bertambah saat invoice (debit), berkurang saat terima (credit)
                return [...$e, 'balance' => $running];
            });
        }

        return Inertia::render('BusinessSupport/Receivable/Card', [
            'customers'  => Customer::orderBy('full_name')->limit(500)->get(['id', 'code', 'full_name']),
            'customerId' => $customerId,
            'entries'    => $entries,
            'outstanding'=> $entries->isNotEmpty() ? $entries->last()['balance'] : 0,
        ]);
    }

    private function accId(string $key): ?int
    {
        $code = config("business_support.gl_accounts.$key");

        return $code ? BsAccount::where('code', $code)->value('id') : null;
    }
}
