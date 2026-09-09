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
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ArInvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('ar.view'), 403);

        $rows = BsArInvoice::query()
            // Rincian disamakan dengan layar "Sudah Booked Revenue": selain data akuntansi,
            // barisnya harus menunjukkan SPR + unit yang ditagih. Keduanya turunan booking.
            ->with(['customer:id,code,full_name', 'booking:id,booking_number,unit_id', 'booking.unit:id,unit_code,unit_type_id', 'booking.unit.unitType:id,name'])
            // Tidak ada lagi penyaringan di server: pencarian, filter per kolom, dan pengurutan
            // seluruhnya dikerjakan di klien atas data lengkap di bawah ini.
            ->latest('invoice_date')->latest('id')
            // SELURUH baris dikirim ke klien — sama dengan daftar Invoice Hutang (AP) & Purchase
            // Order. Filter per-kolom dan pengurutan dihitung di sisi klien, jadi paginasi server
            // justru menyesatkan: penyaringan hanya akan mengenai 50 baris yang kebetulan tampil.
            ->get()
            ->map(fn (BsArInvoice $r) => $this->row($r))
            ->values();

        // SPR yang bisa dipilih (dikelompokkan per customer), dibatasi ke customer yang
        // benar-benar tampil supaya payload tidak membawa seluruh booking.
        $customerIds = $rows->pluck('customer_id')->filter()->unique()->values();
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
        abort_unless($request->user()?->can('ar.update'), 403);

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
        abort_unless($request->user()?->can('ar.view'), 403);

        // Rincian disamakan dengan "Sudah Booked Revenue": customer (+telepon/email), SPR + unit
        // (turunan booking), serta ladder nilai Harga → Diskon → DPP → PPN → Total. Harga & diskon
        // tidak tersimpan di invoice — diambil dari Booked Revenue asal.
        $invoice->load([
            'customer:id,code,full_name,phone,email',
            'booking:id,booking_number,unit_id', 'booking.unit:id,unit_code',
            'bookedRevenue:id,gross_price,discount,amount',
            'lines', 'schedules' => fn ($q) => $q->orderBy('installment_no'),
            'payments' => fn ($q) => $q->latest('payment_date')->latest('id'),
        ]);

        $br = $invoice->bookedRevenue;

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
                // Harga & diskon dari Booked Revenue asal (ladder rincian = Sudah Booked Revenue);
                // NULL untuk invoice lama tanpa Booked Revenue — baris Harga/Diskon disembunyikan.
                'gross_price'       => $br ? (float) $br->gross_price : null,
                'discount'          => $br ? (float) $br->discount : null,
            ]),
            'outstanding'  => (float) $invoice->outstanding,
            'cashAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)->where('code', 'like', '101010%')->orderBy('code')->get(['id', 'code', 'name']),
            'defaultCash'  => $this->accId('cash'),
        ]);
    }

    /**
     * Edit rincian invoice piutang — HANYA metadata (tanggal, jatuh tempo, keterangan, no. ref).
     *
     * Nilai (DPP/PPN/PPh/total) SENGAJA terkunci — sama seperti Booked Revenue asalnya: tagihan tak
     * bisa dikembalikan, hanya keterangannya yang dirapikan. Invoice ini tidak punya jurnal sendiri
     * (jurnal dicatat sekali saat booking di modul Booked Revenue), jadi tak ada jurnal yang disentuh.
     */
    public function update(Request $request, BsArInvoice $invoice): RedirectResponse
    {
        abort_unless($request->user()?->can('ar.update'), 403);

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

        return back()->with('success', "Rincian {$invoice->invoice_number} diperbarui. Nilai tagihan tidak berubah (terkunci).");
    }

    public function pay(Request $request, BsArInvoice $invoice, PayArInvoice $action): RedirectResponse
    {
        abort_unless($request->user()?->can('ar.pay'), 403);

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
        abort_unless($request->user()?->can('ar.reschedule'), 403);

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
        abort_unless($request->user()?->can('ar.aging.view'), 403);

        $today  = now()->startOfDay();
        $rows   = [];
        $totals = ['d30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'd90p' => 0.0, 'total' => 0.0];
        // Invoice yang BELUM jatuh tempo tidak masuk aging — belum ada umur tunggakannya.
        // Jumlahnya tetap dihitung untuk catatan kaki supaya jelas ke mana perginya.
        $belumJt = 0.0;
        $belumJtCount = 0;

        BsArInvoice::with('customer:id,code,full_name')->where('status', 'open')->get()
            ->each(function ($inv) use (&$rows, &$totals, &$belumJt, &$belumJtCount, $today): void {
                $out = (float) $inv->outstanding;
                if ($out <= 0.009) {
                    return;
                }
                $ref     = $inv->due_date ?? $inv->invoice_date;
                $overdue = $ref && $ref->lt($today) ? $ref->diffInDays($today) : 0;
                if ($overdue <= 0) {
                    $belumJt += $out;
                    $belumJtCount++;

                    return;
                }
                $bucket = $overdue <= 30 ? 'd30' : ($overdue <= 60 ? 'd60' : ($overdue <= 90 ? 'd90' : 'd90p'));

                $key = $inv->customer_id;
                $rows[$key] ??= ['name' => $inv->customer?->full_name ?? '—', 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90p' => 0, 'total' => 0, 'max_overdue' => 0];
                $rows[$key][$bucket] += $out;
                $rows[$key]['total'] += $out;
                $rows[$key]['max_overdue'] = max($rows[$key]['max_overdue'], $overdue);
                $totals[$bucket]     += $out;
                $totals['total']     += $out;
            });

        // Tunggakan terbesar di atas — yang paling perlu ditindaklanjuti terbaca lebih dulu.
        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

        return Inertia::render('BusinessSupport/Receivable/Aging', [
            'rows'   => $rows,
            'totals' => $totals,
            'belumJt' => ['amount' => round($belumJt, 2), 'count' => $belumJtCount],
        ]);
    }

    /** Kartu Piutang — mutasi per customer dengan saldo berjalan. */
    public function card(Request $request): Response
    {
        abort_unless($request->user()?->can('ar.card.view'), 403);

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

        // Saldo piutang per customer = Σ total invoice − Σ penerimaan. Rumusnya sengaja
        // SAMA PERSIS dengan saldo berjalan kartu di atas (debit invoice − kredit terima)
        // supaya daftar "punya saldo" tidak pernah berbeda dengan angka yang tampil.
        $totalInvoice = DB::table('bs_ar_invoices')->whereNull('deleted_at')
            ->groupBy('customer_id')->selectRaw('customer_id, COALESCE(SUM(total),0) as t')
            ->pluck('t', 'customer_id');
        $totalTerima = DB::table('bs_ar_payments as p')
            ->join('bs_ar_invoices as i', 'i.id', '=', 'p.ar_invoice_id')
            ->whereNull('i.deleted_at')
            ->groupBy('i.customer_id')->selectRaw('i.customer_id, COALESCE(SUM(p.amount),0) as t')
            ->pluck('t', 'customer_id');

        $saldo = [];
        foreach ($totalInvoice as $cid => $t) {
            $s = round((float) $t - (float) ($totalTerima[$cid] ?? 0), 2);
            if ($s > 0.005) {   // lunas & lebih bayar tidak dianggap "punya saldo"
                $saldo[(int) $cid] = $s;
            }
        }

        $saldoOnly = $request->boolean('saldo');
        // Customer yang sedang dibuka tetap ditahan di daftar walau saldonya nol — kalau tidak,
        // kotak pilihan jadi kosong padahal kartunya masih tampil di bawah.
        $idFilter = $customerId ? array_unique([...array_keys($saldo), $customerId]) : array_keys($saldo);

        $customers = Customer::when($saldoOnly, fn ($q) => $q->whereIn('id', $idFilter))
            ->orderBy('full_name')->limit(500)->get(['id', 'code', 'full_name'])
            ->map(fn ($c) => [
                'id' => $c->id, 'code' => $c->code, 'full_name' => $c->full_name,
                'balance' => $saldo[(int) $c->id] ?? 0.0,
            ])->values();

        return Inertia::render('BusinessSupport/Receivable/Card', [
            'customers'  => $customers,
            'customerId' => $customerId,
            'entries'    => $entries,
            'outstanding'=> $entries->isNotEmpty() ? $entries->last()['balance'] : 0,
            'saldoOnly'  => $saldoOnly,
            'withBalance'=> count($saldo),
            'totalCustomer' => Customer::count(),
        ]);
    }

    private function accId(string $key): ?int
    {
        $code = config("business_support.gl_accounts.$key");

        return $code ? BsAccount::where('code', $code)->value('id') : null;
    }
}
