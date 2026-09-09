<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Contractor;

use App\Domain\BusinessSupport\Models\BsProject;
use App\Domain\BusinessSupport\Models\BsWorkOrder;
use App\Domain\BusinessSupport\Models\BsWorkOrderMaterial;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Query / Laporan Contractor Management. */
class ContractorQueryController extends Controller
{
    private function filters(Request $request): array
    {
        return [
            'project_id' => $request->integer('project_id') ?: null,
            'jenis'      => $request->input('jenis') ?: null,
            // Filter periode: tanggal awal & tanggal akhir (opsional).
            'date_from'  => $request->input('date_from') ?: null,
            'date_to'    => $request->input('date_to') ?: null,
        ];
    }

    private function projectOpts(): array
    {
        return BsProject::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])->toArray();
    }

    // ── Row builders (dipakai halaman Inertia & export Excel) ──

    private function rekapRows(array $f)
    {
        return BsWorkOrder::with(['supplier:id,name', 'project:id,code,name'])
            ->when($f['project_id'], fn ($q, $id) => $q->where('project_id', $id))
            ->when($f['jenis'], fn ($q, $j) => $q->where('jenis', $j))
            ->when($f['date_from'], fn ($q, $d) => $q->whereDate('spk_date', '>=', $d))
            ->when($f['date_to'], fn ($q, $d) => $q->whereDate('spk_date', '<=', $d))
            ->orderByDesc('spk_date')->get()
            ->map(fn ($w) => [
                'spk_number' => $w->spk_number, 'date' => $w->spk_date?->format('Y-m-d'), 'title' => $w->title,
                'supplier' => $w->supplier?->name, 'project' => $w->project?->name, 'jenis' => $w->jenis,
                'contract' => (float) $w->contract_value, 'progress' => (float) $w->progress_value,
                'retention' => (float) $w->retention_held, 'remaining' => (float) $w->remaining_value, 'status' => $w->status,
            ])->values();
    }

    private function kartuRows(array $f)
    {
        return BsWorkOrder::with(['supplier:id,name', 'project:id,code,name', 'progress:id,work_order_id,net_payable,status'])
            ->when($f['project_id'], fn ($q, $id) => $q->where('project_id', $id))
            ->when($f['jenis'], fn ($q, $j) => $q->where('jenis', $j))
            ->when($f['date_from'], fn ($q, $d) => $q->whereDate('spk_date', '>=', $d))
            ->when($f['date_to'], fn ($q, $d) => $q->whereDate('spk_date', '<=', $d))
            ->orderByDesc('spk_date')->get()
            ->map(function ($w) {
                $billed = (float) $w->progress->sum('net_payable');
                $paid   = (float) $w->progress->where('status', 'paid')->sum('net_payable');

                return [
                    'spk_number' => $w->spk_number, 'supplier' => $w->supplier?->name, 'project' => $w->project?->name,
                    'date' => $w->spk_date?->format('Y-m-d'), 'jenis' => $w->jenis,
                    'billed' => $billed, 'paid' => $paid, 'outstanding' => round($billed - $paid, 2),
                ];
            })->values();
    }

    private function pemakaianRows(array $f)
    {
        return BsWorkOrderMaterial::with(['workOrder:id,spk_number,title,project_id,jenis', 'workOrder.project:id,name', 'inventoryAccount:id,code,name'])
            ->whereHas('workOrder', function ($q) use ($f) {
                $q->when($f['project_id'], fn ($qq, $id) => $qq->where('project_id', $id))
                  ->when($f['jenis'], fn ($qq, $j) => $qq->where('jenis', $j));
            })
            ->when($f['date_from'], fn ($q, $d) => $q->whereDate('usage_date', '>=', $d))
            ->when($f['date_to'], fn ($q, $d) => $q->whereDate('usage_date', '<=', $d))
            ->orderByDesc('usage_date')->get()
            ->map(fn ($m) => [
                'date' => $m->usage_date?->format('Y-m-d'), 'spk' => $m->workOrder?->spk_number,
                'project' => $m->workOrder?->project?->name, 'description' => $m->description,
                'account' => $m->inventoryAccount?->code.' - '.$m->inventoryAccount?->name,
                'amount' => (float) $m->amount, 'returned' => (float) $m->returned_amount, 'status' => $m->status,
            ])->values();
    }

    private function summaryRows(array $f)
    {
        return BsWorkOrder::with('project:id,name')
            ->when($f['project_id'], fn ($q, $id) => $q->where('project_id', $id))
            ->when($f['jenis'], fn ($q, $j) => $q->where('jenis', $j))
            ->when($f['date_from'], fn ($q, $d) => $q->whereDate('spk_date', '>=', $d))
            ->when($f['date_to'], fn ($q, $d) => $q->whereDate('spk_date', '<=', $d))
            ->get()
            ->groupBy(fn ($w) => ($w->project?->name ?? 'Tanpa Project').' | '.($w->jenis ?? '-'))
            ->map(fn ($grp, $key) => [
                'group' => $key, 'count' => $grp->count(),
                'contract' => (float) $grp->sum('contract_value'), 'progress' => (float) $grp->sum('progress_value'),
                'retention' => (float) $grp->sum('retention_held'),
            ])->values();
    }

    /** Rekap SPK. */
    public function rekap(Request $request): Response
    {
        abort_unless($request->user()?->can('contractor.query.view'), 403);
        $f = $this->filters($request);
        $rows = $this->rekapRows($f);

        return Inertia::render('BusinessSupport/Contractor/Query/Rekap', [
            'filters' => $f, 'projects' => $this->projectOpts(), 'jenisOpts' => BsWorkOrder::JENIS, 'rows' => $rows,
            'total' => ['contract' => $rows->sum('contract'), 'progress' => $rows->sum('progress'), 'retention' => $rows->sum('retention'), 'remaining' => $rows->sum('remaining')],
        ]);
    }

    /** Kartu Hutang Supplier - SPK: per-SPK hutang (net termin) vs terbayar. */
    public function kartuHutang(Request $request): Response
    {
        abort_unless($request->user()?->can('contractor.query.view'), 403);
        $f = $this->filters($request);
        $rows = $this->kartuRows($f);

        return Inertia::render('BusinessSupport/Contractor/Query/KartuHutang', [
            'filters' => $f, 'projects' => $this->projectOpts(), 'jenisOpts' => BsWorkOrder::JENIS, 'rows' => $rows,
            'total' => ['billed' => $rows->sum('billed'), 'paid' => $rows->sum('paid'), 'outstanding' => $rows->sum('outstanding')],
        ]);
    }

    /** Laporan Pemakaian Barang. */
    public function pemakaian(Request $request): Response
    {
        abort_unless($request->user()?->can('contractor.query.view'), 403);
        $f = $this->filters($request);
        $rows = $this->pemakaianRows($f);

        return Inertia::render('BusinessSupport/Contractor/Query/Pemakaian', [
            'filters' => $f, 'projects' => $this->projectOpts(), 'jenisOpts' => BsWorkOrder::JENIS, 'rows' => $rows,
            'total' => ['amount' => $rows->sum('amount'), 'returned' => $rows->sum('returned')],
        ]);
    }

    /** Summary SPK — agregasi per project & jenis. */
    public function summary(Request $request): Response
    {
        abort_unless($request->user()?->can('contractor.query.view'), 403);
        $f = $this->filters($request);
        $rows = $this->summaryRows($f);

        return Inertia::render('BusinessSupport/Contractor/Query/Summary', [
            'filters' => $f, 'projects' => $this->projectOpts(), 'jenisOpts' => BsWorkOrder::JENIS, 'rows' => $rows,
            'total' => ['contract' => $rows->sum('contract'), 'progress' => $rows->sum('progress'), 'retention' => $rows->sum('retention')],
        ]);
    }

    /** Export Excel (.xls) untuk 4 query — mengikuti filter periode & project/jenis di layar. */
    public function export(Request $request, string $type): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('contractor.query.view'), 403);
        $f = $this->filters($request);

        if ($type === 'kartu-hutang') {
            $title  = 'Kartu Hutang Supplier SPK';
            $header = ['No. SPK', 'Supplier', 'Project', 'Tgl SPK', 'Jenis', 'Tertagih', 'Terbayar', 'Outstanding'];
            $rows   = $this->kartuRows($f)->map(fn ($r) => [$r['spk_number'], $r['supplier'], $r['project'], $r['date'], $r['jenis'], $r['billed'], $r['paid'], $r['outstanding']]);
        } elseif ($type === 'pemakaian') {
            $title  = 'Pemakaian Barang SPK';
            $header = ['Tgl', 'No. SPK', 'Project', 'Uraian', 'Akun', 'Nilai', 'Dikembalikan', 'Status'];
            $rows   = $this->pemakaianRows($f)->map(fn ($r) => [$r['date'], $r['spk'], $r['project'], $r['description'], $r['account'], $r['amount'], $r['returned'], $r['status']]);
        } elseif ($type === 'summary') {
            $title  = 'Summary SPK';
            $header = ['Grup (Project | Jenis)', 'Jml SPK', 'Nilai Kontrak', 'Progress', 'Retensi'];
            $rows   = $this->summaryRows($f)->map(fn ($r) => [$r['group'], $r['count'], $r['contract'], $r['progress'], $r['retention']]);
        } else {
            $title  = 'Rekap SPK';
            $header = ['No. SPK', 'Tgl SPK', 'Pekerjaan', 'Supplier', 'Project', 'Jenis', 'Nilai Kontrak', 'Progress', 'Retensi', 'Sisa', 'Status'];
            $rows   = $this->rekapRows($f)->map(fn ($r) => [$r['spk_number'], $r['date'], $r['title'], $r['supplier'], $r['project'], $r['jenis'], $r['contract'], $r['progress'], $r['retention'], $r['remaining'], $r['status']]);
        }

        return $this->xls($title, $header, $rows, $f);
    }

    /** Bangun file .xls (HTML-table) — kompatibel Excel. */
    private function xls(string $title, array $header, $rows, array $f): \Illuminate\Http\Response
    {
        $e = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $periode = ($f['date_from'] || $f['date_to']) ? ' · Periode '.($f['date_from'] ?: '…').' s/d '.($f['date_to'] ?: '…') : '';

        $h = '<table border="1"><tr style="background:#0f766e;color:#fff">';
        foreach ($header as $col) {
            $h .= '<th>'.$e($col).'</th>';
        }
        $h .= '</tr>';
        foreach ($rows as $r) {
            $h .= '<tr>';
            foreach ($r as $cell) {
                $h .= '<td>'.$e($cell).'</td>';
            }
            $h .= '</tr>';
        }
        $h .= '</table>';

        $file = str_replace(' ', '-', $title).'-'.now()->format('Ymd-His').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'
            .'<h3>'.$e($title).$periode.'</h3>'.$h.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }
}
