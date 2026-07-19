<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Receivable;

use App\Domain\BusinessSupport\Actions\PayArInvoice;
use App\Domain\BusinessSupport\Models\BsAccount;
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

        // SPR yang bisa dipilih untuk baris di HALAMAN INI saja (dikelompokkan per customer).
        // Dibatasi ke customer yang benar-benar tampil supaya payload tidak membawa seluruh
        // booking hanya untuk mengisi dropdown yang jarang dipakai.
        $customerIds = $rows->getCollection()->pluck('customer_id')->filter()->unique()->values();
        $sprOptions = $customerIds->isEmpty() ? [] : Booking::query()
            ->whereIn('customer_id', $customerIds)
            ->with('unit:id,unit_code')
            ->latest('booking_date')->get()
            ->groupBy('customer_id')
            ->map(fn ($list) => $list->map(fn (Booking $b) => [
                'id'    => $b->id,
                'label' => trim($b->booking_number.($b->unit?->unit_code ? ' · '.$b->unit->unit_code : '')),
            ])->values());

        return Inertia::render('BusinessSupport/Receivable/Index', [
            'invoices'   => $rows,
            'sprOptions' => $sprOptions,
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
            'customer_id'    => $r->customer_id,
            'booking_id'     => $r->booking_id,
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

    /**
     * Kaitkan / lepas SPR pada invoice yang SUDAH tercatat.
     *
     * Diperlukan karena kolom booking_id baru ditambahkan: seluruh invoice yang sudah ada
     * bernilai NULL dan tanpa jalan ini tidak akan pernah bisa masuk Rekonsiliasi per SPR.
     * Hanya mengubah pengelompokan — tidak menyentuh nilai, status, maupun jurnal.
     */
    public function linkSpr(Request $request, BsArInvoice $invoice): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        $v = $request->validate([
            'booking_id' => ['nullable', 'integer', 'exists:bookings,id'],
        ]);

        $bookingId = $v['booking_id'] ?? null;

        // SPR WAJIB milik customer invoice ini. Tanpa penjaga ini, salah pilih akan membuat
        // tagihan customer A muncul di grup rekonsiliasi (dan surat tagihan) customer B.
        if ($bookingId !== null) {
            $booking = Booking::find($bookingId);
            if (! $booking || (int) $booking->customer_id !== (int) $invoice->customer_id) {
                return back()->with('error', 'SPR tersebut bukan milik customer invoice ini.');
            }
        }

        $invoice->update(['booking_id' => $bookingId]);

        return back()->with('success', $bookingId
            ? "Invoice {$invoice->invoice_number} dikaitkan ke SPR."
            : "Kaitan SPR pada invoice {$invoice->invoice_number} dilepas.");
    }

    // create() & store() DIHILANGKAN: invoice piutang tidak dibuat manual lagi. Ia dibentuk otomatis
    // saat unit di-booking di modul Booked Revenue (lihat CreateArInvoiceFromBooking) — satu sumber,
    // tidak ada risiko piutang ganda maupun nilai yang menyimpang dari Booked Revenue.

    public function show(Request $request, BsArInvoice $invoice): Response
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        // Rincian disamakan dengan "Sudah Booked Revenue": tampilkan SPR + unit (turunan booking),
        // bukan Kategori (konsep kategori invoice sudah dihapus).
        $invoice->load([
            'customer:id,code,full_name',
            'booking:id,booking_number,unit_id', 'booking.unit:id,unit_code',
            'lines', 'schedules' => fn ($q) => $q->orderBy('installment_no'),
            'payments' => fn ($q) => $q->latest('payment_date')->latest('id'),
        ]);

        return Inertia::render('BusinessSupport/Receivable/Show', [
            // Tanggal dikirim sebagai yyyy-mm-dd (toDateString), BUKAN ISO berwaktu dari toArray():
            // cast 'date' pada tengah malam WIB berserialisasi jadi UTC "…T17:00:00Z" — memotong 10
            // karakter darinya justru MUNDUR sehari, dan <input type=date> menolaknya. toDateString()
            // memberi tanggal kalender yang benar (sama seperti baris daftar & Booked Revenue).
            'invoice'      => array_merge($invoice->toArray(), [
                'receivable_amount' => $invoice->receivable_amount,
                'invoice_date'      => optional($invoice->invoice_date)->toDateString(),
                'due_date'          => optional($invoice->due_date)->toDateString(),
                'receive_date'      => optional($invoice->receive_date)->toDateString(),
            ]),
            'outstanding'  => (float) $invoice->outstanding,
            'cashAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)->where('code', 'like', '101010%')->orderBy('code')->get(['id', 'code', 'name']),
            'defaultCash'  => $this->accId('cash'),
        ]);
    }

    /**
     * Edit rincian invoice piutang — HANYA metadata (tanggal, jatuh tempo, keterangan, no. ref).
     *
     * Nilai (DPP/PPN/PPh/total) dan jurnal pendaftaran yang sudah diposting SENGAJA terkunci —
     * sama seperti Booked Revenue: tagihan tak bisa dikembalikan, hanya keterangannya yang dirapikan.
     */
    public function update(Request $request, BsArInvoice $invoice): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ar'), 403);

        $v = $request->validate([
            'invoice_date'    => ['required', 'date'],
            'due_date'        => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'description'     => ['required', 'string', 'max:255'],
            'customer_ref_no' => ['nullable', 'string', 'max:80'],
        ]);

        $invoice->update([
            'invoice_date'    => $v['invoice_date'],
            'due_date'        => $v['due_date'] ?? null,
            'description'     => $v['description'],
            'customer_ref_no' => $v['customer_ref_no'] ?? null,
        ]);

        return back()->with('success', "Rincian {$invoice->invoice_number} diperbarui. Nilai & jurnal tidak berubah (terkunci).");
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
