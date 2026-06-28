<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Gl;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCashFlowMapping;
use App\Domain\BusinessSupport\Models\BsJournalLine;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Laporan Arus Kas — metode Langsung (Direct, rekonsiliasi saldo kas)
 * dan Tidak Langsung (Indirect, dari laba bersih + penyusutan + perubahan modal kerja).
 */
class CashFlowController extends Controller
{
    /** Klasifikasi akun lawan ke aktivitas arus kas berdasarkan kode CoA eProperty. */
    private function classify(string $code): string
    {
        if ($code !== '' && ($code[0] === '3' || str_starts_with($code, '20102') || str_starts_with($code, '202'))) {
            return 'financing'; // modal & utang bank
        }
        if (str_starts_with($code, '102')) {
            return 'investing'; // aset tetap
        }

        return 'operating';
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $year    = (int) $request->integer('year', (int) now()->year);
        $cashIds = BsAccount::where('is_postable', true)->where('code', 'like', '101010%')->pluck('id');

        // Saldo kas awal & perubahan tahun berjalan.
        $beginning = (float) BsJournalLine::query()
            ->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            ->where('bs_journals.status', 'posted')->whereIn('bs_journal_lines.account_id', $cashIds)
            ->whereDate('bs_journals.journal_date', '<', "{$year}-01-01")
            ->sum(DB::raw('debit-credit'));

        $cashMovement = (float) BsJournalLine::query()
            ->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            ->where('bs_journals.status', 'posted')->whereIn('bs_journal_lines.account_id', $cashIds)
            ->where('bs_journals.period_year', $year)
            ->sum(DB::raw('debit-credit'));

        // ── DIRECT: klasifikasi sisi lawan dari jurnal yang menyentuh kas ──
        $cashJournalIds = BsJournalLine::query()
            ->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            ->where('bs_journals.status', 'posted')->where('bs_journals.period_year', $year)
            ->whereIn('bs_journal_lines.account_id', $cashIds)
            ->distinct()->pluck('bs_journal_lines.journal_id');

        // Mapping akun → aktivitas (Mapping Cash Flow Direct); override heuristik bila sudah dipetakan.
        $mapActivity = BsCashFlowMapping::with('category:id,activity')->get()
            ->mapWithKeys(fn ($m) => [$m->account_id => $m->category?->activity])->filter();

        $directBuckets = ['operating' => [], 'investing' => [], 'financing' => []];
        if ($cashJournalIds->isNotEmpty()) {
            $lines = BsJournalLine::query()
                ->whereIn('journal_id', $cashJournalIds)
                ->whereNotIn('account_id', $cashIds)
                ->selectRaw('account_id, COALESCE(SUM(credit-debit),0) as flow')
                ->groupBy('account_id')->get();
            $accs = BsAccount::whereIn('id', $lines->pluck('account_id'))->get(['id', 'code', 'name'])->keyBy('id');
            foreach ($lines as $l) {
                $a = $accs[$l->account_id] ?? null;
                if (! $a || abs((float) $l->flow) < 0.001) {
                    continue;
                }
                $activity = $mapActivity[$l->account_id] ?? null;
                if (! in_array($activity, ['operating', 'investing', 'financing'], true)) {
                    $activity = $this->classify($a->code);
                }
                $directBuckets[$activity][] = ['code' => $a->code, 'name' => $a->name, 'amount' => (float) $l->flow];
            }
        }
        $sum = fn (array $b) => array_sum(array_column($b, 'amount'));
        $direct = [
            'operating' => $directBuckets['operating'], 'investing' => $directBuckets['investing'], 'financing' => $directBuckets['financing'],
            'total_operating' => $sum($directBuckets['operating']), 'total_investing' => $sum($directBuckets['investing']), 'total_financing' => $sum($directBuckets['financing']),
        ];
        $direct['net_change'] = $direct['total_operating'] + $direct['total_investing'] + $direct['total_financing'];

        // ── INDIRECT: dari laba bersih + penyusutan + perubahan modal kerja ──
        $netByType = fn (string $type) => (float) BsJournalLine::query()
            ->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            ->join('bs_accounts', 'bs_accounts.id', '=', 'bs_journal_lines.account_id')
            ->where('bs_journals.status', 'posted')->where('bs_journals.period_year', $year)
            ->where('bs_accounts.type', $type)->sum(DB::raw('debit-credit'));
        $sumPrefix = fn (string $prefix, bool $creditMinusDebit = false) => (float) BsJournalLine::query()
            ->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            ->join('bs_accounts', 'bs_accounts.id', '=', 'bs_journal_lines.account_id')
            ->where('bs_journals.status', 'posted')->where('bs_journals.period_year', $year)
            ->where('bs_accounts.code', 'like', $prefix.'%')
            ->sum(DB::raw($creditMinusDebit ? 'credit-debit' : 'debit-credit'));

        $income      = -$netByType('income');   // saldo normal kredit → positif
        $expense     = $netByType('expense');   // saldo normal debit → positif
        $netProfit   = $income - $expense;
        $depreciation = $sumPrefix('10203', true); // akumulasi penyusutan (kredit) = non-kas
        $dReceivable = $sumPrefix('10102');          // kenaikan piutang (debit) → arus keluar
        $dInventory  = $sumPrefix('10104');          // kenaikan persediaan/CIP → arus keluar
        $dPayable    = $sumPrefix('20101', true);    // kenaikan hutang (kredit) → arus masuk
        $dTaxPayable = $sumPrefix('20104', true);    // kenaikan hutang pajak → arus masuk
        $cashFromOps = $netProfit + $depreciation - $dReceivable - $dInventory + $dPayable + $dTaxPayable;
        $investing   = -$sumPrefix('102');           // belanja aset tetap (debit) → keluar
        $financingEq = $sumPrefix('3', true);        // perubahan modal
        $financingLoanShort = $sumPrefix('20102', true);
        $financingLoanLong  = $sumPrefix('202', true);
        $financing   = $financingEq + $financingLoanShort + $financingLoanLong;
        $indirect = [
            'net_profit' => $netProfit, 'depreciation' => $depreciation,
            'd_receivable' => $dReceivable, 'd_inventory' => $dInventory, 'd_payable' => $dPayable, 'd_tax_payable' => $dTaxPayable,
            'cash_from_ops' => $cashFromOps, 'investing' => $investing, 'financing' => $financing,
            'net_change' => $cashFromOps + $investing + $financing,
        ];

        return Inertia::render('BusinessSupport/Gl/CashFlow', [
            'year'      => $year,
            'beginning' => $beginning,
            'ending'    => $beginning + $cashMovement,
            'cashMovement' => $cashMovement,
            'direct'    => $direct,
            'indirect'  => $indirect,
        ]);
    }
}
