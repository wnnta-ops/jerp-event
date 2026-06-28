<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Gl;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Laporan GL lanjutan: Neraca Komparasi Tahunan, Neraca Multi Periode, Histori Saldo akun.
 */
class GlExtraReportController extends Controller
{
    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);
        $mode = in_array($request->input('mode'), ['histori', 'multi', 'tahunan', 'lr-multi', 'lr-tahunan', 'lr-banding'], true) ? $request->input('mode') : 'tahunan';
        $year = (int) ($request->integer('year') ?: now()->year);

        $data = match ($mode) {
            'histori'    => $this->histori($request, $year),
            'multi'      => $this->multi($year),
            'lr-multi'   => $this->lrMulti($year),
            'lr-tahunan' => $this->lrTahunan($year),
            'lr-banding' => $this->lrBanding($year),
            default      => $this->tahunan($year),
        };

        return Inertia::render('BusinessSupport/Gl/ExtraReports', array_merge([
            'mode'     => $mode,
            'year'     => $year,
            'accounts' => BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name']),
            'filters'  => ['account_id' => $request->integer('account_id') ?: null],
        ], $data));
    }

    /** Saldo (debit−credit) per akun s/d tanggal. @return \Illuminate\Support\Collection<int,float> */
    private function balanceMap(string $cutoff)
    {
        return DB::table('bs_journal_lines as jl')
            ->join('bs_journals as j', 'j.id', '=', 'jl.journal_id')
            ->where('j.status', 'posted')->whereDate('j.journal_date', '<=', $cutoff)
            ->groupBy('jl.account_id')
            ->selectRaw('jl.account_id, COALESCE(SUM(jl.debit-jl.credit),0) net')
            ->pluck('net', 'jl.account_id');
    }

    /** @return array<string,mixed> */
    private function tahunan(int $year): array
    {
        $prev = $this->balanceMap(sprintf('%04d-12-31', $year - 1));
        $curr = $this->balanceMap(sprintf('%04d-12-31', $year));
        $rows = BsAccount::whereIn('type', ['asset', 'liability', 'equity'])->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'normal_balance'])
            ->map(function ($a) use ($prev, $curr) {
                $sign = $a->normal_balance === 'K' ? -1 : 1;
                $pv = round(((float) ($prev[$a->id] ?? 0)) * $sign, 2);
                $cv = round(((float) ($curr[$a->id] ?? 0)) * $sign, 2);

                return ['code' => $a->code, 'label' => $a->name, 'values' => [$pv, $cv, round($cv - $pv, 2)]];
            })->filter(fn ($r) => abs($r['values'][0]) > 0.001 || abs($r['values'][1]) > 0.001)->values();

        return ['title' => "Neraca Komparasi Tahunan — {$year} vs ".($year - 1), 'rowHeader' => 'Akun', 'columns' => [(string) ($year - 1), (string) $year, 'Selisih'], 'rows' => $rows];
    }

    /** @return array<string,mixed> */
    private function multi(int $year): array
    {
        $maps = [];
        for ($m = 1; $m <= 12; $m++) {
            $maps[$m] = $this->balanceMap(date('Y-m-t', mktime(0, 0, 0, $m, 1, $year)));
        }
        $rows = BsAccount::whereIn('type', ['asset', 'liability', 'equity'])->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'normal_balance'])
            ->map(function ($a) use ($maps) {
                $sign = $a->normal_balance === 'K' ? -1 : 1;
                $vals = [];
                for ($m = 1; $m <= 12; $m++) {
                    $vals[] = round(((float) ($maps[$m][$a->id] ?? 0)) * $sign, 2);
                }

                return ['code' => $a->code, 'label' => $a->name, 'values' => $vals];
            })->filter(fn ($r) => array_sum(array_map('abs', $r['values'])) > 0.001)->values();

        return ['title' => "Neraca Multi Periode — {$year}", 'rowHeader' => 'Akun', 'columns' => self::MONTHS, 'rows' => $rows];
    }

    /** @return array<string,mixed> */
    private function histori(Request $request, int $year): array
    {
        $accountId = (int) $request->integer('account_id');
        $base = ['title' => 'Histori Saldo Akun — '.$year, 'rowHeader' => 'Bulan', 'columns' => ['Debit', 'Kredit', 'Saldo'], 'rows' => []];
        if (! $accountId) {
            return $base;
        }

        $opening = (float) DB::table('bs_journal_lines as jl')->join('bs_journals as j', 'j.id', '=', 'jl.journal_id')
            ->where('j.status', 'posted')->where('jl.account_id', $accountId)->whereDate('j.journal_date', '<', sprintf('%04d-01-01', $year))
            ->selectRaw('COALESCE(SUM(jl.debit-jl.credit),0) n')->value('n');
        $mq = DB::table('bs_journal_lines as jl')->join('bs_journals as j', 'j.id', '=', 'jl.journal_id')
            ->where('j.status', 'posted')->where('jl.account_id', $accountId)->whereYear('j.journal_date', $year)
            ->selectRaw('j.period_month m, COALESCE(SUM(jl.debit),0) d, COALESCE(SUM(jl.credit),0) c')
            ->groupBy('j.period_month')->get()->keyBy('m');

        $running = $opening;
        $rows = [['label' => 'Saldo Awal', 'values' => [null, null, round($opening, 2)]]];
        for ($m = 1; $m <= 12; $m++) {
            $d = (float) ($mq[$m]->d ?? 0);
            $c = (float) ($mq[$m]->c ?? 0);
            $running += ($d - $c);
            $rows[] = ['label' => self::MONTHS[$m - 1], 'values' => [$d, $c, round($running, 2)]];
        }
        $base['rows'] = $rows;

        return $base;
    }

    // ── LABA RUGI (aktivitas income/expense dalam periode) ─────────────────

    /** Aktivitas (debit−credit) per akun pada rentang tanggal [from..to]. */
    private function activityMap(string $from, string $to)
    {
        return DB::table('bs_journal_lines as jl')
            ->join('bs_journals as j', 'j.id', '=', 'jl.journal_id')
            ->where('j.status', 'posted')->whereBetween('j.journal_date', [$from, $to])
            ->groupBy('jl.account_id')
            ->selectRaw('jl.account_id, COALESCE(SUM(jl.debit-jl.credit),0) net')
            ->pluck('net', 'jl.account_id');
    }

    /**
     * Susun baris Laba Rugi: tiap akun income/expense ditampilkan positif,
     * lalu baris total "LABA (RUGI) BERSIH" = Σpendapatan − Σbeban per kolom.
     *
     * @param  array<int,\Illuminate\Support\Collection> $maps  satu map per kolom (account_id→net)
     * @return array<string,mixed>
     */
    private function lrAssemble(string $title, array $columns, array $maps): array
    {
        $accs = BsAccount::whereIn('type', ['income', 'expense'])->where('is_postable', true)
            ->orderBy('code')->get(['id', 'code', 'name', 'normal_balance', 'type']);

        $rows = [];
        $net = array_fill(0, count($maps), 0.0);
        foreach ($accs as $a) {
            $sign = $a->normal_balance === 'K' ? -1 : 1;
            $vals = [];
            foreach ($maps as $i => $map) {
                $v = round(((float) ($map[$a->id] ?? 0)) * $sign, 2);
                $vals[] = $v;
                $net[$i] += ($a->type === 'income' ? $v : -$v);
            }
            if (array_sum(array_map('abs', $vals)) > 0.001) {
                $rows[] = ['code' => $a->code, 'label' => $a->name, 'values' => $vals];
            }
        }
        $rows[] = ['code' => '', 'label' => 'LABA (RUGI) BERSIH', 'values' => array_map(fn ($x) => round($x, 2), $net)];

        return ['title' => $title, 'rowHeader' => 'Akun', 'columns' => $columns, 'rows' => $rows];
    }

    /** @return array<string,mixed> Laba Rugi 12 kolom bulanan. */
    private function lrMulti(int $year): array
    {
        $maps = [];
        for ($m = 1; $m <= 12; $m++) {
            $maps[] = $this->activityMap(date('Y-m-01', mktime(0, 0, 0, $m, 1, $year)), date('Y-m-t', mktime(0, 0, 0, $m, 1, $year)));
        }

        return $this->lrAssemble("Laba Rugi Multi Periode — {$year}", self::MONTHS, $maps);
    }

    /** @return array<string,mixed> Laba Rugi tahun ini vs tahun lalu + selisih. */
    private function lrTahunan(int $year): array
    {
        $maps = [
            $this->activityMap(sprintf('%04d-01-01', $year - 1), sprintf('%04d-12-31', $year - 1)),
            $this->activityMap(sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)),
        ];
        $r = $this->lrAssemble("Laba Rugi Komparasi Tahunan — {$year} vs ".($year - 1), [(string) ($year - 1), (string) $year], $maps);
        foreach ($r['rows'] as &$row) {
            $row['values'][] = round(($row['values'][1] ?? 0) - ($row['values'][0] ?? 0), 2);
        }
        unset($row);
        $r['columns'][] = 'Selisih';

        return $r;
    }

    /** @return array<string,mixed> Laba Rugi perbandingan 3 tahun (tren). */
    private function lrBanding(int $year): array
    {
        $maps = [];
        foreach ([$year - 2, $year - 1, $year] as $y) {
            $maps[] = $this->activityMap(sprintf('%04d-01-01', $y), sprintf('%04d-12-31', $y));
        }

        return $this->lrAssemble('Laba Rugi Perbandingan — '.($year - 2).' · '.($year - 1)." · {$year}", [(string) ($year - 2), (string) ($year - 1), (string) $year], $maps);
    }
}
