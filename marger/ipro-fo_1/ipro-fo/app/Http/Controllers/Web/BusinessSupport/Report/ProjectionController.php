<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Report;

use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsArInvoice;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Proyeksi Hutang/Piutang + Summary (BO Query proyeksi).
 * Bucket: Jatuh Tempo (overdue) + 12 bulan ke depan + > 12 bln, per supplier/customer.
 */
class ProjectionController extends Controller
{
    public function index(Request $request): Response
    {
        // Proyeksi lintas-modul: Hutang (AP) atau Piutang (AR).
        // Proyeksi menggabungkan hutang & piutang, jadi salah satu izin lihat sudah cukup.
        abort_unless($request->user()?->can('ap.invoice.view') || $request->user()?->can('ar.view'), 403);

        $mode  = $request->input('mode') === 'piutang' ? 'piutang' : 'hutang';
        $today = now()->startOfDay();

        // Bucket keys: overdue + 12 bulan + beyond.
        $buckets = [['key' => 'overdue', 'label' => 'Jatuh Tempo']];
        $monthKeys = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $today->copy()->startOfMonth()->addMonths($i);
            $monthKeys[] = $m->format('Y-m');
            $buckets[] = ['key' => $m->format('Y-m'), 'label' => $m->isoFormat('MMM YY')];
        }
        $buckets[] = ['key' => 'beyond', 'label' => '> 12 bln'];

        $bucketOf = function ($due) use ($today, $monthKeys): string {
            if (! $due) {
                return 'overdue';
            }
            $d = Carbon::parse($due)->startOfDay();
            if ($d->lt($today)) {
                return 'overdue';
            }
            $k = $d->format('Y-m');

            return in_array($k, $monthKeys, true) ? $k : 'beyond';
        };

        $zero = array_fill_keys(array_column($buckets, 'key'), 0.0);
        $entities = [];      // name => buckets + total
        $totals   = $zero + ['total' => 0.0];

        $add = function (string $name, string $bucket, float $amt) use (&$entities, &$totals, $zero): void {
            $entities[$name] ??= $zero + ['total' => 0.0];
            $entities[$name][$bucket] += $amt;
            $entities[$name]['total'] += $amt;
            $totals[$bucket] += $amt;
            $totals['total'] += $amt;
        };

        // #131: rentang tanggal yang bisa dipilih user (default hari ini → +30 hari).
        $from = $request->input('from') ? Carbon::parse($request->input('from'))->startOfDay() : $today->copy();
        $to   = $request->input('to')   ? Carbon::parse($request->input('to'))->endOfDay()     : $today->copy()->addDays(30)->endOfDay();
        $periodItems = [];

        if ($mode === 'hutang') {
            BsApInvoice::with('supplier:id,code,name')->where('status', 'open')->get()->each(function ($inv) use ($add, $bucketOf, &$periodItems, $from, $to, $today): void {
                $out = round((float) $inv->outstanding, 2);
                if ($out <= 0.009) {
                    return;
                }
                $due = $inv->due_date ?? $inv->invoice_date;
                $add($inv->supplier?->name ?? '— tanpa supplier —', $bucketOf($due), $out);
                $d = $due ? Carbon::parse($due)->startOfDay() : null;
                if ($d && $d->betweenIncluded($from, $to)) {
                    $periodItems[] = ['party' => $inv->supplier?->name ?? '— tanpa supplier —', 'ref' => $inv->invoice_number, 'due' => $d->toDateString(), 'amount' => $out, 'overdue' => $d->lt($today)];
                }
            });
        } else {
            BsArInvoice::with(['customer:id,code,full_name', 'schedules'])->where('status', 'open')->get()->each(function ($inv) use ($add, $bucketOf, &$periodItems, $from, $to, $today): void {
                $name = $inv->customer?->full_name ?? '— tanpa customer —';
                $schedules = $inv->schedules->filter(fn ($s) => (float) $s->amount - (float) $s->paid_amount > 0.009);
                if ($schedules->isNotEmpty()) {
                    foreach ($schedules as $s) {
                        $rem = round((float) $s->amount - (float) $s->paid_amount, 2);
                        $add($name, $bucketOf($s->due_date), $rem);
                        $d = $s->due_date ? Carbon::parse($s->due_date)->startOfDay() : null;
                        if ($d && $d->betweenIncluded($from, $to)) {
                            $periodItems[] = ['party' => $name, 'ref' => $inv->invoice_number.(isset($s->installment_no) ? ' #'.$s->installment_no : ''), 'due' => $d->toDateString(), 'amount' => $rem, 'overdue' => $d->lt($today)];
                        }
                    }
                } else {
                    $out = round((float) $inv->outstanding, 2);
                    if ($out > 0.009) {
                        $due = $inv->due_date ?? $inv->invoice_date;
                        $add($name, $bucketOf($due), $out);
                        $d = $due ? Carbon::parse($due)->startOfDay() : null;
                        if ($d && $d->betweenIncluded($from, $to)) {
                            $periodItems[] = ['party' => $name, 'ref' => $inv->invoice_number, 'due' => $d->toDateString(), 'amount' => $out, 'overdue' => $d->lt($today)];
                        }
                    }
                }
            });
        }

        // Urutkan baris dari total terbesar.
        $rows = collect($entities)->map(fn ($v, $name) => ['name' => $name] + $v)->sortByDesc('total')->values();

        usort($periodItems, fn ($a, $b) => strcmp((string) $a['due'], (string) $b['due']));
        $periodTotal = array_sum(array_column($periodItems, 'amount'));

        return Inertia::render('BusinessSupport/Report/Projection', [
            'mode'        => $mode,
            'view'        => $request->input('view') === 'summary' ? 'summary' : 'detail',
            'buckets'     => $buckets,
            'rows'        => $rows,
            'totals'      => $totals,
            'filters'     => ['from' => $from->toDateString(), 'to' => $to->toDateString()], // #131
            'periodItems' => $periodItems,
            'periodTotal' => $periodTotal,
        ]);
    }
}
