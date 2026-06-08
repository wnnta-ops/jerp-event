<?php

namespace App\Modules\FO\Launching\Controllers;

use App\Models\Project;
use App\Modules\FO\MarketingSales\Models\Unit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Public, read-only site plan viewer (marketing tool for customers & sales).
 * Reuses the same data as the launching "Pilih Unit" page — project siteplan
 * image + per-unit polygon coords + per-skema price list — but WITHOUT the
 * barcode scanner / timer / unit-lock flow. Mirrors the legacy
 * siteplan.javaresidence.com look.
 */
class SiteplanController
{
    /** Landing: pick a project/cluster. */
    public function index(): View
    {
        $projects = Project::query()
            ->where('is_active', true)
            ->whereNotNull('siteplan_image_path')
            ->orderBy('name')
            ->get();

        // If exactly one project has a siteplan, jump straight to it.
        if ($projects->count() === 1) {
            return $this->show(request(), $projects->first()->code);
        }

        return view('siteplan.index', ['projects' => $projects]);
    }

    public function show(Request $request, string $project): View
    {
        $model = Project::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereRaw('LOWER(code) = ?', [mb_strtolower($project)])->orWhere('id', $project))
            ->firstOrFail();

        $cluster = $request->query('cluster');

        $clusters = Unit::query()
            ->where('project_id', $model->id)
            ->where('is_active', true)
            ->whereNotNull('cluster')
            ->where('cluster', '!=', '')
            ->distinct()
            ->orderBy('cluster')
            ->pluck('cluster')
            ->all();

        return view('siteplan.show', [
            'project' => $model,
            'cluster' => $cluster,
            'clusters' => $clusters,
            'siteplan' => $this->siteplan($model),
            'units' => $this->units($model, is_string($cluster) ? $cluster : null),
            'tahapStats' => $this->tahapStats($model),
        ]);
    }

    /**
     * Per-tahap availability summary (Unit Ready / Sold / Hold + persen) for the
     * "Show Information" panel — mirrors the legacy site plan info box.
     *
     * @return array<int,array{tahap:string,ready:int,sold:int,hold:int,total:int,ready_pct:float,sold_pct:float,hold_pct:float}>
     */
    private function tahapStats(Project $project): array
    {
        $units = Unit::with('lokasi:id,kode,nama')
            ->where('project_id', $project->id)
            ->where('is_active', true)
            ->get(['lokasi_id', 'tahap', 'status']);

        $rows = [];
        // Header memakai KODE LOKASI (mis. "JRK3"), bukan kode project. Group per
        // (lokasi, tahap) supaya tiap baris mencerminkan Lokasi-Tahap yang tepat.
        $grouped = $units->groupBy(function ($u) {
            $lok = $u->lokasi?->kode ?: '-';
            $tahap = ($u->tahap !== null && $u->tahap !== '') ? $u->tahap : '-';

            return $lok.'|'.$tahap;
        });

        foreach ($grouped as $key => $group) {
            [$lokasiKode, $tahap] = explode('|', $key, 2);
            $ready = $group->where('status', 'TERSEDIA')->count();
            $hold = $group->where('status', 'HOLD')->count();
            $sold = $group->whereIn('status', ['TERJUAL', 'LUNAS', 'BOOKING'])->count();
            $total = $ready + $sold + $hold;
            $pct = fn (int $n): float => $total > 0 ? round($n / $total * 100, 2) : 0.0;

            $rows[] = [
                'lokasi' => $lokasiKode !== '-' ? $lokasiKode : ($project->code ?? ''),
                'tahap' => (string) $tahap,
                'ready' => $ready, 'sold' => $sold, 'hold' => $hold, 'total' => $total,
                'ready_pct' => $pct($ready), 'sold_pct' => $pct($sold), 'hold_pct' => $pct($hold),
            ];
        }

        usort($rows, fn ($a, $b) => [$a['lokasi'], $a['tahap']] <=> [$b['lokasi'], $b['tahap']]);

        return $rows;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function units(Project $project, ?string $cluster): array
    {
        $units = Unit::with(['unitType', 'lokasi', 'pricelists.skema'])
            ->where('project_id', $project->id)
            ->where('is_active', true)
            ->when($cluster !== null && $cluster !== '', fn ($q) => $q->where('cluster', $cluster))
            ->orderBy('blok')
            ->orderBy('no_unit')
            ->get();

        $out = [];
        foreach ($units as $u) {
            $kode = $u->kode_unit ?: $u->label;

            // Harga per skema bayar (fo_unit_price_lists). Skema di-scope per
            // lokasi/tahap → hanya yang COCOK dgn lokasi+tahap unit ini yang tampil
            // (mis. skema CASH tahap 001 tak muncul di unit tahap 004).
            $pricelist = [];
            foreach ($u->pricelists as $p) {
                $price = (float) $p->price_list;
                // Hanya skema "Tampil di Site Plan" + cocok lokasi/tahap unit.
                if ($price <= 0 || $p->skema === null || ! $p->skema->tampil_siteplan
                    || ! $p->skema->appliesToScope($u->lokasi_id, $u->tahap_id)) {
                    continue;
                }
                $pricelist[] = [
                    'skema_code' => $p->skema->code,
                    'skema_name' => $p->skema->name ?: $p->no_skema,
                    'cara_bayar' => $p->skema->cara_bayar,
                    'price' => $price,
                ];
            }

            $out[] = [
                'id' => $u->id,
                'kode' => $kode,
                'label' => $kode,
                'blok' => $u->blok,
                'no_unit' => $u->no_unit,
                'lokasi_kode' => $u->lokasi?->kode ?: ($project->code ?? ''),
                'tahap' => ($u->tahap !== null && $u->tahap !== '') ? $u->tahap : '-',
                'nama_lokasi' => $u->lokasi?->nama ?: $project->name,
                'tipe' => $u->unitType?->name ?: '—',
                'luas_tanah' => (float) $u->luas_tanah,
                'luas_bangunan' => (float) $u->luas_bangunan,
                'alamat' => $u->alamat ?: ($project->address ?? '—'),
                'harga' => (float) $u->harga_jual,
                'pricelist' => $pricelist,
                'status' => $u->status,
                'siteplan_coords' => is_array($u->siteplan_coords) ? $u->siteplan_coords : null,
            ];
        }

        return $out;
    }

    /**
     * @return array{url:string,width:int,height:int}|null
     */
    private function siteplan(Project $project): ?array
    {
        if (! $project->siteplan_image_path) {
            return null;
        }

        return [
            'url' => Storage::disk('public')->url($project->siteplan_image_path),
            'width' => (int) ($project->siteplan_width ?: 2000),
            'height' => (int) ($project->siteplan_height ?: 1200),
        ];
    }
}
