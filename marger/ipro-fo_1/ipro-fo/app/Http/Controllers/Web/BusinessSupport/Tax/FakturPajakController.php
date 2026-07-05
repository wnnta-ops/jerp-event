<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Tax;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsArInvoice;
use App\Domain\BusinessSupport\Models\BsFakturKeluaranEntry;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Domain\Finance\FakturPajak\Models\FakturPajak;
use App\Exceptions\BusinessRuleException;
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

        // Sumber 3: Entri dari Approval Retensi & Approval UTJ & DP (keterangan Retensi / UTJ & DP).
        //   - Retensi  → editable (boleh isi Nomor Faktur Pajak).
        //   - UTJ & DP → editable HANYA setelah "Konversi ke Uang Muka Penjualan".
        $entryRows = BsFakturKeluaranEntry::with(['customer:id,full_name,npwp,npwp_name', 'booking.unit:id,unit_code'])
            ->whereYear('doc_date', $year)->whereMonth('doc_date', $month)
            ->orderBy('doc_date')->orderBy('id')->get()
            ->map(fn ($e) => [
                'source' => $e->source_type, 'editable' => (bool) $e->is_active, 'is_entry' => true,
                'id' => $e->id, 'doc_no' => $e->booking?->unit?->unit_code ?: $e->keterangan, 'tax_invoice_no' => $e->fp_number,
                'invoice_date' => $e->doc_date?->format('Y-m-d'),
                'partner' => $e->customer?->npwp_name ?: ($e->customer?->full_name ?? '—'),
                'npwp' => $e->customer?->npwp ?: '-', 'dpp' => (float) $e->dpp, 'ppn' => (float) $e->ppn,
                'keterangan' => $e->keterangan, 'is_active' => (bool) $e->is_active,
                'converted' => $e->converted_at !== null, 'unit' => $e->booking?->unit?->unit_code,
            ]);

        $rows = $arRows->concat($propRows)->concat($entryRows)->sortBy('invoice_date')->values();

        return Inertia::render('BusinessSupport/Tax/Keluaran', [
            'rows'    => $rows,
            'period'  => ['year' => $year, 'month' => $month],
            'totals'  => [
                'dpp' => round($rows->sum('dpp'), 2), 'ppn' => round($rows->sum('ppn'), 2), 'count' => $rows->count(),
                'ar_ppn' => round($arRows->sum('ppn'), 2), 'prop_ppn' => round($propRows->sum('ppn'), 2),
                'ret_ppn' => round($entryRows->where('source', 'retensi')->sum('ppn'), 2),
                'utj_ppn' => round($entryRows->where('source', 'utj_dp')->sum('ppn'), 2),
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

    /** Set / ubah Nomor Faktur Pajak pada satu entri Retensi / UTJ & DP (harus sudah aktif). */
    public function updateEntryNsfp(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canModule('tax'), 403);
        $v = $request->validate([
            'entry_id'       => ['required', 'integer'],
            'tax_invoice_no' => ['nullable', 'string', 'max:30'],
        ]);

        $entry = BsFakturKeluaranEntry::findOrFail($v['entry_id']);
        if (! $entry->is_active) {
            return back()->with('error', 'Nomor Faktur Pajak belum bisa diisi — konversi UTJ & DP ke Uang Muka Penjualan terlebih dahulu.');
        }
        $entry->update(['fp_number' => $v['tax_invoice_no'] ?: null]);

        return back()->with('success', 'Nomor Faktur Pajak diperbarui.');
    }

    /** Simpan Nomor Faktur Pajak massal untuk baris terpilih (AR Invoice & entri Retensi/UTJ). */
    public function bulkKeluaranNsfp(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canModule('tax'), 403);
        $v = $request->validate([
            'items'                  => ['required', 'array', 'min:1'],
            'items.*.type'           => ['required', 'in:ar,entry'],
            'items.*.id'             => ['required', 'integer'],
            'items.*.tax_invoice_no' => ['nullable', 'string', 'max:30'],
        ]);

        $saved = 0;
        $skipped = 0;
        DB::transaction(function () use ($v, &$saved, &$skipped): void {
            foreach ($v['items'] as $item) {
                $no = ($item['tax_invoice_no'] ?? null) ?: null;
                if ($item['type'] === 'ar') {
                    $inv = BsArInvoice::find($item['id']);
                    if ($inv) {
                        $inv->update(['tax_invoice_no' => $no]);
                        $saved++;
                    }

                    continue;
                }
                $entry = BsFakturKeluaranEntry::find($item['id']);
                if (! $entry) {
                    continue;
                }
                if (! $entry->is_active) {
                    $skipped++; // UTJ & DP yang belum dikonversi tidak bisa diisi

                    continue;
                }
                $entry->update(['fp_number' => $no]);
                $saved++;
            }
        });

        $msg = "Tersimpan: {$saved} Nomor Faktur Pajak.";
        if ($skipped > 0) {
            $msg .= " {$skipped} entri UTJ & DP dilewati (belum dikonversi ke Uang Muka Penjualan).";
        }

        return back()->with($skipped > 0 ? 'error' : 'success', $msg);
    }

    /**
     * Konversi entri UTJ & DP → Uang Muka Penjualan.
     * Membentuk jurnal reklasifikasi (Dr Hutang Usaha / Cr Uang Muka Penjualan + PPN Keluaran)
     * yang otomatis tersinkron ke General Ledger, lalu mengaktifkan pengisian Nomor Faktur Pajak.
     */
    public function konversiUangMuka(Request $request, BsFakturKeluaranEntry $entry, JournalService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('tax'), 403);

        if ($entry->source_type !== 'utj_dp') {
            return back()->with('error', 'Konversi hanya berlaku untuk entri UTJ & DP.');
        }
        if ($entry->converted_at) {
            return back()->with('error', 'Entri ini sudah dikonversi ke Uang Muka Penjualan.');
        }
        $amount = round((float) $entry->total, 2);
        if ($amount <= 0) {
            return back()->with('error', 'Nominal entri tidak valid.');
        }

        // PPN inklusif 11%: PPN Keluaran = amount * 11/111; Uang Muka = sisa.
        $ppn      = round($amount * 11 / 111, 2);
        $uangMuka = round($amount - $ppn, 2);

        $entry->loadMissing(['journal.lines', 'booking.unit:id,unit_code', 'customer:id,full_name']);
        // Debit = akun yang dulu dikredit saat approval (umumnya Hutang Usaha); fallback by name/config.
        $hutangId   = optional($entry->journal?->lines->first(fn ($l) => (float) $l->credit > 0))->account_id
            ?? $this->resolveAccountId('hutang usaha', 'payable');
        $uangMukaId = $this->resolveAccountId('uang muka penjualan', 'unearned_revenue');
        $ppnId      = $this->resolveAccountId('ppn keluaran', 'ppn_out');

        if (! $hutangId || ! $uangMukaId) {
            return back()->with('error', 'Akun Hutang Usaha atau Uang Muka Penjualan belum tersedia di CoA.');
        }
        if ((int) $hutangId === (int) $uangMukaId) {
            return back()->with('error', 'Akun debit (Hutang Usaha) tidak boleh sama dengan Uang Muka Penjualan.');
        }

        $suffix = '';
        if ($entry->customer?->full_name) {
            $suffix .= ' · '.$entry->customer->full_name;
        }
        if ($entry->booking?->unit?->unit_code) {
            $suffix .= ' · Unit '.$entry->booking->unit->unit_code;
        }
        $desc = 'Konversi UTJ/DP ke Uang Muka Penjualan'.$suffix;

        $lines = [['account_id' => (int) $hutangId, 'debit' => $amount, 'credit' => 0, 'description' => $desc]];
        if ($ppnId && $ppn > 0 && (int) $ppnId !== (int) $hutangId) {
            $lines[] = ['account_id' => (int) $uangMukaId, 'debit' => 0, 'credit' => $uangMuka, 'description' => 'Uang Muka Penjualan'.$suffix];
            $lines[] = ['account_id' => (int) $ppnId, 'debit' => 0, 'credit' => $ppn, 'description' => 'PPN Keluaran'.$suffix];
        } else {
            // Tanpa akun PPN valid: seluruh nominal masuk Uang Muka (jaga jurnal tetap balance).
            $uangMuka = $amount;
            $ppn      = 0.0;
            $lines[]  = ['account_id' => (int) $uangMukaId, 'debit' => 0, 'credit' => $amount, 'description' => 'Uang Muka Penjualan'.$suffix];
        }

        try {
            $journal = $svc->post([
                'journal_date' => now()->toDateString(),
                'source'       => 'cash',
                'status'       => 'posted',
                'reference'    => 'KONVERSI-UTJDP-'.$entry->id,
                'description'  => $desc,
                'created_by'   => $request->user()->id,
            ], $lines);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        $entry->update([
            'dpp'                => $uangMuka,
            'ppn'                => $ppn,
            'is_active'          => true,
            'converted_at'       => now(),
            'convert_journal_id' => $journal->id,
        ]);

        return back()->with('success', "UTJ & DP dikonversi ke Uang Muka Penjualan — jurnal {$journal->journal_number} terposting. Nomor Faktur Pajak kini bisa diisi.");
    }

    /** Cari id akun postable berdasarkan NAMA (mengandung frasa), fallback ke kode config. */
    private function resolveAccountId(string $needle, string $configKey): ?int
    {
        $needle = mb_strtolower($needle);
        $hit = BsAccount::where('is_active', true)->where('is_postable', true)->get(['id', 'name', 'code'])
            ->first(fn ($a) => str_contains(mb_strtolower((string) $a->name), $needle));
        if ($hit) {
            return (int) $hit->id;
        }
        $code = config("business_support.gl_accounts.{$configKey}");
        $id = $code ? BsAccount::where('code', $code)->value('id') : null;

        return $id ? (int) $id : null;
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
