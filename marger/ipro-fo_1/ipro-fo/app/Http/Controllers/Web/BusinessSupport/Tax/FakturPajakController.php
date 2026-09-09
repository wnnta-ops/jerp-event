<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Tax;

use App\Domain\BusinessSupport\Actions\ConvertUtjDpToUangMuka;
use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsArInvoice;
use App\Domain\BusinessSupport\Models\BsFakturKeluaranEntry;
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
        abort_unless($request->user()?->can('tax.ppn-keluaran.view'), 403);
        [$year, $month] = $this->period($request);

        $rows = $this->keluaranRows($year, $month);

        return Inertia::render('BusinessSupport/Tax/Keluaran', [
            'rows'    => $rows,
            'period'  => ['year' => $year, 'month' => $month],
            'totals'  => [
                // Dihitung dari daftar gabungannya, bukan dari ketiga koleksi sumber. Nilainya
                // sama persis — tiap baris membawa 'source'-nya sendiri dan ketiga sumber itu
                // tidak pernah memakai nilai source yang sama.
                'dpp' => round($rows->sum('dpp'), 2), 'ppn' => round($rows->sum('ppn'), 2), 'count' => $rows->count(),
                'ar_ppn' => round($rows->where('source', 'ar')->sum('ppn'), 2),
                'prop_ppn' => round($rows->where('source', 'properti')->sum('ppn'), 2),
                'ret_ppn' => round($rows->where('source', 'retensi')->sum('ppn'), 2),
                'utj_ppn' => round($rows->where('source', 'utj_dp')->sum('ppn'), 2),
                'penc_ppn' => round($rows->where('source', 'pencairan')->sum('ppn'), 2),
            ],
        ]);
    }

    /**
     * Baris Faktur Pajak Keluaran satu masa pajak — gabungan TIGA sumber, terurut tanggal.
     *
     * Diangkat jadi helper supaya layar dan ekspornya membaca dari SATU sumber. Sebelumnya
     * ekspor CSV merakit ulang daftarnya sendiri dan MELEWATKAN entri Approval (retensi,
     * UTJ & DP, pencairan) — berkasnya diam-diam kekurangan baris dibanding layarnya.
     *
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    private function keluaranRows(int $year, int $month): \Illuminate\Support\Collection
    {
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

        return $arRows->concat($propRows)->concat($entryRows)->sortBy('invoice_date')->values();
    }

    /**
     * Ekspor Faktur Pajak Keluaran ke Excel.
     *
     * Bentuknya HTML bertipe vnd.ms-excel, BUKAN .xlsx seperti saudaranya Faktur Masukan:
     * XlsxExport berbasis SATU query Eloquent, sedangkan layar ini menggabungkan tiga sumber
     * (Invoice Piutang, Penjualan Properti, dan Entri Approval) lalu mengurutkannya bersama.
     * Memaksakannya ke satu query berarti menulis ulang penggabungan itu di tempat kedua.
     *
     * Saringan kolom di layar dihitung di sisi peramban dan tidak pernah masuk URL, jadi
     * berkas ini berisi SELURUH masa pajak yang dipilih. Itu dikatakan di judul lembarnya,
     * bukan didiamkan.
     */
    public function exportKeluaranXlsx(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('tax.export'), 403);
        [$year, $month] = $this->period($request);

        $rows = $this->keluaranRows($year, $month);

        $e = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES);
        // Kolom identitas dipaksa dibaca sebagai teks — tanpa itu Nomor Seri Faktur Pajak dan
        // NPWP yang panjang berubah jadi notasi ilmiah dan nol depannya hilang.
        $t = fn ($v) => '<td style="mso-number-format:\'\@\'">'.$e($v).'</td>';
        $n = fn ($v) => '<td>'.number_format((float) ($v ?? 0), 2, ',', '.').'</td>';

        $sumber = [
            'ar'        => 'Invoice Piutang (AR)',
            'properti'  => 'Penjualan Properti',
            'retensi'   => 'Approval Retensi',
            'dajam'     => 'Pelepasan DAJAM',
            'pencairan' => 'Approval Pencairan',
            'utj_dp'    => 'Approval UTJ & DP',
        ];

        $h = '<table border="1"><tr style="background:#c2410c;color:#fff">'
            .'<th>No. Faktur Pajak</th><th>Sumber</th><th>Tanggal</th><th>NPWP</th><th>Nama</th>'
            .'<th>No. Dokumen / Unit</th><th>DPP</th><th>PPN</th><th>Status Konversi</th></tr>';

        foreach ($rows as $r) {
            $konversi = ! isset($r['is_entry'])
                ? '—'
                : (($r['converted'] ?? false) ? 'Sudah dikonversi' : (($r['is_active'] ?? false) ? 'Aktif' : 'Belum dikonversi'));

            $h .= '<tr>'
                .$t($r['tax_invoice_no'])
                .$t($sumber[$r['source']] ?? $r['source'])
                .$t($r['invoice_date'])
                .$t($r['npwp'])
                .$t($r['partner'])
                .$t($r['doc_no'])
                .$n($r['dpp'])
                .$n($r['ppn'])
                .$t($konversi)
                .'</tr>';
        }
        $h .= '<tr style="font-weight:bold;background:#f1f5f9"><td colspan="6">Total ('.$rows->count().' baris)</td>'
            .$n($rows->sum('dpp')).$n($rows->sum('ppn')).'<td></td></tr></table>';

        $file = 'Faktur-Pajak-Keluaran-'.sprintf('%04d-%02d', $year, $month).'-'.now()->format('Ymd-His').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'
            .'<h3>FAKTUR PAJAK KELUARAN</h3>'
            .'<p>Masa pajak: '.sprintf('%02d/%04d', $month, $year).' — seluruh baris masa ini (saringan kolom di layar tidak diterapkan).</p>'
            .$h.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /** Faktur Pajak Masukan — PPN atas pembelian (AP). */
    public function masukan(Request $request): Response
    {
        abort_unless($request->user()?->can('tax.ppn-masukan.view'), 403);
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
        abort_unless($request->user()?->can('tax.rekap'), 403);
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
        abort_unless($request->user()?->can('tax.nsfp'), 403);
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
        abort_unless($request->user()?->can('tax.nsfp'), 403);
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
        abort_unless($request->user()?->can('tax.nsfp'), 403);
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
        abort_unless($request->user()?->can('tax.nsfp'), 403);
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
    /**
     * Konversi UTJ/DP → Uang Muka Penjualan. Logikanya dipindah ke ConvertUtjDpToUangMuka
     * agar IDENTIK dengan tombol "Setujui & Posting" di Cash Book → Approval UM Penjualan;
     * gerbang idempoten di action mencegah entri yang sama dijurnal dua kali dari dua pintu.
     */
    public function konversiUangMuka(Request $request, BsFakturKeluaranEntry $entry, ConvertUtjDpToUangMuka $action): RedirectResponse
    {
        abort_unless($request->user()?->can('tax.nsfp'), 403);

        try {
            $journal = $action->execute($entry, (int) $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "UTJ & DP dikonversi ke Uang Muka Penjualan — jurnal {$journal->journal_number} terposting. Nomor Faktur Pajak kini bisa diisi.");
    }

    public function exportMasukanXlsx(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('tax.export'), 403);
        [$year, $month] = $this->period($request);

        return (new FakturPajakMasukanExport($year, $month))->toResponse("faktur-masukan-{$year}-{$month}.xlsx");
    }

    public function exportKeluaran(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('tax.export'), 403);
        [$year, $month] = $this->period($request);

        // Dulu merakit ulang daftarnya sendiri dari DUA sumber saja, sehingga seluruh entri
        // Approval (retensi, UTJ & DP, pencairan) tidak pernah ikut — berkasnya diam-diam
        // kekurangan baris dibanding layarnya. Kini membaca daftar yang sama.
        $sumber = [
            'ar' => 'AR Invoice', 'properti' => 'Penjualan Properti', 'retensi' => 'Approval Retensi',
            'dajam' => 'Pelepasan DAJAM', 'pencairan' => 'Approval Pencairan', 'utj_dp' => 'Approval UTJ & DP',
        ];
        $rows = $this->keluaranRows($year, $month)->map(fn (array $r) => [
            $r['tax_invoice_no'] ?? '', $r['invoice_date'], $sumber[$r['source']] ?? $r['source'],
            $r['npwp'], $r['partner'], $r['doc_no'], (float) $r['dpp'], (float) $r['ppn'],
        ]);

        return $this->csv("faktur-keluaran-{$year}-{$month}.csv", ['No Faktur Pajak', 'Tgl', 'Sumber', 'NPWP', 'Nama', 'No Dokumen', 'DPP', 'PPN'], $rows);
    }

    public function exportMasukan(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('tax.export'), 403);
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
