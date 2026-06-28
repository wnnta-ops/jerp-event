<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Tax;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsArInvoice;
use App\Domain\Finance\FakturPajak\Models\FakturPajak;
use App\Exports\FakturPajakMasukanExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Faktur Pajak — rekap PPN dari AR (Keluaran) & AP (Masukan) untuk SPT Masa PPN / e-Faktur.
 *   PPN Keluaran  = PPN dipungut atas penjualan (AR)  → akun 20104040406
 *   PPN Masukan   = PPN dibayar atas pembelian (AP)   → akun 10105010101
 *   PPN Kurang/(Lebih) Bayar = Keluaran − Masukan
 */
class FakturPajakController extends Controller
{
    /** Faktur Pajak Keluaran — PPN atas penjualan: AR Invoice + Penjualan Properti (FakturPajak). */
    public function keluaran(Request $request): Response
    {
        abort_unless($request->user()?->canModule('tax'), 403);
        [$year, $month] = $this->period($request);

        // Sumber 1: Invoice Piutang (AR) ber-PPN — NSFP bisa diedit.
        $arRows = BsArInvoice::with('customer:id,full_name,npwp,npwp_name')
            ->where('ppn_amount', '>', 0)
            ->whereYear('invoice_date', $year)->whereMonth('invoice_date', $month)
            ->orderBy('invoice_date')->orderBy('id')->get()
            ->map(fn ($i) => [
                'source' => 'ar', 'editable' => true, 'id' => $i->id, 'doc_no' => $i->invoice_number, 'tax_invoice_no' => $i->tax_invoice_no,
                'invoice_date' => $i->invoice_date?->format('Y-m-d'),
                'partner' => $i->customer?->npwp_name ?: $i->customer?->full_name ?? '—',
                'npwp' => $i->customer?->npwp ?: '-', 'dpp' => (float) $i->dpp, 'ppn' => (float) $i->ppn_amount,
            ]);

        // Sumber 2: Penjualan Properti (Faktur Pajak penjualan) — NSFP = fp_number (read-only).
        $propRows = FakturPajak::with('customer:id,full_name,npwp,npwp_name')
            ->where('ppn', '>', 0)
            ->whereYear('issued_at', $year)->whereMonth('issued_at', $month)
            ->orderBy('issued_at')->orderBy('id')->get()
            ->map(fn ($f) => [
                'source' => 'properti', 'editable' => false, 'id' => $f->id, 'doc_no' => $f->fp_number, 'tax_invoice_no' => $f->fp_number,
                'invoice_date' => $f->issued_at?->format('Y-m-d'),
                'partner' => $f->customer?->npwp_name ?: $f->customer?->full_name ?? '—',
                'npwp' => $f->customer?->npwp ?: '-', 'dpp' => (float) $f->dpp, 'ppn' => (float) $f->ppn,
            ]);

        $rows = $arRows->concat($propRows)->sortBy('invoice_date')->values();

        return Inertia::render('BusinessSupport/Tax/Keluaran', [
            'rows'    => $rows,
            'period'  => ['year' => $year, 'month' => $month],
            'totals'  => [
                'dpp' => round($rows->sum('dpp'), 2), 'ppn' => round($rows->sum('ppn'), 2), 'count' => $rows->count(),
                'ar_ppn' => round($arRows->sum('ppn'), 2), 'prop_ppn' => round($propRows->sum('ppn'), 2),
            ],
        ]);
    }

    /** Faktur Pajak Masukan — PPN atas pembelian (AP). */
    public function masukan(Request $request): Response
    {
        abort_unless($request->user()?->canModule('tax'), 403);
        [$year, $month] = $this->period($request);

        $rows = BsApInvoice::with('supplier:id,name,npwp')
            ->where('ppn_amount', '>', 0)
            ->whereYear('invoice_date', $year)->whereMonth('invoice_date', $month)
            ->orderBy('invoice_date')->orderBy('id')->get()
            ->map(fn ($i) => [
                'id' => $i->id, 'invoice_number' => $i->invoice_number, 'tax_invoice_no' => $i->tax_invoice_no,
                'invoice_date' => $i->invoice_date?->format('Y-m-d'), 'supplier_invoice_no' => $i->supplier_invoice_no,
                'partner' => $i->supplier?->name ?? '—', 'npwp' => $i->supplier?->npwp ?: '-',
                'dpp' => (float) $i->dpp, 'ppn' => (float) $i->ppn_amount,
            ]);

        return Inertia::render('BusinessSupport/Tax/Masukan', [
            'rows'    => $rows,
            'period'  => ['year' => $year, 'month' => $month],
            'totals'  => ['dpp' => round($rows->sum('dpp'), 2), 'ppn' => round($rows->sum('ppn'), 2), 'count' => $rows->count()],
        ]);
    }

    /** Rekap PPN periode — Keluaran − Masukan = Kurang/(Lebih) Bayar, dengan cross-check GL. */
    public function rekap(Request $request): Response
    {
        abort_unless($request->user()?->canModule('tax'), 403);
        [$year, $month] = $this->period($request);

        // PPN Keluaran = AR Invoice + Penjualan Properti (FakturPajak).
        $arPpn   = (float) BsArInvoice::where('ppn_amount', '>', 0)->whereYear('invoice_date', $year)->whereMonth('invoice_date', $month)->sum('ppn_amount');
        $arDpp   = (float) BsArInvoice::where('ppn_amount', '>', 0)->whereYear('invoice_date', $year)->whereMonth('invoice_date', $month)->sum('dpp');
        $propPpn = (float) FakturPajak::where('ppn', '>', 0)->whereYear('issued_at', $year)->whereMonth('issued_at', $month)->sum('ppn');
        $propDpp = (float) FakturPajak::where('ppn', '>', 0)->whereYear('issued_at', $year)->whereMonth('issued_at', $month)->sum('dpp');
        $keluaran = $arPpn + $propPpn;
        $dppKel   = $arDpp + $propDpp;

        $masukan  = (float) BsApInvoice::where('ppn_amount', '>', 0)->whereYear('invoice_date', $year)->whereMonth('invoice_date', $month)->sum('ppn_amount');
        $dppMas   = (float) BsApInvoice::where('ppn_amount', '>', 0)->whereYear('invoice_date', $year)->whereMonth('invoice_date', $month)->sum('dpp');

        // Cross-check GL: mutasi akun PPN periode dari jurnal posted.
        $glOut = $this->accPeriodMutation(config('business_support.gl_accounts.ppn_out'), $year, $month); // kredit − debit
        $glIn  = $this->accPeriodMutation(config('business_support.gl_accounts.ppn_in'), $year, $month, true); // debit − credit

        return Inertia::render('BusinessSupport/Tax/Rekap', [
            'period'   => ['year' => $year, 'month' => $month],
            'keluaran' => ['dpp' => round($dppKel, 2), 'ppn' => round($keluaran, 2), 'ar_ppn' => round($arPpn, 2), 'prop_ppn' => round($propPpn, 2)],
            'masukan'  => ['dpp' => round($dppMas, 2), 'ppn' => round($masukan, 2)],
            'selisih'  => round($keluaran - $masukan, 2), // + = kurang bayar (setor), − = lebih bayar (kompensasi)
            'gl'       => ['keluaran' => round($glOut, 2), 'masukan' => round($glIn, 2)],
        ]);
    }

    /** Set / ubah Nomor Seri Faktur Pajak pada invoice AR/AP. */
    public function updateNsfp(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canModule('tax'), 403);
        $v = $request->validate([
            'type'           => ['required', 'in:keluaran,masukan'],
            'invoice_id'     => ['required', 'integer'],
            'tax_invoice_no' => ['nullable', 'string', 'max:30'],
            'npwp'           => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);

        $model = $v['type'] === 'keluaran' ? BsArInvoice::findOrFail($v['invoice_id']) : BsApInvoice::findOrFail($v['invoice_id']);
        $model->update(['tax_invoice_no' => $v['tax_invoice_no'] ?: null]);

        // Untuk faktur masukan, NPWP berasal dari supplier — simpan opsional bila dikirim.
        if ($v['type'] === 'masukan' && $request->has('npwp') && $model->supplier) {
            $model->supplier->update(['npwp' => ($v['npwp'] ?? null) ?: null]);
        }

        return back()->with('success', 'Nomor Faktur Pajak diperbarui.');
    }

    /** Input massal NSFP + NPWP untuk seluruh faktur masukan pada masa pajak. */
    public function bulkNsfp(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canModule('tax'), 403);
        $v = $request->validate([
            'items'                  => ['required', 'array'],
            'items.*.invoice_id'     => ['required', 'integer'],
            'items.*.tax_invoice_no' => ['nullable', 'string', 'max:30'],
            'items.*.npwp'           => ['nullable', 'string', 'max:30'],
        ]);

        $invoiceCount = 0;
        $supplierCount = 0;

        DB::transaction(function () use ($v, &$invoiceCount, &$supplierCount): void {
            foreach ($v['items'] as $item) {
                $invoice = BsApInvoice::with('supplier')->find($item['invoice_id']);
                if (! $invoice) {
                    continue;
                }

                $invoice->update(['tax_invoice_no' => ($item['tax_invoice_no'] ?? null) ?: null]);
                $invoiceCount++;

                if (array_key_exists('npwp', $item) && $invoice->supplier) {
                    $invoice->supplier->update(['npwp' => ($item['npwp'] ?? null) ?: null]);
                    $supplierCount++;
                }
            }
        });

        return back()->with('success', "Tersimpan: {$invoiceCount} faktur, {$supplierCount} NPWP supplier diperbarui.");
    }

    public function exportMasukanXlsx(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->canModule('tax'), 403);
        [$year, $month] = $this->period($request);

        return (new FakturPajakMasukanExport($year, $month))->toResponse("faktur-masukan-{$year}-{$month}.xlsx");
    }

    public function exportKeluaran(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->canModule('tax'), 403);
        [$year, $month] = $this->period($request);

        $ar = BsArInvoice::with('customer:id,full_name,npwp,npwp_name')->where('ppn_amount', '>', 0)
            ->whereYear('invoice_date', $year)->whereMonth('invoice_date', $month)->orderBy('invoice_date')->get()
            ->map(fn ($i) => [
                $i->tax_invoice_no ?? '', $i->invoice_date?->format('Y-m-d'), 'AR Invoice', $i->customer?->npwp ?: '',
                $i->customer?->npwp_name ?: $i->customer?->full_name, $i->invoice_number, (float) $i->dpp, (float) $i->ppn_amount,
            ]);
        $prop = FakturPajak::with('customer:id,full_name,npwp,npwp_name')->where('ppn', '>', 0)
            ->whereYear('issued_at', $year)->whereMonth('issued_at', $month)->orderBy('issued_at')->get()
            ->map(fn ($f) => [
                $f->fp_number, $f->issued_at?->format('Y-m-d'), 'Penjualan Properti', $f->customer?->npwp ?: '',
                $f->customer?->npwp_name ?: $f->customer?->full_name, $f->fp_number, (float) $f->dpp, (float) $f->ppn,
            ]);

        return $this->csv("faktur-keluaran-{$year}-{$month}.csv", ['No Faktur Pajak', 'Tgl', 'Sumber', 'NPWP', 'Nama', 'No Dokumen', 'DPP', 'PPN'], $ar->concat($prop));
    }

    public function exportMasukan(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->canModule('tax'), 403);
        [$year, $month] = $this->period($request);
        $rows = BsApInvoice::with('supplier:id,name,npwp')->where('ppn_amount', '>', 0)
            ->whereYear('invoice_date', $year)->whereMonth('invoice_date', $month)->orderBy('invoice_date')->get();

        return $this->csv("faktur-masukan-{$year}-{$month}.csv", ['No Faktur Pajak', 'Tgl', 'NPWP', 'Nama', 'No Invoice Supplier', 'DPP', 'PPN'],
            $rows->map(fn ($i) => [
                $i->tax_invoice_no ?? '', $i->invoice_date?->format('Y-m-d'), $i->supplier?->npwp ?: '',
                $i->supplier?->name, $i->supplier_invoice_no ?? '', (float) $i->dpp, (float) $i->ppn_amount,
            ]));
    }

    /** @return array{0:int,1:int} */
    private function period(Request $request): array
    {
        $year  = (int) ($request->integer('year') ?: now()->year);
        $month = (int) ($request->integer('month') ?: now()->month);

        return [$year, max(1, min(12, $month))];
    }

    private function accPeriodMutation(string $code, int $year, int $month, bool $debitNormal = false): float
    {
        $accId = BsAccount::where('code', $code)->value('id');
        if (! $accId) {
            return 0.0;
        }
        $r = DB::table('bs_journal_lines')->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            ->where('bs_journals.status', 'posted')->where('bs_journal_lines.account_id', $accId)
            ->whereYear('bs_journals.journal_date', $year)->whereMonth('bs_journals.journal_date', $month)
            ->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();

        return $debitNormal ? round((float) $r->d - (float) $r->c, 2) : round((float) $r->c - (float) $r->d, 2);
    }

    /** @param \Illuminate\Support\Collection<int,array<int,mixed>> $rows */
    private function csv(string $filename, array $header, $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
