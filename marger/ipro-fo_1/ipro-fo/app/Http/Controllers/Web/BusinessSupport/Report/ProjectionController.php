<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Report;

use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsArInvoice;
use App\Domain\BusinessSupport\Support\PosisiHutang;
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
        $this->bolehLihat($request);

        return Inertia::render('BusinessSupport/Report/Projection', $this->data($request));
    }

    /** Proyeksi menggabungkan hutang & piutang, jadi salah satu izin lihat sudah cukup — juga untuk unduhannya. */
    private function bolehLihat(Request $request): void
    {
        abort_unless($request->user()?->can('ap.invoice.view') || $request->user()?->can('ar.view'), 403);
    }

    /** Hitungan proyeksi — SATU sumber untuk layar, Excel, dan PDF. */
    private function data(Request $request): array
    {
        // Proyeksi lintas-modul: Hutang (AP) atau Piutang (AR).
        $mode  = $request->input('mode') === 'piutang' ? 'piutang' : 'hutang';
        // Posisi hutang HANYA berlaku pada cabang hutang. Cabang piutang membaca BsArInvoice,
        // yang tidak punya kategori invoice AP sama sekali — membawa pilihan ini ke sana berarti
        // menyodorkan penyaring yang tidak menyaring apa pun.
        $posisi = $mode === 'hutang' ? PosisiHutang::dariRequest($request) : PosisiHutang::SEMUA;
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
            PosisiHutang::saring(BsApInvoice::with('supplier:id,code,name'), $posisi)->where('status', 'open')->get()->each(function ($inv) use ($add, $bucketOf, &$periodItems, $from, $to, $today): void {
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

        return [
            'mode'        => $mode,
            'view'        => $request->input('view') === 'summary' ? 'summary' : 'detail',
            'buckets'     => $buckets,
            'rows'        => $rows,
            'totals'      => $totals,
            'filters'     => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'posisi' => $posisi], // #131
            'posisiOpsi'  => PosisiHutang::opsi(),
            'periodItems' => $periodItems,
            'periodTotal' => $periodTotal,
        ];
    }

    /**
     * Isi unduhan — sama dengan layar: Detail (Jatuh Tempo + 12 bulan + > 12 bln) atau Summary,
     * lalu Item Jatuh Tempo per Periode. Angka penuh.
     *
     * @return array{0:string,1:string,2:string,3:bool} [judul, keterangan kepala, isi tabel HTML, mendatar?]
     */
    private function isiUnduhan(array $d, bool $excel): array
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
        $r = fn () => $excel ? '' : ' class="r"';
        $ap = $d['mode'] === 'hutang';
        $pihak = $ap ? 'Supplier' : 'Customer';
        $summary = $d['view'] === 'summary';
        $kolom = $summary ? [['key' => 'overdue', 'label' => 'Jatuh Tempo']] : $d['buckets'];
        $th = $excel ? ' style="background:#0f766e;color:#fff"' : '';

        $t1 = '<table'.($excel ? ' border="1"' : '').'><thead><tr'.$th.'><th>'.$pihak.'</th>';
        foreach ($kolom as $b) {
            $t1 .= '<th'.$r().'>'.$e($b['label']).'</th>';
        }
        $t1 .= '<th'.$r().'>Total</th></tr></thead><tbody>';
        foreach ($d['rows'] as $row) {
            $t1 .= '<tr><td>'.$e($row['name']).'</td>';
            foreach ($kolom as $b) {
                $t1 .= '<td'.$r().'>'.$rp($row[$b['key']] ?? 0).'</td>';
            }
            $t1 .= '<td'.$r().'>'.$rp($row['total']).'</td></tr>';
        }
        if (count($d['rows']) === 0) {
            $t1 .= '<tr><td colspan="'.(count($kolom) + 2).'" style="text-align:center">Tidak ada '.($ap ? 'hutang' : 'piutang').' terbuka.</td></tr>';
        }
        $t1 .= '<tr style="font-weight:bold;background:#e2e8f0"><td>TOTAL</td>';
        foreach ($kolom as $b) {
            $t1 .= '<td'.$r().'>'.$rp($d['totals'][$b['key']] ?? 0).'</td>';
        }
        $t1 .= '<td'.$r().'>'.$rp($d['totals']['total']).'</td></tr></tbody></table>';

        $dari = Carbon::parse($d['filters']['from'])->format('d-m-Y');
        $sampai = Carbon::parse($d['filters']['to'])->format('d-m-Y');
        $t2 = '<h3>Item '.($ap ? 'Hutang' : 'Piutang').' Jatuh Tempo per Periode ('.$dari.' s/d '.$sampai.')</h3>'
            .'<table'.($excel ? ' border="1"' : '').'><thead><tr'.$th.'><th>'.$pihak.'</th><th>No. Dokumen</th><th>Jatuh Tempo</th><th'.$r().'>Nominal</th><th>Ket.</th></tr></thead><tbody>';
        foreach ($d['periodItems'] as $it) {
            $t2 .= '<tr><td>'.$e($it['party']).'</td><td>'.$e($it['ref']).'</td><td>'.$e(Carbon::parse($it['due'])->format('d-m-Y')).'</td><td'.$r().'>'.$rp($it['amount']).'</td><td>'.($it['overdue'] ? 'Lewat jatuh tempo' : '').'</td></tr>';
        }
        if ($d['periodItems'] === []) {
            $t2 .= '<tr><td colspan="5" style="text-align:center">Tidak ada item jatuh tempo pada periode ini.</td></tr>';
        }
        $t2 .= '<tr style="font-weight:bold;background:#e2e8f0"><td colspan="3">TOTAL PERIODE</td><td'.$r().'>'.$rp($d['periodTotal']).'</td><td></td></tr></tbody></table>';

        $judul = 'PROYEKSI '.($ap ? 'HUTANG' : 'PIUTANG').' — '.($summary ? 'SUMMARY' : 'DETAIL');
        $posisi = $ap ? (collect($d['posisiOpsi'])->firstWhere('nilai', $d['filters']['posisi'])['label'] ?? 'Semua') : null;
        $ket = 'Per '.now()->format('d-m-Y').($posisi ? ' · Posisi: '.$e($posisi) : '').' · Cetak '.now()->format('d-m-Y H:i');

        return [$judul, $ket, $t1.$t2, ! $summary];
    }

    public function excel(Request $request): \Illuminate\Http\Response
    {
        $this->bolehLihat($request);
        $d = $this->data($request);
        [$judul, $ket, $isi] = $this->isiUnduhan($d, true);
        $co = htmlspecialchars((string) config('business_support.company.name', 'PT. Mitra Usaha Propertindo'), ENT_QUOTES, 'UTF-8');
        $file = 'Proyeksi-'.($d['mode'] === 'hutang' ? 'Hutang' : 'Piutang').'-'.now()->format('Ymd-His').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'
            .'<table><tr><td style="font-weight:bold">'.$judul.' — '.$co.'</td></tr><tr><td>'.$ket.'</td></tr></table><br>'.$isi.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /** Cetak (HTML siap-cetak / simpan PDF) — auto-print; Detail mendatar, Summary tegak. */
    public function pdf(Request $request): \Illuminate\Http\Response
    {
        $this->bolehLihat($request);
        $d = $this->data($request);
        [$judul, $ket, $isi, $mendatar] = $this->isiUnduhan($d, false);
        $co = htmlspecialchars((string) config('business_support.company.name', 'PT. Mitra Usaha Propertindo'), ENT_QUOTES, 'UTF-8');

        $html = '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>'.$judul.'</title><style>
            @page{size:A4 '.($mendatar ? 'landscape' : 'portrait').';margin:12mm}*{box-sizing:border-box}body{font-family:"Segoe UI",Arial,sans-serif;font-size:10px;color:#1f2937;margin:0}
            h1{font-size:15px;margin:0 0 2px;color:#b91c1c}h3{font-size:11px;margin:14px 0 4px;color:#991b1b}
            .head{border-bottom:2px solid #b91c1c;padding-bottom:8px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:flex-start}
            .muted{color:#6b7280;font-size:9px}table{border-collapse:collapse;width:100%;margin:4px 0;font-size:'.($mendatar ? '8.5' : '10').'px}
            th,td{border:1px solid #cbd5e1;padding:3px 5px;text-align:left}.r{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
            thead th{background:#f1f5f9}
            @media print{.noprint{display:none}}</style></head><body>
            <div class="head"><div><h1>'.$judul.'</h1><div class="muted">'.$co.'</div></div><div class="muted" style="text-align:right">'.$ket.'</div></div>
            '.$isi.'
            <button class="noprint" onclick="window.print()" style="margin-top:14px;padding:6px 14px">Cetak</button>
            <script>window.onload=function(){setTimeout(function(){window.print()},300)}</script></body></html>';

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
