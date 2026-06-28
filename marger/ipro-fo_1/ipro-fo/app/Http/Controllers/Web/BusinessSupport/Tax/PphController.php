<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Tax;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsArInvoice;
use App\Domain\BusinessSupport\Models\BsPayrollRun;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rekap PPh (withholding) untuk SPT Masa PPh — pemotongan yang menjadi kewajiban perusahaan.
 *   PPh 21      (gaji)        → akun 20104040402, sumber Payroll + AP
 *   PPh 23      (jasa)        → akun 20104040403, sumber AP
 *   PPh 4 ayat 2 (final)      → akun 20104040401, sumber AP
 *   PPh 25/29                 → akun 20104040404, sumber AP
 *   PPh Dibayar Dimuka (kredit pajak, dipungut pelanggan) → 1010502xx, sumber AR (informasi)
 */
class PphController extends Controller
{
    private const ARTICLES = [
        '20104040402' => 'PPh Pasal 21',
        '20104040403' => 'PPh Pasal 23',
        '20104040401' => 'PPh Pasal 4(2)',
        '20104040404' => 'PPh Pasal 25/29',
    ];

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('tax'), 403);
        [$year, $month] = $this->period($request);

        // Peta akun id → kode untuk klasifikasi pasal.
        $codeById = BsAccount::whereIn('code', array_keys(self::ARTICLES))->pluck('code', 'id');

        // Sumber AP (withholding atas pembayaran ke supplier).
        $apRows = BsApInvoice::with('supplier:id,code,name')->where('pph_amount', '>', 0)
            ->whereYear('invoice_date', $year)->whereMonth('invoice_date', $month)
            ->orderBy('invoice_date')->get()
            ->map(function ($i) use ($codeById) {
                $code = $codeById[$i->pph_account_id] ?? '20104040403';
                return [
                    'source' => 'AP', 'date' => $i->invoice_date?->format('Y-m-d'), 'partner' => $i->supplier?->name ?? '—',
                    'doc_no' => $i->invoice_number, 'article' => self::ARTICLES[$code] ?? 'PPh Pasal 23',
                    'base' => (float) $i->dpp, 'pph' => (float) $i->pph_amount,
                ];
            });

        // Sumber Payroll (PPh 21 dipotong dari gaji).
        $payRows = BsPayrollRun::where('period_year', $year)->where('period_month', $month)
            ->withSum('lines as pph21_total', 'pph21')->get()
            ->filter(fn ($r) => (float) $r->pph21_total > 0)
            ->map(fn ($r) => [
                'source' => 'Payroll', 'date' => $r->run_date?->format('Y-m-d'), 'partner' => 'Karyawan ('.$r->run_number.')',
                'doc_no' => $r->run_number, 'article' => 'PPh Pasal 21', 'base' => (float) $r->total_bruto, 'pph' => (float) $r->pph21_total,
            ]);

        $rows = $apRows->concat($payRows)->sortBy('date')->values();

        // Total per pasal.
        $byArticle = [];
        foreach (self::ARTICLES as $label) {
            $byArticle[$label] = round($rows->where('article', $label)->sum('pph'), 2);
        }

        // PPh Dibayar Dimuka (kredit pajak) dari AR — dipungut pelanggan.
        $prepaid = (float) BsArInvoice::where('pph_amount', '>', 0)->whereYear('invoice_date', $year)->whereMonth('invoice_date', $month)->sum('pph_amount');

        // Cross-check GL: kredit (terutang) mutasi akun Hutang PPh masa ini.
        $gl = [];
        foreach (self::ARTICLES as $code => $label) {
            $gl[$label] = $this->accCreditMutation($code, $year, $month);
        }

        return Inertia::render('BusinessSupport/Tax/Pph', [
            'period'    => ['year' => $year, 'month' => $month],
            'rows'      => $rows,
            'byArticle' => $byArticle,
            'totalTerutang' => round(array_sum($byArticle), 2),
            'prepaid'   => round($prepaid, 2),
            'gl'        => $gl,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->canModule('tax'), 403);
        [$year, $month] = $this->period($request);
        $codeById = BsAccount::whereIn('code', array_keys(self::ARTICLES))->pluck('code', 'id');

        $ap = BsApInvoice::with('supplier:id,name')->where('pph_amount', '>', 0)
            ->whereYear('invoice_date', $year)->whereMonth('invoice_date', $month)->orderBy('invoice_date')->get()
            ->map(fn ($i) => ['AP', $i->invoice_date?->format('Y-m-d'), $i->supplier?->name, $i->invoice_number,
                self::ARTICLES[$codeById[$i->pph_account_id] ?? '20104040403'] ?? 'PPh Pasal 23', (float) $i->dpp, (float) $i->pph_amount]);
        $pay = BsPayrollRun::where('period_year', $year)->where('period_month', $month)->withSum('lines as pph21_total', 'pph21')->get()
            ->filter(fn ($r) => (float) $r->pph21_total > 0)
            ->map(fn ($r) => ['Payroll', $r->run_date?->format('Y-m-d'), 'Karyawan', $r->run_number, 'PPh Pasal 21', (float) $r->total_bruto, (float) $r->pph21_total]);

        return response()->streamDownload(function () use ($ap, $pay): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['Sumber', 'Tgl', 'Lawan Transaksi', 'No Dokumen', 'Pasal', 'DPP/Bruto', 'PPh']);
            foreach ($ap->concat($pay) as $r) {
                fputcsv($out, $r);
            }
            fclose($out);
        }, "rekap-pph-{$year}-{$month}.csv", ['Content-Type' => 'text/csv']);
    }

    /** @return array{0:int,1:int} */
    private function period(Request $request): array
    {
        $year  = (int) ($request->integer('year') ?: now()->year);
        $month = (int) ($request->integer('month') ?: now()->month);

        return [$year, max(1, min(12, $month))];
    }

    private function accCreditMutation(int|string $code, int $year, int $month): float
    {
        $id = BsAccount::where('code', (string) $code)->value('id');
        if (! $id) {
            return 0.0;
        }
        $r = DB::table('bs_journal_lines')->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            ->where('bs_journals.status', 'posted')->where('bs_journal_lines.account_id', $id)
            ->whereYear('bs_journals.journal_date', $year)->whereMonth('bs_journals.journal_date', $month)
            ->selectRaw('COALESCE(SUM(credit),0) c, COALESCE(SUM(debit),0) d')->first();

        return round((float) $r->c - (float) $r->d, 2);
    }
}
