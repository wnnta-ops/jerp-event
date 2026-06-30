<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Payable;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsPurchaseOrder;
use App\Domain\BusinessSupport\Models\BsWorkOrder;
use App\Domain\BusinessSupport\Models\BsWorkOrderProgress;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Posting Invoice — terbitkan invoice hutang (AP) dari sumber PO (penerimaan)
 * atau SPK (progress). Tidak men-jurnal ulang: hutang sudah dibukukan oleh
 * penerimaan barang / progress; invoice menjadi dokumen tagihan yang dibayar via Kuitansi.
 */
class PostingInvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        $invoicedPo  = BsApInvoice::where('source_type', 'po')->pluck('source_id')->all();
        $invoicedSpk = BsApInvoice::where('source_type', 'spk')->pluck('source_id')->all();

        $pos = BsPurchaseOrder::whereIn('status', ['approved', 'closed'])->whereNotIn('id', $invoicedPo)
            ->with(['supplier:id,code,name', 'lines'])->latest('po_date')->limit(100)->get()
            ->map(fn ($po) => [
                'id' => $po->id, 'po_number' => $po->po_number, 'supplier' => $po->supplier?->name,
                'received_value' => round((float) $po->lines->sum(fn ($l) => (float) $l->qty_received * (float) $l->unit_price), 2),
            ])->filter(fn ($p) => $p['received_value'] > 0.009)->values();

        $progress = BsWorkOrderProgress::where('status', 'unpaid')->whereNotIn('id', $invoicedSpk)
            ->whereHas('workOrder')->with('workOrder.supplier:id,code,name')->latest('progress_date')->limit(100)->get()
            ->map(fn ($p) => [
                'id' => $p->id, 'spk_number' => $p->workOrder?->spk_number, 'supplier' => $p->workOrder?->supplier?->name,
                'description' => $p->description, 'net_payable' => (float) $p->net_payable,
            ]);

        // Antrian posting FO (J-ERP) — DITAMPUNG (pending) SEBELUM jadi hutang; tampil di dalam
        // section "Dari Purchase Order". Klik "Posting Invoice" → terbit hutang AP. Badge per
        // baris = SUMBER: refund (per jenis SPR/SPRS/Transfer Anonim), Komisi, atau BLT.
        $refundLabel = ['SPR' => 'SPR', 'SPRS' => 'SPRS', 'TRANSFER_ANONIM' => 'Transfer Anonim'];
        $refunds = DB::table('bs_ap_refund_queue')->where('status', 'pending')
            ->orderByDesc('id')->limit(300)->get()
            ->map(fn ($r) => [
                'id'           => $r->id,
                'refund_no'    => $r->refund_no,
                'customer'     => $r->customer_name,
                'sumber_label' => match ($r->kind) {
                    'refund' => 'Refund: '.($refundLabel[$r->refund_source] ?? (string) $r->refund_source),
                    'komisi' => 'Komisi',
                    'blt'    => 'BLT',
                    'gmc'    => 'GMC',
                    default  => (string) $r->kind,
                },
                'amount'       => (float) $r->amount,
                'tgl'          => $r->tgl_refund,
            ])->values();

        return Inertia::render('BusinessSupport/Payable/Posting', [
            'pos'      => $pos,
            'progress' => $progress,
            'refunds'  => $refunds,
        ]);
    }

    /** Terbitkan invoice hutang dari 1 baris antrian (refund/komisi/blt) → masuk Account Payable. */
    public function fromRefund(Request $request, JournalService $journal, int $queue): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        $row = DB::table('bs_ap_refund_queue')->where('id', $queue)->first();
        if (! $row || $row->status === 'posted') {
            return back()->with('error', 'Antrian tidak ditemukan atau sudah diposting.');
        }

        // Akun BO sudah di-resolve J-ERP (coa_map) saat masuk antrian; fallback payable generik.
        $payable = $row->payable_account_id ?: BsAccount::where('code', config('business_support.gl_accounts.payable'))->value('id');
        $offset = $row->offset_account_id ?: $payable;
        if (! $payable || ! $offset || (int) $payable === (int) $offset) {
            return back()->with('error', 'Akun hutang / lawan belum ter-set (cek ipro.coa_map di J-ERP).');
        }

        $amount = round((float) $row->amount, 2);
        $jenis = ['SPR' => 'SPR', 'SPRS' => 'SPRS', 'TRANSFER_ANONIM' => 'Transfer Anonim'][$row->refund_source] ?? (string) $row->refund_source;
        $desc = match ($row->kind) {
            'komisi' => 'Komisi '.$row->refund_no,
            'blt'    => 'BLT '.$row->refund_no,
            'gmc'    => 'Gimmick '.$row->refund_no.($row->customer_name ? ' — '.$row->customer_name : ''),
            default  => 'Refund '.$row->refund_no.($row->customer_name ? ' — '.$row->customer_name : '').' ('.$jenis.')',
        };
        $hutangLabel = ['komisi' => 'Hutang Komisi', 'blt' => 'Hutang BLT', 'gmc' => 'Hutang Gimmick'][$row->kind] ?? 'Hutang Refund';
        $date = $row->tgl_refund ?: now()->toDateString();

        $inv = DB::transaction(function () use ($row, $amount, $payable, $offset, $desc, $hutangLabel, $date, $journal, $request) {
            $inv = $this->makeInvoice([
                'supplier_id' => null, 'customer_id' => $row->customer_id,
                'expense_account_id' => $offset, 'payable_account_id' => $payable,
                'description' => $desc, 'invoice_date' => $date, 'receive_date' => $date, 'due_date' => $date,
                'dpp' => $amount, 'total' => $amount, 'net_payable' => $amount,
                'source_type' => $row->kind, 'source_id' => $row->refund_id, 'refund_source' => $row->refund_source,
            ], $request->user()->id);

            // Jurnal pengakuan: Dr lawan (beban/titipan) / Cr Hutang (refund/komisi/blt).
            $j = $journal->post([
                'journal_date' => $inv->invoice_date->toDateString(), 'source' => 'ap',
                'reference' => $inv->invoice_number, 'description' => 'AP — '.$desc, 'created_by' => $request->user()->id,
            ], [
                ['account_id' => $offset, 'debit' => $amount, 'credit' => 0, 'description' => $desc],
                ['account_id' => $payable, 'debit' => 0, 'credit' => $amount, 'description' => $hutangLabel],
            ]);
            $inv->update(['journal_id' => $j->id]);

            DB::table('bs_ap_refund_queue')->where('id', $row->id)
                ->update(['status' => 'posted', 'ap_invoice_id' => $inv->id, 'posted_at' => now(), 'updated_at' => now()]);

            return $inv;
        });

        return redirect()->route('bs.ap.show', $inv)->with('success', "Invoice {$inv->invoice_number} diterbitkan dari {$row->refund_no} — masuk Account Payable.");
    }

    public function fromPo(Request $request, BsPurchaseOrder $po): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        if (BsApInvoice::where('source_type', 'po')->where('source_id', $po->id)->exists()) {
            return back()->with('error', 'PO ini sudah diposting menjadi invoice.');
        }
        $po->load('lines');
        $value = round((float) $po->lines->sum(fn ($l) => (float) $l->qty_received * (float) $l->unit_price), 2);
        if ($value <= 0) {
            return back()->with('error', 'Belum ada barang diterima untuk PO ini.');
        }

        $payable = \App\Domain\BusinessSupport\Models\BsAccount::where('code', config('business_support.gl_accounts.payable'))->value('id');
        $debit   = \App\Domain\BusinessSupport\Models\BsAccount::where('code', config('business_support.gl_accounts.inventory'))->value('id');

        $inv = $this->makeInvoice([
            'supplier_id' => $po->supplier_id, 'expense_account_id' => $debit, 'payable_account_id' => $payable,
            'description' => 'Invoice dari PO '.$po->po_number, 'invoice_date' => now()->toDateString(),
            'due_date' => $po->due_date?->toDateString() ?? now()->addDays(30)->toDateString(),
            'dpp' => $value, 'total' => $value, 'net_payable' => $value,
            'source_type' => 'po', 'source_id' => $po->id,
        ], $request->user()->id);

        return redirect()->route('bs.ap.show', $inv)->with('success', "Invoice {$inv->invoice_number} diterbitkan dari PO {$po->po_number}.");
    }

    public function fromSpk(Request $request, BsWorkOrderProgress $progress): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        if ($progress->status !== 'unpaid' || BsApInvoice::where('source_type', 'spk')->where('source_id', $progress->id)->exists()) {
            return back()->with('error', 'Progress ini sudah diposting / dibayar.');
        }
        $wo = $progress->workOrder;
        $payable = \App\Domain\BusinessSupport\Models\BsAccount::where('code', config('business_support.gl_accounts.payable'))->value('id');

        $inv = $this->makeInvoice([
            'supplier_id' => $wo->supplier_id, 'expense_account_id' => $wo->construction_account_id, 'payable_account_id' => $payable,
            'category_id' => null, 'description' => 'Invoice SPK '.$wo->spk_number.' — '.$progress->description,
            'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'dpp' => (float) $progress->progress_value, 'ppn_amount' => (float) $progress->ppn_amount,
            'total' => round((float) $progress->progress_value + (float) $progress->ppn_amount, 2),
            'net_payable' => (float) $progress->net_payable, 'journal_id' => $progress->journal_id,
            'source_type' => 'spk', 'source_id' => $progress->id,
        ], $request->user()->id);

        $progress->update(['status' => 'invoiced']);

        return redirect()->route('bs.ap.show', $inv)->with('success', "Invoice {$inv->invoice_number} diterbitkan dari SPK {$wo->spk_number}.");
    }

    /** @param array<string,mixed> $data */
    private function makeInvoice(array $data, ?int $userId): BsApInvoice
    {
        return DB::transaction(fn () => BsApInvoice::create(array_merge([
            'invoice_number' => $this->nextNumber(),
            'trx_type'       => 'normal',
            'ppn_pct'        => 0, 'ppn_amount' => 0, 'ppn_mode' => 'none', 'pph_mode' => 'none', 'pph_pct' => 0, 'pph_amount' => 0,
            'paid_amount'    => 0, 'status' => 'open', 'kurs' => 1, 'created_by' => $userId,
        ], $data)));
    }

    private function nextNumber(): string
    {
        $d   = now();
        $seq = BsApInvoice::withTrashed()->whereYear('invoice_date', $d->year)->count() + 1;
        do {
            $number = sprintf('AP/%s/%04d', $d->format('y').$d->format('m'), $seq);
            $seq++;
        } while (BsApInvoice::withTrashed()->where('invoice_number', $number)->exists());

        return $number;
    }
}
