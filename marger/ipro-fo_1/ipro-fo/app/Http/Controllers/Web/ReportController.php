<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Reports\ReportRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    private const MONTHS_ID = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    /** Katalog laporan (dikelompokkan per modul). */
    public function index(Request $request): Response
    {
        $this->authorizeReports();

        $reports = collect(ReportRegistry::all())->map(fn ($d, $key) => [
            'key'    => $key,
            'label'  => $d['label'],
            'group'  => $d['group'],
            'hasAmount' => ! empty($d['amount']),
        ])->values();

        return Inertia::render('Reports/Index', [
            'reports' => $reports,
            'groups'  => $reports->pluck('group')->unique()->values(),
        ]);
    }

    /** Laporan DETAIL — tabel ter-filter + pagination. */
    public function detail(string $key, Request $request): Response
    {
        $this->authorizeReports();
        $def = $this->def($key);

        $query = $this->buildQuery($def, $request);
        $query->select($this->selects($def));
        $this->orderByDate($query, $def);

        $rows = $query->paginate((int) $request->integer('per_page', 50))->withQueryString();

        return Inertia::render('Reports/Detail', [
            'report'  => ['key' => $key, 'label' => $def['label'], 'group' => $def['group'], 'hasStatus' => ! empty($def['status'])],
            'columns' => $this->columnsMeta($def),
            'rows'    => $rows,
            'filters' => $this->filterState($request),
            'statuses' => $this->distinctStatuses($def),
        ]);
    }

    /** Laporan SUMMARY — KPI + distribusi status + tren bulanan. */
    public function summary(string $key, Request $request): Response
    {
        $this->authorizeReports();
        $def = $this->def($key);

        $base = fn (): Builder => $this->buildQuery($def, $request);

        $total = (clone $base())->count();
        $amountTotal = $def['amount'] ? (float) (clone $base())->sum($def['table'].'.'.$def['amount']) : null;

        // Distribusi status
        $statusDist = [];
        if ($def['status'] && Schema::hasColumn($def['table'], $def['status'])) {
            $col = $def['table'].'.'.$def['status'];
            $rows = (clone $base())->select($col.' as s', DB::raw('count(*) as c'))
                ->when($def['amount'], fn ($q) => $q->addSelect(DB::raw('sum('.$def['table'].'.'.$def['amount'].') as a')))
                ->groupBy($col)->get();
            $palette = ['#ff8c00', '#10b981', '#f59e0b', '#14b8a6', '#7c3aed', '#f43f5e', '#64748b', '#84cc16', '#0ea5e9', '#e11d48'];
            foreach ($rows as $i => $r) {
                $statusDist[] = [
                    'label' => (string) ($r->s ?? '—'),
                    'value' => (int) $r->c,
                    'amount' => isset($r->a) ? (float) $r->a : null,
                    'color' => $palette[$i % count($palette)],
                ];
            }
            usort($statusDist, fn ($a, $b) => $b['value'] <=> $a['value']);
        }

        // Tren bulanan (count + amount) 6 bulan
        $trend = $this->monthlyTrend($def, $request);

        return Inertia::render('Reports/Summary', [
            'report' => ['key' => $key, 'label' => $def['label'], 'group' => $def['group'], 'hasAmount' => ! empty($def['amount'])],
            'kpi'    => ['total' => $total, 'amount_total' => $amountTotal],
            'status_dist' => $statusDist,
            'trend'  => $trend,
            'filters' => $this->filterState($request),
        ]);
    }

    /** Export CSV laporan detail (semua baris ter-filter). */
    public function export(string $key, Request $request): StreamedResponse
    {
        $this->authorizeReports();
        $def = $this->def($key);

        $query = $this->buildQuery($def, $request);
        $query->select($this->selects($def));
        $this->orderByDate($query, $def);

        $meta = $this->columnsMeta($def);
        $filename = "report-{$key}-".now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query, $meta): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM (Excel)
            fputcsv($out, array_column($meta, 'label'), ',', '"', '');

            foreach ($query->cursor() as $row) {
                $line = [];
                foreach ($meta as $m) {
                    $line[] = $this->fmt($row->{$m['key']} ?? null, $m['type']);
                }
                fputcsv($out, $line, ',', '"', '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ── Helper ────────────────────────────────────────────────

    private function authorizeReports(): void
    {
        abort_unless(auth()->user()?->can('reporting.report.export'), 403);
    }

    /** @return array<string,mixed> */
    private function def(string $key): array
    {
        $def = ReportRegistry::get($key);
        abort_if($def === null || ! Schema::hasTable($def['table']), 404, 'Laporan tidak ditemukan.');

        return $def;
    }

    private function buildQuery(array $def, Request $request): Builder
    {
        $q = DB::table($def['table']);

        foreach ($def['joins'] ?? [] as [$jt, $local, $foreign]) {
            if (Schema::hasTable($jt)) {
                $q->leftJoin($jt, $local, '=', $foreign);
            }
        }

        if (Schema::hasColumn($def['table'], 'deleted_at')) {
            $q->whereNull($def['table'].'.deleted_at');
        }

        // Filter tanggal
        $dateCol = $def['date'];
        if ($dateCol && Schema::hasColumn($def['table'], $dateCol)) {
            $full = $def['table'].'.'.$dateCol;
            if ($request->filled('from')) {
                $q->whereDate($full, '>=', $request->input('from'));
            }
            if ($request->filled('to')) {
                $q->whereDate($full, '<=', $request->input('to'));
            }
        }

        // Filter status
        if ($request->filled('status') && $def['status'] && Schema::hasColumn($def['table'], $def['status'])) {
            $q->where($def['table'].'.'.$def['status'], $request->input('status'));
        }

        return $q;
    }

    /** @return array<int, \Illuminate\Database\Query\Expression> */
    private function selects(array $def): array
    {
        $sel = [];
        $i = 0;
        foreach ($def['columns'] as [$expr, $type]) {
            $sel[] = DB::raw($expr.' as col_'.$i);
            $i++;
        }

        return $sel;
    }

    /** @return array<int, array{label:string,type:string,key:string}> */
    private function columnsMeta(array $def): array
    {
        $meta = [];
        $i = 0;
        foreach ($def['columns'] as $label => [$expr, $type]) {
            $meta[] = ['label' => $label, 'type' => $type, 'key' => 'col_'.$i];
            $i++;
        }

        return $meta;
    }

    private function orderByDate(Builder $q, array $def): void
    {
        $dateCol = $def['date'];
        if ($dateCol && Schema::hasColumn($def['table'], $dateCol)) {
            $q->orderByDesc($def['table'].'.'.$dateCol);
        } else {
            $q->orderByDesc($def['table'].'.id');
        }
    }

    /** @return array<int,string> */
    private function distinctStatuses(array $def): array
    {
        if (! $def['status'] || ! Schema::hasColumn($def['table'], $def['status'])) {
            return [];
        }

        return DB::table($def['table'])
            ->whereNotNull($def['status'])
            ->distinct()
            ->orderBy($def['status'])
            ->pluck($def['status'])
            ->map(fn ($v) => (string) $v)
            ->all();
    }

    /** @return array{labels:array<int,string>,count:array<int,int>,amount:array<int,float>} */
    private function monthlyTrend(array $def, Request $request): array
    {
        $n = 6;
        $months = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $months[] = now()->startOfMonth()->subMonths($i);
        }
        $labels = array_map(fn (Carbon $m) => self::MONTHS_ID[$m->month], $months);
        $count  = array_fill(0, $n, 0);
        $amount = array_fill(0, $n, 0.0);

        $dateCol = $def['date'];
        if (! $dateCol || ! Schema::hasColumn($def['table'], $dateCol)) {
            return ['labels' => $labels, 'count' => $count, 'amount' => $amount];
        }

        $start = $months[0]->copy()->startOfMonth();
        $q = $this->buildQuery($def, $request)
            ->whereNotNull($def['table'].'.'.$dateCol)
            ->where($def['table'].'.'.$dateCol, '>=', $start);

        $select = [$def['table'].'.'.$dateCol.' as d'];
        if ($def['amount']) {
            $select[] = $def['table'].'.'.$def['amount'].' as a';
        }

        foreach ($q->get($select) as $row) {
            $key = Carbon::parse($row->d)->format('Y-m');
            foreach ($months as $idx => $m) {
                if ($m->format('Y-m') === $key) {
                    $count[$idx]++;
                    if ($def['amount']) {
                        $amount[$idx] += (float) ($row->a ?? 0);
                    }
                    break;
                }
            }
        }

        return ['labels' => $labels, 'count' => $count, 'amount' => $amount];
    }

    private function filterState(Request $request): array
    {
        return [
            'from'   => $request->input('from'),
            'to'     => $request->input('to'),
            'status' => $request->input('status'),
        ];
    }

    private function fmt(mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        return match ($type) {
            'money'    => number_format((float) $value, 0, ',', '.'),
            'date'     => Carbon::parse($value)->format('Y-m-d'),
            'datetime' => Carbon::parse($value)->format('Y-m-d H:i'),
            default    => (string) $value,
        };
    }
}
