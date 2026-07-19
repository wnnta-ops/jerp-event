<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Gl;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsJournalLine;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class LedgerReportController extends Controller
{
    /** Buku Besar (General Ledger) per akun dengan saldo berjalan. */
    public function ledger(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);
        $data = $this->ledgerData($request);

        return Inertia::render('BusinessSupport/Gl/Ledger', [
            'accounts' => BsAccount::where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name']),
            'ledgers'  => $data['ledgers'],
            'filters'  => [
                'from_code' => $data['from_code'], 'to_code' => $data['to_code'],
                'from' => $data['from'], 'to' => $data['to'],
            ],
        ]);
    }

    /** Export Buku Besar (range COA) ke Excel (.xls) mengikuti filter aktif. */
    public function ledgerExport(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);
        $data = $this->ledgerData($request);
        $file = 'Buku-Besar-'.($data['from_code'] ?: 'x').'-sd-'.($data['to_code'] ?: $data['from_code'] ?: 'x').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'
            .$this->ledgerHtml($data).'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /**
     * Data Buku Besar terfilter — dipakai bersama tampilan & export.
     * Range akun postable dari kode `from_code` s/d `to_code` (salah satu boleh kosong = satu akun).
     * Tiap akun: saldo awal (sebelum `from`) + mutasi periode + saldo berjalan.
     */
    private function ledgerData(Request $request): array
    {
        $fromCode = trim((string) $request->input('from_code', ''));
        $toCode   = trim((string) $request->input('to_code', ''));
        $from = $request->date('from');
        $to   = $request->date('to');

        $ledgers = [];
        if ($fromCode !== '' || $toCode !== '') {
            $lo = $fromCode !== '' ? $fromCode : $toCode;
            $hi = $toCode !== '' ? $toCode : $fromCode;
            if (strcmp($lo, $hi) > 0) {
                [$lo, $hi] = [$hi, $lo]; // jaga urutan bila terbalik
            }
            $accounts = BsAccount::where('is_postable', true)
                ->whereBetween('code', [$lo, $hi])->orderBy('code')->get(['id', 'code', 'name']);
            foreach ($accounts as $a) {
                $l = $this->accountLedger((int) $a->id, $from, $to);
                // hanya akun yang ada mutasi ATAU saldo awal (hindari daftar akun kosong yang panjang)
                if (! empty($l['rows']) || abs($l['opening']) > 0.005) {
                    $ledgers[] = ['code' => $a->code, 'name' => $a->name] + $l;
                }
            }
        }

        return [
            'from_code' => $fromCode, 'to_code' => $toCode,
            'from' => $from?->toDateString(), 'to' => $to?->toDateString(),
            'ledgers' => $ledgers,
        ];
    }

    /** Buku besar satu akun: saldo awal + baris mutasi + total + saldo akhir. */
    private function accountLedger(int $accountId, $from, $to): array
    {
        $opening = 0.0;
        if ($from) {
            $opening = (float) BsJournalLine::where('account_id', $accountId)
                ->whereHas('journal', fn ($q) => $q->where('status', 'posted')->whereDate('journal_date', '<', $from))
                ->selectRaw('COALESCE(SUM(debit-credit),0) as b')->value('b');
        }
        $running = $opening;
        $rows = BsJournalLine::query()
            ->where('account_id', $accountId)
            ->whereHas('journal', function ($q) use ($from, $to) {
                $q->where('status', 'posted');
                if ($from) $q->whereDate('journal_date', '>=', $from);
                if ($to) $q->whereDate('journal_date', '<=', $to);
            })
            ->with('journal:id,journal_number,journal_date,description')
            ->get()
            ->sortBy(fn ($l) => [$l->journal->journal_date, $l->id])
            ->values()
            ->map(function ($l) use (&$running) {
                $running += (float) $l->debit - (float) $l->credit;

                return [
                    'date' => $l->journal->journal_date?->format('Y-m-d'),
                    'number' => $l->journal->journal_number,
                    'description' => $l->description ?: $l->journal->description,
                    'debit' => (float) $l->debit, 'credit' => (float) $l->credit, 'balance' => $running,
                ];
            });

        return [
            'opening' => $opening, 'rows' => $rows->all(),
            'total_debit' => (float) $rows->sum('debit'), 'total_credit' => (float) $rows->sum('credit'),
            'closing' => $running,
        ];
    }

    /** HTML tabel Buku Besar (per akun) untuk export Excel. */
    private function ledgerHtml(array $data): string
    {
        $rp = fn ($v) => number_format((float) $v, 0, ',', '.');
        $h = '<p><b>Buku Besar</b> — COA '.e($data['from_code'] ?: '-').' s/d '.e($data['to_code'] ?: ($data['from_code'] ?: '-'))
            .' · Periode '.e($data['from'] ?? '-').' s/d '.e($data['to'] ?? '-').'</p>';
        if (empty($data['ledgers'])) {
            return $h.'<p>Tidak ada data.</p>';
        }
        foreach ($data['ledgers'] as $l) {
            $h .= '<table border="1" cellspacing="0"><tr><td colspan="6"><b>'.e($l['code']).' — '.e($l['name']).'</b></td></tr>'
                .'<tr><th>Tanggal</th><th>No. Jurnal</th><th>Keterangan</th><th>Debit</th><th>Kredit</th><th>Saldo</th></tr>'
                .'<tr><td colspan="5">Saldo Awal</td><td align="right">'.$rp($l['opening']).'</td></tr>';
            foreach ($l['rows'] as $r) {
                $h .= '<tr><td>'.e($r['date']).'</td><td>'.e($r['number']).'</td><td>'.e($r['description']).'</td>'
                    .'<td align="right">'.($r['debit'] > 0 ? $rp($r['debit']) : '').'</td>'
                    .'<td align="right">'.($r['credit'] > 0 ? $rp($r['credit']) : '').'</td>'
                    .'<td align="right">'.$rp($r['balance']).'</td></tr>';
            }
            $h .= '<tr><td colspan="3"><b>Total</b></td><td align="right"><b>'.$rp($l['total_debit']).'</b></td>'
                .'<td align="right"><b>'.$rp($l['total_credit']).'</b></td><td align="right"><b>'.$rp($l['closing']).'</b></td></tr>'
                .'</table><br />';
        }

        return $h;
    }

    /**
     * Neraca Saldo (Trial Balance).
     * Filter: rentang tanggal (start_date..end_date) + mode akun:
     *   - 'mutation' (default) → hanya akun yang bermutasi pada periode
     *   - 'all'                → seluruh akun postable (termasuk bersaldo nol)
     */
    public function trialBalance(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $data = $this->trialBalanceData($request);

        return Inertia::render('BusinessSupport/Gl/TrialBalance', [
            'rows'         => $data['rows'],
            'total_debit'  => $data['total_debit'],
            'total_credit' => $data['total_credit'],
            'filters'      => [
                'start_date' => $request->input('start_date'),
                'end_date'   => $request->input('end_date'),
                'mode'       => $data['mode'],
            ],
        ]);
    }

    /** Export Neraca Saldo ke Excel (.xls) mengikuti filter aktif. */
    public function trialBalanceExport(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $data = $this->trialBalanceData($request);
        $file = 'Neraca-Saldo-'.$this->periodSlug($data).'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'
            .$this->trialBalanceHtml($data, false).'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /** Halaman cetak Neraca Saldo (auto print) mengikuti filter aktif. */
    public function trialBalancePrint(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $data = $this->trialBalanceData($request);

        return response('<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Neraca Saldo</title>'
            .'<style>body{font-family:Arial,Helvetica,sans-serif;margin:24px;color:#111}'
            .'h2{margin:0 0 2px;font-size:18px}p.sub{margin:0 0 16px;color:#555;font-size:12px}'
            .'table{width:100%;border-collapse:collapse;font-size:12px}'
            .'th,td{border:1px solid #cbd5e1;padding:6px 8px}th{background:#0f766e;color:#fff;text-align:left}'
            .'td.num,th.num{text-align:right}tfoot td{font-weight:bold;background:#f1f5f9}'
            .'@media print{@page{size:A4;margin:12mm}}</style></head><body onload="window.print()">'
            .$this->trialBalanceHtml($data, true).'</body></html>')
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Data Neraca Saldo terfilter — dipakai bersama tampilan, export, & cetak.
     *
     * @return array{from:?\Illuminate\Support\Carbon,to:?\Illuminate\Support\Carbon,mode:string,rows:\Illuminate\Support\Collection<int,array<string,mixed>>,total_debit:float,total_credit:float}
     */
    private function trialBalanceData(Request $request): array
    {
        $from = $request->date('start_date');
        $to   = $request->date('end_date');
        $mode = $request->input('mode') === 'all' ? 'all' : 'mutation';

        // Saldo net (Σ debit − credit) per akun dari jurnal posted pada rentang tanggal.
        $net = BsJournalLine::query()
            ->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            ->where('bs_journals.status', 'posted')
            ->when($from, fn ($q) => $q->whereDate('bs_journals.journal_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('bs_journals.journal_date', '<=', $to))
            ->selectRaw('bs_journal_lines.account_id, COALESCE(SUM(bs_journal_lines.debit - bs_journal_lines.credit),0) as net')
            ->groupBy('bs_journal_lines.account_id')
            ->pluck('net', 'account_id');

        $withLines = $net->keys()->all() ?: [0];

        // 'all' → semua akun postable + akun apa pun yang bermutasi; 'mutation' → hanya akun ber-mutasi.
        $accounts = BsAccount::query()
            ->when(
                $mode === 'all',
                fn ($q) => $q->where(fn ($w) => $w->where('is_postable', true)->orWhereIn('id', $withLines)),
                fn ($q) => $q->whereIn('id', $withLines),
            )
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type']);

        $rows = $accounts->map(function ($a) use ($net) {
            $n = (float) ($net[$a->id] ?? 0);

            return [
                'code' => $a->code, 'name' => $a->name, 'type' => $a->type,
                'debit'  => $n > 0 ? $n : 0.0,
                'credit' => $n < 0 ? -$n : 0.0,
            ];
        });

        // Mode mutasi: sembunyikan akun bersaldo nol (perilaku Neraca Saldo klasik).
        if ($mode !== 'all') {
            $rows = $rows->filter(fn ($r) => $r['debit'] != 0.0 || $r['credit'] != 0.0);
        }
        $rows = $rows->values();

        return [
            'from' => $from, 'to' => $to, 'mode' => $mode,
            'rows' => $rows,
            'total_debit'  => (float) $rows->sum('debit'),
            'total_credit' => (float) $rows->sum('credit'),
        ];
    }

    /** Tabel HTML Neraca Saldo — dipakai export .xls & halaman cetak. @param array<string,mixed> $data */
    private function trialBalanceHtml(array $data, bool $print): string
    {
        $e  = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES);
        $rp = fn ($v) => (float) $v != 0.0 ? number_format((float) $v, 2, ',', '.') : '';
        $typeLabel = ['asset' => 'Aktiva', 'liability' => 'Kewajiban', 'equity' => 'Ekuitas', 'income' => 'Pendapatan', 'expense' => 'Beban'];

        $period = ($data['from'] || $data['to'])
            ? (optional($data['from'])->toDateString() ?: '…').' s/d '.(optional($data['to'])->toDateString() ?: '…')
            : 'Semua periode';
        $modeLabel = $data['mode'] === 'all' ? 'Semua Akun' : 'Akun Bermutasi';
        $numCls = $print ? ' class="num"' : ' style="text-align:right"';

        $h = '<h2>NERACA SALDO (TRIAL BALANCE)</h2>'
            .'<p class="sub">Periode: '.$e($period).' &nbsp;&middot;&nbsp; '.$e($modeLabel).' &nbsp;&middot;&nbsp; '.$data['rows']->count().' akun</p>'
            .'<table border="1"><thead><tr>'
            .'<th>Kode</th><th>Nama Akun</th><th>Tipe</th><th'.$numCls.'>Debit</th><th'.$numCls.'>Kredit</th></tr></thead><tbody>';
        foreach ($data['rows'] as $r) {
            $h .= '<tr><td>'.$e($r['code']).'</td><td>'.$e($r['name']).'</td><td>'.$e($typeLabel[$r['type']] ?? $r['type']).'</td>'
                .'<td'.$numCls.'>'.$rp($r['debit']).'</td><td'.$numCls.'>'.$rp($r['credit']).'</td></tr>';
        }
        $h .= '</tbody><tfoot><tr><td colspan="3"'.$numCls.'>TOTAL</td>'
            .'<td'.$numCls.'>'.number_format((float) $data['total_debit'], 2, ',', '.').'</td>'
            .'<td'.$numCls.'>'.number_format((float) $data['total_credit'], 2, ',', '.').'</td></tr></tfoot></table>';

        return $h;
    }

    /** Slug periode untuk nama file export. @param array<string,mixed> $data */
    private function periodSlug(array $data): string
    {
        $f = optional($data['from'])->format('Ymd');
        $t = optional($data['to'])->format('Ymd');
        if ($f && $t) {
            return $f.'-'.$t;
        }
        if ($f) {
            return 'sejak-'.$f;
        }
        if ($t) {
            return 'sd-'.$t;
        }

        return 'semua-periode';
    }

    /** Neraca (Balance Sheet) + Laba Rugi (Income Statement). */
    public function financials(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $year = $request->integer('year') ?: null;
        $b = $this->accountBalances($year);

        $byType = fn (string $type) => $b->where('type', $type)->map(fn ($x) => [
            'code' => $x['code'], 'name' => $x['name'],
            'amount' => in_array($type, ['asset', 'expense']) ? $x['net'] : -$x['net'], // tampilkan positif sesuai sifat
        ])->filter(fn ($x) => abs($x['amount']) > 0.001)->values();

        $income  = $byType('income');
        $expense = $byType('expense');
        $totalIncome = $income->sum('amount');
        $totalExpense = $expense->sum('amount');
        $netProfit = $totalIncome - $totalExpense;

        $assets = $byType('asset');
        $liabilities = $byType('liability');
        $equity = $byType('equity');
        $totalAssets = $assets->sum('amount');
        $totalLiab = $liabilities->sum('amount');
        $totalEquity = $equity->sum('amount');

        return Inertia::render('BusinessSupport/Gl/Financials', [
            'year' => $year,
            'incomeStatement' => [
                'income' => $income, 'expense' => $expense,
                'total_income' => $totalIncome, 'total_expense' => $totalExpense, 'net_profit' => $netProfit,
            ],
            'balanceSheet' => [
                'assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity,
                'total_assets' => $totalAssets, 'total_liabilities' => $totalLiab,
                'total_equity' => $totalEquity, 'net_profit' => $netProfit,
                'total_liab_equity' => $totalLiab + $totalEquity + $netProfit,
            ],
        ]);
    }

    /**
     * Neraca (Balance Sheet) — bentuk skontro terstruktur:
     * Aset (kiri) vs Kewajiban & Ekuitas (kanan), berkelompok Sub Kategori (L2) → Ledger (L3) + sub-total.
     */
    public function balanceSheet(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $year  = $request->integer('year') ?: null;
        $leaf  = $this->accountBalances($year);
        $nodes = BsAccount::whereIn('level', [2, 3])->orderBy('code')->get(['code', 'name', 'level']);

        $sum = fn (string $prefix) => (float) $leaf->reduce(
            fn ($c, $x) => $c + (str_starts_with((string) $x['code'], $prefix) ? (float) $x['net'] : 0.0),
            0.0,
        );

        // Bangun sisi neraca per kategori: grup L2 (sub-kategori) berisi baris L3 (ledger) + sub-total.
        $side = function (array $cats, bool $creditPositive) use ($nodes, $sum): array {
            $sign   = $creditPositive ? -1.0 : 1.0;
            $groups = [];
            foreach ($nodes as $l2) {
                if ($l2['level'] !== 2 || ! in_array($l2['code'][0], $cats, true)) {
                    continue;
                }
                $lines = [];
                foreach ($nodes as $l3) {
                    if ($l3['level'] !== 3 || ! str_starts_with($l3['code'], $l2['code'])) {
                        continue;
                    }
                    $amt = $sign * $sum($l3['code']);
                    if (abs($amt) > 0.001) {
                        $lines[] = ['name' => $l3['name'], 'amount' => $amt];
                    }
                }
                $total = $sign * $sum($l2['code']);
                if (abs($total) > 0.001 || $lines !== []) {
                    $groups[] = ['name' => $l2['name'], 'code' => $l2['code'], 'total' => $total, 'lines' => $lines];
                }
            }

            return $groups;
        };

        $assets      = $side(['1'], false);
        $liabilities = $side(['2'], true);
        $equity      = $side(['3', '8'], true);

        // Laba/Rugi periode berjalan = -(Σ net akun pendapatan+beban); disuntikkan ke grup MODAL (301).
        $netProfit = -1.0 * (float) $leaf->filter(fn ($x) => in_array($x['type'], ['income', 'expense'], true))->sum('net');
        $injected  = false;
        foreach ($equity as $i => $g) {
            if (str_starts_with($g['code'], '301')) {
                $equity[$i]['lines'][] = ['name' => 'LABA/RUGI PERIODE BERJALAN', 'amount' => $netProfit];
                $equity[$i]['total'] += $netProfit;
                $injected = true;
                break;
            }
        }
        if (! $injected && abs($netProfit) > 0.001) {
            $equity[] = ['name' => 'MODAL DITEMPATKAN/DISETOR', 'code' => '301', 'total' => $netProfit,
                'lines' => [['name' => 'LABA/RUGI PERIODE BERJALAN', 'amount' => $netProfit]]];
        }

        $grand       = fn (array $g) => (float) array_sum(array_map(fn ($x) => $x['total'], $g));
        $totalAssets = $grand($assets);

        return Inertia::render('BusinessSupport/Gl/BalanceSheet', [
            'company'       => $this->companyName(),
            'as_of'         => now()->translatedFormat('d F Y'),
            'assets'        => $assets,
            'liabilities'   => $liabilities,
            'equity'        => $equity,
            'total_assets'  => $totalAssets,
            'total_passiva' => $grand($liabilities) + $grand($equity),
        ]);
    }

    /** Laporan Laba Rugi (Income Statement) — berkategori sesuai format eProperty. */
    public function incomeStatement(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $year  = $request->integer('year') ?: null;
        $leaf  = $this->accountBalances($year);
        $nodes = BsAccount::where('level', 3)->orderBy('code')->get(['code', 'name']);

        $sum = fn (string $prefix) => (float) $leaf->reduce(
            fn ($c, $x) => $c + (str_starts_with((string) $x['code'], $prefix) ? (float) $x['net'] : 0.0),
            0.0,
        );
        $linesUnder = function (array $prefixes, bool $creditPositive) use ($nodes, $sum): array {
            $sign = $creditPositive ? -1.0 : 1.0;
            $out  = [];
            foreach ($nodes as $n) {
                foreach ($prefixes as $p) {
                    if (str_starts_with($n['code'], $p)) {
                        $amt = $sign * $sum($n['code']);
                        if (abs($amt) > 0.001) {
                            $out[] = ['name' => $n['name'], 'amount' => $amt];
                        }
                        break;
                    }
                }
            }

            return $out;
        };

        $penjualan = -1.0 * $sum('401');
        $hpp       = $sum('501');
        $gross     = $penjualan - $hpp;
        $bebanPenj = $sum('502');
        $bua       = $sum('503');
        $bebanOp   = $bebanPenj + $bua;
        $ebitda    = $gross - $bebanOp;
        $pendLain  = -1.0 * ($sum('402') + $sum('601'));
        $bebanLain = $sum('701');
        $netLain   = $pendLain - $bebanLain;
        $netProfit = $ebitda + $netLain;

        $rows = [];
        $add  = function (string $kind, string $label, ?float $amount = null) use (&$rows): void {
            $rows[] = ['kind' => $kind, 'label' => $label, 'amount' => $amount];
        };
        $addLines = function (array $prefixes, bool $creditPositive) use (&$rows, $linesUnder): void {
            foreach ($linesUnder($prefixes, $creditPositive) as $l) {
                $rows[] = ['kind' => 'line', 'label' => $l['name'], 'amount' => $l['amount']];
            }
        };

        $add('header', 'PENJUALAN');
        $add('group', 'PENDAPATAN USAHA');
        $addLines(['401'], true);
        $add('subtotal', 'JUMLAH PENJUALAN USAHA', $penjualan);
        $add('header', 'BEBAN POKOK PENJUALAN');
        $addLines(['501'], false);
        $add('subtotal', 'JUMLAH BEBAN POKOK PENJUALAN', $hpp);
        $add('emphasis', 'LABA RUGI GROSS', $gross);
        $add('header', 'BEBAN PENJUALAN');
        $addLines(['502'], false);
        $add('subtotal', 'JUMLAH BEBAN PENJUALAN', $bebanPenj);
        $add('header', 'BEBAN UMUM & ADMINISTRASI (BUA)');
        $addLines(['503'], false);
        $add('subtotal', 'JUMLAH BEBAN UMUM & ADMINISTRASI', $bua);
        $add('subtotal2', 'JUMLAH BEBAN PENJUALAN & BUA', $bebanOp);
        $add('emphasis', 'EBITDA', $ebitda);
        $add('header', 'PENDAPATAN DAN BEBAN LAIN');
        $add('group', 'PENDAPATAN LAIN-LAIN');
        $addLines(['402', '601'], true);
        $add('subtotal', 'JUMLAH PENDAPATAN LAIN-LAIN', $pendLain);
        $add('group', 'BEBAN LAIN-LAIN');
        $addLines(['701'], false);
        $add('subtotal', 'JUMLAH BEBAN LAIN-LAIN', $bebanLain);
        $add('subtotal2', 'JUMLAH PENDAPATAN DAN BEBAN LAIN', $netLain);
        $add('total', 'LABA RUGI NETTO', $netProfit);

        return Inertia::render('BusinessSupport/Gl/IncomeStatement', [
            'company'    => $this->companyName(),
            'as_of'      => now()->translatedFormat('d F Y'),
            'rows'       => $rows,
            'net_profit' => $netProfit,
        ]);
    }

    /**
     * Laba Rugi vs Budget — realisasi (jurnal posted) vs budget (bs_budgets) + variance,
     * berkategori sama dengan Laporan Laba Rugi. Variance = Realisasi − Budget.
     */
    public function incomeVsBudget(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        $year = $request->integer('year') ?: (int) now()->year;

        // Realisasi: net (debit-credit) per kode dari jurnal posted pada period_year.
        $realByCode = [];
        foreach ($this->accountBalances($year) as $x) {
            $realByCode[$x['code']] = (float) $x['net'];
        }

        // Budget: jumlah tahunan per akun × arah normal (D → +, K → −) supaya sekonvensi net jurnal.
        $accts    = BsAccount::query()->get(['id', 'code', 'normal_balance']);
        $codeById = $accts->pluck('code', 'id');
        $signById = $accts->mapWithKeys(fn ($a) => [$a->id => $a->normal_balance === 'D' ? 1.0 : -1.0]);
        $budByCode = [];
        foreach (BsBudget::where('year', $year)->where('status', '!=', 'rejected')->get() as $b) {
            $code = $codeById[$b->account_id] ?? null;
            if (! $code) {
                continue;
            }
            // Total tahunan: pakai amount (eksak) bila terisi, jika tidak jumlahkan breakdown bulanan.
            $annual = abs((float) $b->amount) > 0.001 ? (float) $b->amount : (float) array_sum($b->monthsArray());
            $budByCode[$code] = ($budByCode[$code] ?? 0.0) + ($signById[$b->account_id] ?? 1.0) * $annual;
        }

        $sumR  = fn (string $p) => $this->sumPrefix($realByCode, $p);
        $sumB  = fn (string $p) => $this->sumPrefix($budByCode, $p);
        $nodes = BsAccount::where('level', 3)->orderBy('code')->get(['code', 'name']);

        // Nilai skalar per aliran (realisasi / budget) — mirror struktur Laporan Laba Rugi.
        $vals = function (callable $sum): array {
            $penjualan = -1.0 * $sum('401');
            $hpp       = $sum('501');
            $gross     = $penjualan - $hpp;
            $bebanPenj = $sum('502');
            $bua       = $sum('503');
            $bebanOp   = $bebanPenj + $bua;
            $ebitda    = $gross - $bebanOp;
            $pendLain  = -1.0 * ($sum('402') + $sum('601'));
            $bebanLain = $sum('701');
            $netLain   = $pendLain - $bebanLain;

            return [
                'penjualan' => $penjualan, 'hpp' => $hpp, 'gross' => $gross,
                'bebanPenj' => $bebanPenj, 'bua' => $bua, 'bebanOp' => $bebanOp, 'ebitda' => $ebitda,
                'pendLain' => $pendLain, 'bebanLain' => $bebanLain, 'netLain' => $netLain,
                'net' => $ebitda + $netLain,
            ];
        };
        $R = $vals($sumR);
        $B = $vals($sumB);

        // Basis common-size % = Jumlah Pendapatan Usaha per kolom.
        $baseR = $R['penjualan'];
        $baseB = $B['penjualan'];

        $rows = [];
        $push = function (string $kind, string $label, ?float $r = null, ?float $b = null) use (&$rows, $baseR, $baseB): void {
            $rows[] = [
                'kind' => $kind, 'label' => $label,
                'realisasi' => $r, 'budget' => $b,
                'variance'  => ($r === null && $b === null) ? null : (float) (($r ?? 0.0) - ($b ?? 0.0)),
                'realisasi_pct' => ($r !== null && abs($baseR) > 0.001) ? $r / $baseR * 100.0 : null,
                'budget_pct'    => ($b !== null && abs($baseB) > 0.001) ? $b / $baseB * 100.0 : null,
            ];
        };
        $pushLines = function (array $prefixes, bool $creditPositive) use ($nodes, $sumR, $sumB, $push): void {
            $sign = $creditPositive ? -1.0 : 1.0;
            foreach ($nodes as $n) {
                foreach ($prefixes as $p) {
                    if (str_starts_with($n['code'], $p)) {
                        $r = $sign * $sumR($n['code']);
                        $b = $sign * $sumB($n['code']);
                        if (abs($r) > 0.001 || abs($b) > 0.001) {
                            $push('line', $n['name'], $r, $b);
                        }
                        break;
                    }
                }
            }
        };

        $push('header', 'PENDAPATAN');
        $push('group', 'PENDAPATAN USAHA');
        $pushLines(['401'], true);
        $push('subtotal', 'JUMLAH PENDAPATAN USAHA', $R['penjualan'], $B['penjualan']);
        $push('header', 'BEBAN POKOK PENDAPATAN');
        $pushLines(['501'], false);
        $push('subtotal', 'JUMLAH BEBAN POKOK PENDAPATAN', $R['hpp'], $B['hpp']);
        $push('emphasis', 'LABA RUGI GROSS', $R['gross'], $B['gross']);
        $push('header', 'BEBAN PENJUALAN');
        $pushLines(['502'], false);
        $push('subtotal', 'JUMLAH BEBAN PENJUALAN', $R['bebanPenj'], $B['bebanPenj']);
        $push('header', 'BEBAN UMUM & ADMINISTRASI (BUA)');
        $pushLines(['503'], false);
        $push('subtotal', 'JUMLAH BEBAN UMUM & ADMINISTRASI', $R['bua'], $B['bua']);
        $push('subtotal2', 'JUMLAH BEBAN PENJUALAN & BUA', $R['bebanOp'], $B['bebanOp']);
        $push('emphasis', 'EBITDA', $R['ebitda'], $B['ebitda']);
        $push('header', 'PENDAPATAN DAN BEBAN LAIN');
        $push('group', 'PENDAPATAN LAIN-LAIN');
        $pushLines(['402', '601'], true);
        $push('subtotal', 'JUMLAH PENDAPATAN LAIN-LAIN', $R['pendLain'], $B['pendLain']);
        $push('group', 'BEBAN LAIN-LAIN');
        $pushLines(['701'], false);
        $push('subtotal', 'JUMLAH BEBAN LAIN-LAIN', $R['bebanLain'], $B['bebanLain']);
        $push('subtotal2', 'JUMLAH PENDAPATAN DAN BEBAN LAIN', $R['netLain'], $B['netLain']);
        $push('total', 'LABA RUGI NETTO', $R['net'], $B['net']);

        return Inertia::render('BusinessSupport/Gl/IncomeVsBudget', [
            'company'    => $this->companyName(),
            'year'       => $year,
            'as_of'      => now()->translatedFormat('d F Y'),
            'rows'       => $rows,
            'totals'     => ['realisasi' => $R['net'], 'budget' => $B['net'], 'variance' => $R['net'] - $B['net']],
            'has_budget' => array_sum(array_map('abs', $budByCode)) > 0.001,
        ]);
    }

    /** Σ nilai di $map untuk kode berawalan $prefix. @param array<string,float> $map */
    private function sumPrefix(array $map, string $prefix): float
    {
        $s = 0.0;
        foreach ($map as $code => $v) {
            if (str_starts_with((string) $code, $prefix)) {
                $s += (float) $v;
            }
        }

        return $s;
    }

    /** Nama perusahaan (tenant) untuk kop laporan. */
    private function companyName(): string
    {
        return (string) (\App\Domain\Foundation\Tenant\Models\Tenant::query()->orderBy('id')->value('name')
            ?: 'PT Mitra Usaha Propertindo');
    }

    /** Saldo per akun = Σ(debit-credit). @return \Illuminate\Support\Collection<int,array<string,mixed>> */
    private function accountBalances(?int $year)
    {
        $lines = BsJournalLine::query()
            ->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
            ->where('bs_journals.status', 'posted')
            ->when($year, fn ($q) => $q->where('bs_journals.period_year', $year))
            ->selectRaw('bs_journal_lines.account_id, COALESCE(SUM(debit-credit),0) as net')
            ->groupBy('bs_journal_lines.account_id')
            ->pluck('net', 'account_id');

        return BsAccount::whereIn('id', $lines->keys())->orderBy('code')->get()
            ->map(fn ($a) => ['code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'net' => (float) $lines[$a->id]]);
    }
}
