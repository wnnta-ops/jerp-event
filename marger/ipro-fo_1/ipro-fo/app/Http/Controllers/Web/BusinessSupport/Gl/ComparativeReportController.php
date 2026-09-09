<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Gl;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsJournalLine;
use App\Exports\SheetXlsx;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComparativeReportController extends Controller
{
    /** Neraca Lajur (worksheet 10 kolom): Neraca Saldo · Laba Rugi · Neraca. */
    public function worksheet(Request $request): Response
    {
        abort_unless($request->user()?->can('gl.worksheet.view'), 403);

        return Inertia::render('BusinessSupport/Gl/Worksheet', $this->worksheetData($request));
    }

    /** Export Neraca Lajur ke Excel (.xlsx) — tahun yang sedang ditampilkan. */
    public function worksheetExport(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('gl.worksheet.view'), 403);

        $d = $this->worksheetData($request);
        $rows = [
            ['NERACA LAJUR (WORKSHEET)'],
            ['Tahun '.$d['year']],
            [],
            // Header dua tingkat, sama seperti di layar: pasangan Debit/Kredit ada tiga,
            // dan tanpa baris pengelompokannya orang harus menghitung kolom sendiri.
            ['Kode', 'Akun', 'Neraca Saldo', '', 'Laba Rugi', '', 'Neraca', ''],
            ['', '', 'Debit', 'Kredit', 'Debit', 'Kredit', 'Debit', 'Kredit'],
        ];
        foreach ($d['rows'] as $r) {
            $rows[] = [
                $r['code'], $r['name'],
                (float) $r['ns_d'], (float) $r['ns_k'],
                (float) $r['lr_d'], (float) $r['lr_k'],
                (float) $r['nrc_d'], (float) $r['nrc_k'],
            ];
        }
        $t = $d['totals'];
        $rows[] = ['', 'JUMLAH',
            (float) $t['ns_d'], (float) $t['ns_k'],
            (float) $t['lr_d'], (float) $t['lr_k'],
            (float) $t['nrc_d'], (float) $t['nrc_k']];
        $rows[] = ['', 'LABA (RUGI) BERSIH', null, null, null, (float) $d['netProfit'], null, (float) $d['netProfit']];

        return (new SheetXlsx($rows, 5, 'Neraca Lajur', ['A4:A5', 'B4:B5', 'C4:D4', 'E4:F4', 'G4:H4']))
            ->toResponse('Neraca-Lajur-'.$d['year'].'.xlsx');
    }

    /**
     * Data Neraca Lajur — dipakai bersama layar & export.
     *
     * @return array<string,mixed>
     */
    private function worksheetData(Request $request): array
    {
        $year = (int) $request->integer('year', (int) now()->year);

        $net = BsJournalLine::query()
            ->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            ->where('bs_journals.status', 'posted')->where('bs_journals.period_year', $year)
            ->selectRaw('bs_journal_lines.account_id, COALESCE(SUM(debit-credit),0) as net')
            ->groupBy('bs_journal_lines.account_id')->pluck('net', 'account_id');

        $accs = BsAccount::whereIn('id', $net->keys())->orderBy('code')->get();
        $rows = [];
        $tot = ['ns_d' => 0.0, 'ns_k' => 0.0, 'lr_d' => 0.0, 'lr_k' => 0.0, 'nrc_d' => 0.0, 'nrc_k' => 0.0];

        foreach ($accs as $a) {
            $n = (float) $net[$a->id];
            if (abs($n) < 0.001) {
                continue;
            }
            $nsD = $n > 0 ? $n : 0.0;
            $nsK = $n < 0 ? -$n : 0.0;
            $isPL = in_array($a->type, ['income', 'expense'], true);
            $row = [
                'code' => $a->code, 'name' => $a->name, 'type' => $a->type,
                'ns_d' => $nsD, 'ns_k' => $nsK,
                'lr_d' => $isPL ? $nsD : 0.0, 'lr_k' => $isPL ? $nsK : 0.0,
                'nrc_d' => $isPL ? 0.0 : $nsD, 'nrc_k' => $isPL ? 0.0 : $nsK,
            ];
            foreach (['ns_d', 'ns_k', 'lr_d', 'lr_k', 'nrc_d', 'nrc_k'] as $k) {
                $tot[$k] += $row[$k];
            }
            $rows[] = $row;
        }

        $netProfit = $tot['lr_k'] - $tot['lr_d']; // pendapatan - beban

        return [
            'year'      => $year,
            'rows'      => $rows,
            'totals'    => $tot,
            'netProfit' => $netProfit,
        ];
    }

    /** Komparasi bulanan: Laba Rugi & Neraca per bulan dalam satu tahun. */
    public function comparative(Request $request): Response
    {
        abort_unless($request->user()?->can('gl.comparative.view'), 403);

        $year = (int) $request->integer('year', (int) now()->year);

        // Laba Rugi per bulan (aktivitas bulan tsb).
        $byMonthType = fn (string $type, bool $creditMinusDebit) => BsJournalLine::query()
            ->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            ->join('bs_accounts', 'bs_accounts.id', '=', 'bs_journal_lines.account_id')
            ->where('bs_journals.status', 'posted')->where('bs_journals.period_year', $year)
            ->where('bs_accounts.type', $type)
            ->selectRaw('bs_journals.period_month as m, COALESCE(SUM('.($creditMinusDebit ? 'credit-debit' : 'debit-credit').'),0) as v')
            ->groupBy('bs_journals.period_month')->pluck('v', 'm');

        $income  = $byMonthType('income', true);
        $expense = $byMonthType('expense', false);

        $lr = collect(range(1, 12))->map(fn ($m) => [
            'month' => $m,
            'income' => (float) ($income[$m] ?? 0),
            'expense' => (float) ($expense[$m] ?? 0),
            'laba' => (float) ($income[$m] ?? 0) - (float) ($expense[$m] ?? 0),
        ]);

        // Neraca per akhir bulan (kumulatif).
        $cumByType = function (string $type, string $cutoff) {
            $sign = in_array($type, ['asset', 'expense'], true) ? 'debit-credit' : 'credit-debit';
            return (float) BsJournalLine::query()
                ->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
                ->join('bs_accounts', 'bs_accounts.id', '=', 'bs_journal_lines.account_id')
                ->where('bs_journals.status', 'posted')->where('bs_accounts.type', $type)
                ->whereDate('bs_journals.journal_date', '<=', $cutoff)
                ->sum(DB::raw($sign));
        };
        $neraca = collect(range(1, 12))->map(function ($m) use ($year, $cumByType) {
            $cutoff = Carbon::create($year, $m, 1)->endOfMonth()->toDateString();
            return [
                'month' => $m,
                'asset' => $cumByType('asset', $cutoff),
                'liability' => $cumByType('liability', $cutoff),
                'equity' => $cumByType('equity', $cutoff),
            ];
        });

        return Inertia::render('BusinessSupport/Gl/Comparative', [
            'year'   => $year,
            'lr'     => $lr,
            'neraca' => $neraca,
            'lrTotal' => [
                'income' => $lr->sum('income'), 'expense' => $lr->sum('expense'), 'laba' => $lr->sum('laba'),
            ],
        ]);
    }
}
