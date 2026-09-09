<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Tax;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsArInvoice;
use App\Domain\BusinessSupport\Models\BsPayrollRun;
use App\Domain\Finance\FakturPajak\Models\FakturPajak;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cetak dokumen pajak (format DJP) — SPT Masa PPN (Induk 1111) & Buku Pembantu Pajak.
 * Mengembalikan HTML siap-cetak (Ctrl+P → Save as PDF), tanpa dependensi Chrome.
 */
class SptPajakController extends Controller
{
    private const PPH_ARTICLES = [
        '20104040402' => 'PPh Pasal 21',
        '20104040403' => 'PPh Pasal 23',
        '20104040401' => 'PPh Pasal 4(2)',
        '20104040404' => 'PPh Pasal 25/29',
    ];

    /** SPT Masa PPN (Formulir Induk 1111) — penyerahan, perolehan, PPN kurang/lebih bayar. */
    public function cetakPpn(Request $request): View
    {
        abort_unless($request->user()?->can('tax.print'), 403);
        [$year, $month] = $this->period($request);

        $arPpn   = (float) BsArInvoice::where('ppn_amount', '>', 0)->whereYear('invoice_date', $year)->whereMonth('invoice_date', $month)->sum('ppn_amount');
        $arDpp   = (float) BsArInvoice::where('ppn_amount', '>', 0)->whereYear('invoice_date', $year)->whereMonth('invoice_date', $month)->sum('dpp');
        $propPpn = (float) FakturPajak::where('ppn', '>', 0)->whereYear('issued_at', $year)->whereMonth('issued_at', $month)->sum('ppn');
        $propDpp = (float) FakturPajak::where('ppn', '>', 0)->whereYear('issued_at', $year)->whereMonth('issued_at', $month)->sum('dpp');
        $apPpn   = (float) BsApInvoice::where('ppn_amount', '>', 0)->whereYear('invoice_date', $year)->whereMonth('invoice_date', $month)->sum('ppn_amount');
        $apDpp   = (float) BsApInvoice::where('ppn_amount', '>', 0)->whereYear('invoice_date', $year)->whereMonth('invoice_date', $month)->sum('dpp');

        return view('print.spt-ppn', [
            'company'   => $this->company(),
            'year'      => $year, 'month' => $month, 'masaRoman' => $this->roman($month),
            'penyerahan'=> ['dpp' => round($arDpp + $propDpp, 2), 'ppn' => round($arPpn + $propPpn, 2)],
            'perolehan' => ['dpp' => round($apDpp, 2), 'ppn' => round($apPpn, 2)],
            'selisih'   => round(($arPpn + $propPpn) - $apPpn, 2),
            'breakdown' => ['ar_ppn' => round($arPpn, 2), 'prop_ppn' => round($propPpn, 2)],
        ]);
    }

    /** SPT Masa PPh — rekap pemotongan PPh 21/23/4(2)/25 per masa (sumber Payroll + AP). */
    public function cetakPph(Request $request): View
    {
        abort_unless($request->user()?->can('tax.print'), 403);
        [$year, $month] = $this->period($request);
        $codeById = BsAccount::whereIn('code', array_keys(self::PPH_ARTICLES))->pluck('code', 'id');

        $rows = [];
        BsApInvoice::with('supplier:id,name')->where('pph_amount', '>', 0)
            ->whereYear('invoice_date', $year)->whereMonth('invoice_date', $month)->orderBy('invoice_date')->get()
            ->each(function ($i) use (&$rows, $codeById) {
                $code = $codeById[$i->pph_account_id] ?? '20104040403';
                $rows[] = ['source' => 'AP', 'date' => $i->invoice_date?->format('Y-m-d'), 'partner' => $i->supplier?->name ?? '—',
                    'doc_no' => $i->invoice_number, 'article' => self::PPH_ARTICLES[$code] ?? 'PPh Pasal 23', 'base' => (float) $i->dpp, 'pph' => (float) $i->pph_amount];
            });
        BsPayrollRun::where('period_year', $year)->where('period_month', $month)->withSum('lines as pph21_total', 'pph21')->get()
            ->filter(fn ($r) => (float) $r->pph21_total > 0)
            ->each(function ($r) use (&$rows) {
                $rows[] = ['source' => 'Payroll', 'date' => $r->run_date?->format('Y-m-d'), 'partner' => 'Karyawan ('.$r->run_number.')',
                    'doc_no' => $r->run_number, 'article' => 'PPh Pasal 21', 'base' => (float) $r->total_bruto, 'pph' => (float) $r->pph21_total];
            });
        usort($rows, fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));

        $byArticle = [];
        foreach (self::PPH_ARTICLES as $label) {
            $byArticle[$label] = round(array_sum(array_map(fn ($r) => $r['article'] === $label ? $r['pph'] : 0, $rows)), 2);
        }
        $prepaid = (float) BsArInvoice::where('pph_amount', '>', 0)->whereYear('invoice_date', $year)->whereMonth('invoice_date', $month)->sum('pph_amount');

        return view('print.spt-pph', [
            'company' => $this->company(), 'year' => $year, 'month' => $month, 'masaRoman' => $this->roman($month),
            'rows' => $rows, 'byArticle' => $byArticle, 'totalTerutang' => round(array_sum($byArticle), 2), 'prepaid' => round($prepaid, 2),
        ]);
    }

    /** Buku Pembantu Pajak — kartu mutasi akun pajak (PPN Keluaran/Masukan, PPh) per masa. */
    public function bukuPembantu(Request $request): View
    {
        abort_unless($request->user()?->can('tax.print'), 403);
        [$year, $month] = $this->period($request);

        $accounts = array_merge(
            ['20104040406' => 'PPN Keluaran', '10105010101' => 'PPN Masukan'],
            self::PPH_ARTICLES,
        );

        $cards = [];
        foreach ($accounts as $code => $label) {
            $acc = BsAccount::where('code', $code)->first();
            if (! $acc) {
                continue;
            }
            // Saldo awal (sebelum masa) + mutasi masa berjalan.
            $opening = (float) DB::table('bs_journal_lines')->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
                ->where('bs_journals.status', 'posted')->where('bs_journal_lines.account_id', $acc->id)
                ->whereDate('bs_journals.journal_date', '<', sprintf('%04d-%02d-01', $year, $month))
                ->selectRaw('COALESCE(SUM(credit)-SUM(debit),0) b')->value('b');

            $lines = DB::table('bs_journal_lines as jl')->join('bs_journals as j', 'j.id', '=', 'jl.journal_id')
                ->where('j.status', 'posted')->where('jl.account_id', $acc->id)
                ->whereYear('j.journal_date', $year)->whereMonth('j.journal_date', $month)
                ->orderBy('j.journal_date')->orderBy('jl.id')
                ->get(['j.journal_date', 'j.journal_number', 'j.reference', 'jl.description', 'jl.debit', 'jl.credit']);

            $run = (float) $opening;
            $rows = [];
            foreach ($lines as $l) {
                $run += (float) $l->credit - (float) $l->debit; // akun pajak: saldo kredit normal
                $rows[] = [
                    'date' => $l->journal_date, 'no' => $l->journal_number, 'ref' => $l->reference,
                    'desc' => $l->description, 'debit' => (float) $l->debit, 'credit' => (float) $l->credit, 'balance' => round($run, 2),
                ];
            }
            $cards[] = [
                'code' => $code, 'label' => $label, 'opening' => round((float) $opening, 2),
                'rows' => $rows, 'closing' => round($run, 2),
                'total_debit' => round(array_sum(array_column($rows, 'debit')), 2),
                'total_credit' => round(array_sum(array_column($rows, 'credit')), 2),
            ];
        }

        return view('print.buku-pembantu-pajak', [
            'company' => $this->company(), 'year' => $year, 'month' => $month, 'cards' => $cards,
        ]);
    }

    /** Faktur Keluaran (AR + Properti) → list standar untuk e-Faktur. @return array<int,array<string,mixed>> */
    private function keluaranRows(int $year, int $month): array
    {
        $rows = [];
        BsArInvoice::with('customer:id,full_name,npwp,npwp_name,npwp_address,npwp_kota,npwp_postal_code')
            ->where('ppn_amount', '>', 0)->whereYear('invoice_date', $year)->whereMonth('invoice_date', $month)->get()
            ->each(function ($i) use (&$rows) {
                $rows[] = $this->mapRow($i->tax_invoice_no, $i->invoice_date, $i->customer, (float) $i->dpp, (float) $i->ppn_amount, $i->invoice_number, $i->description);
            });
        FakturPajak::with('customer:id,full_name,npwp,npwp_name,npwp_address,npwp_kota,npwp_postal_code')
            ->where('ppn', '>', 0)->whereYear('issued_at', $year)->whereMonth('issued_at', $month)->get()
            ->each(function ($f) use (&$rows) {
                $rows[] = $this->mapRow($f->fp_number, $f->issued_at, $f->customer, (float) $f->dpp, (float) $f->ppn, $f->fp_number, 'Penjualan properti');
            });

        return $rows;
    }

    /** @return array<string,mixed> */
    private function mapRow(?string $nsfp, $date, $cust, float $dpp, float $ppn, string $ref, ?string $desc): array
    {
        $digits = preg_replace('/\D/', '', (string) $nsfp);
        $serial = $digits !== '' ? substr(str_pad($digits, 13, '0', STR_PAD_LEFT), -13) : '';

        return [
            'kd_jenis' => '01', 'fg_pengganti' => '0', 'nomor_faktur' => $serial,
            'tanggal' => $date, 'npwp' => preg_replace('/\D/', '', (string) ($cust?->npwp ?: '')),
            'nama' => $cust?->npwp_name ?: ($cust?->full_name ?? 'PEMBELI'),
            'alamat' => trim(($cust?->npwp_address ?? '').' '.($cust?->npwp_kota ?? '').' '.($cust?->npwp_postal_code ?? '')) ?: '-',
            'dpp' => round($dpp, 2), 'ppn' => round($ppn, 2), 'referensi' => $ref, 'item' => $desc ?: 'Penyerahan BKP/JKP',
        ];
    }

    /** e-Faktur — CSV impor (skema FK/LT/OF) untuk Faktur Keluaran. */
    public function efakturCsv(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('tax.efaktur'), 403);
        [$year, $month] = $this->period($request);
        $rows = $this->keluaranRows($year, $month);

        return response()->streamDownload(function () use ($rows, $year, $month): void {
            $out = fopen('php://output', 'wb');
            // Baris definisi skema e-Faktur.
            fputcsv($out, ['FK', 'KD_JENIS_TRANSAKSI', 'FG_PENGGANTI', 'NOMOR_FAKTUR', 'MASA_PAJAK', 'TAHUN_PAJAK', 'TANGGAL_FAKTUR', 'NPWP', 'NAMA', 'ALAMAT_LENGKAP', 'JUMLAH_DPP', 'JUMLAH_PPN', 'JUMLAH_PPNBM', 'ID_KETERANGAN_TAMBAHAN', 'FG_UANG_MUKA', 'UANG_MUKA_DPP', 'UANG_MUKA_PPN', 'UANG_MUKA_PPNBM', 'REFERENSI']);
            fputcsv($out, ['LT', 'NPWP', 'NAMA', 'JALAN', 'BLOK', 'NOMOR', 'RT', 'RW', 'KECAMATAN', 'KELURAHAN', 'KABUPATEN', 'PROPINSI', 'KODE_POS', 'NOMOR_TELEPON']);
            fputcsv($out, ['OF', 'KODE_OBJEK', 'NAMA', 'HARGA_SATUAN', 'JUMLAH_BARANG', 'HARGA_TOTAL', 'DISKON', 'DPP', 'PPN', 'TARIF_PPNBM', 'PPNBM']);
            foreach ($rows as $r) {
                fputcsv($out, ['FK', $r['kd_jenis'], $r['fg_pengganti'], $r['nomor_faktur'], $month, $year,
                    \Illuminate\Support\Carbon::parse($r['tanggal'])->format('d/m/Y'), $r['npwp'], $r['nama'], $r['alamat'],
                    $r['dpp'], $r['ppn'], 0, '', 0, 0, 0, 0, $r['referensi']]);
                fputcsv($out, ['OF', '000000', $r['item'], $r['dpp'], 1, $r['dpp'], 0, $r['dpp'], $r['ppn'], 0, 0]);
            }
            fclose($out);
        }, "efaktur-keluaran-{$year}-{$month}.csv", ['Content-Type' => 'text/csv']);
    }

    /** e-Faktur — XML bulk (representasi) untuk Faktur Keluaran. */
    public function efakturXml(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('tax.efaktur'), 403);
        [$year, $month] = $this->period($request);
        $rows = $this->keluaranRows($year, $month);
        $company = $this->company();

        return response()->streamDownload(function () use ($rows, $year, $month, $company): void {
            $esc = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
            echo "<TaxInvoiceBulk>\n";
            echo '  <TIN>'.$esc(preg_replace('/\D/', '', $company['npwp']))."</TIN>\n";
            echo '  <MasaPajak>'.$month."</MasaPajak>\n  <TahunPajak>".$year."</TahunPajak>\n";
            echo "  <ListOfTaxInvoice>\n";
            foreach ($rows as $r) {
                echo "    <TaxInvoice>\n";
                echo '      <KodeJenisTransaksi>'.$esc($r['kd_jenis'])."</KodeJenisTransaksi>\n";
                echo '      <FgPengganti>'.$esc($r['fg_pengganti'])."</FgPengganti>\n";
                echo '      <NomorFaktur>'.$esc($r['nomor_faktur'])."</NomorFaktur>\n";
                echo '      <TanggalFaktur>'.\Illuminate\Support\Carbon::parse($r['tanggal'])->format('d/m/Y')."</TanggalFaktur>\n";
                echo '      <NPWP>'.$esc($r['npwp'])."</NPWP>\n      <Nama>".$esc($r['nama'])."</Nama>\n";
                echo '      <AlamatLengkap>'.$esc($r['alamat'])."</AlamatLengkap>\n";
                echo '      <JumlahDPP>'.number_format($r['dpp'], 2, '.', '')."</JumlahDPP>\n";
                echo '      <JumlahPPN>'.number_format($r['ppn'], 2, '.', '')."</JumlahPPN>\n";
                echo '      <JumlahPPnBM>0.00</JumlahPPnBM>'."\n";
                echo '      <Referensi>'.$esc($r['referensi'])."</Referensi>\n";
                echo "      <ListOfGoodService>\n        <GoodService>\n";
                echo '          <Nama>'.$esc($r['item'])."</Nama>\n";
                echo '          <HargaSatuan>'.number_format($r['dpp'], 2, '.', '')."</HargaSatuan>\n";
                echo '          <JumlahBarang>1</JumlahBarang>'."\n";
                echo '          <DPP>'.number_format($r['dpp'], 2, '.', '')."</DPP>\n";
                echo '          <PPN>'.number_format($r['ppn'], 2, '.', '')."</PPN>\n";
                echo "        </GoodService>\n      </ListOfGoodService>\n";
                echo "    </TaxInvoice>\n";
            }
            echo "  </ListOfTaxInvoice>\n</TaxInvoiceBulk>\n";
        }, "efaktur-keluaran-{$year}-{$month}.xml", ['Content-Type' => 'application/xml']);
    }

    /** @return array{0:int,1:int} */
    private function period(Request $request): array
    {
        $year  = (int) ($request->integer('year') ?: now()->year);
        $month = (int) ($request->integer('month') ?: now()->month);

        return [$year, max(1, min(12, $month))];
    }

    /** @return array<string,string> */
    private function company(): array
    {
        return [
            'name' => config('business_support.company.name', 'PT. MITRA USAHA PROPERTINDO'),
            'npwp' => config('business_support.company.npwp', '00.000.000.0-000.000'),
            'address' => config('business_support.company.address', 'Sidoarjo, Jawa Timur'),
        ];
    }

    private function roman(int $month): string
    {
        return ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][$month - 1] ?? (string) $month;
    }
}
