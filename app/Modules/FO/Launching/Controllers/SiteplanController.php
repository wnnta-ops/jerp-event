<?php

namespace App\Modules\FO\Launching\Controllers;

use App\Models\Project;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Modules\FO\MarketingSales\Models\SiteplanBlock;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Settings\FoSettings;
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
        // Tampilkan project yang punya siteplan level project ATAU punya cluster
        // (Lokasi) dengan siteplan sendiri — per-cluster ikut dipublikasikan.
        $projects = Project::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNotNull('siteplan_image_path')
                    ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('fo_lokasi as l')
                        ->whereColumn('l.project_id', 'projects.id')
                        ->where('l.is_active', true)
                        ->whereNotNull('l.siteplan_image_path'));
            })
            ->orderBy('name')
            ->get();

        // If exactly one project has a siteplan, jump straight to it.
        if ($projects->count() === 1) {
            return $this->show(request(), $projects->first()->code);
        }

        return view('siteplan.index', ['projects' => $projects]);
    }

    /**
     * Siteplan LIVE (acara NUP) — real-time, BOOKING dihitung SOLD, kotak Stock
     * Status, + animasi SOLD OUT otomatis saat scope (cluster/tahap) yang di-setup
     * 100% terjual. Scope diambil dari Pengaturan FO.
     */
    public function live(Request $request): View
    {
        $s = app(FoSettings::class);
        $focusLokasiId = $s->siteplanlive_focus_lokasi_id;
        $focusTahap = trim((string) $s->siteplanlive_focus_tahap);

        $focusLokasi = $focusLokasiId ? Lokasi::find($focusLokasiId) : null;
        $project = $focusLokasi?->project ?? Project::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNotNull('siteplan_image_path')
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('fo_lokasi as l')
                    ->whereColumn('l.project_id', 'projects.id')->where('l.is_active', true)
                    ->whereNotNull('l.siteplan_image_path')))
            ->orderBy('name')->first();
        abort_unless($project, 404);

        // Tanpa fokus & project tak punya siteplan sendiri → default cluster pertama
        // yang punya siteplan, supaya polygon SELALU sejajar gambar (spt /siteplan).
        if (! $focusLokasi && ! $project->siteplan_image_path) {
            $focusLokasi = Lokasi::where('project_id', $project->id)->where('is_active', true)
                ->orderBy('kode')->get()->first(fn ($l) => $l->hasOwnSiteplan());
        }

        return view('siteplan.live', [
            'project' => $project,
            'siteplan' => $focusLokasi?->resolvedSiteplan() ?? $this->siteplan($project),
            'units' => $this->units($project, $focusLokasi?->id),
            'blocks' => $this->blocks($project, $focusLokasi?->id),
            'focusLokasiId' => $focusLokasi?->id,
            'stock' => $this->liveStock($project, $focusLokasi?->id, $focusTahap),
            'showStock' => $s->siteplanlive_show_stock,
            'soldAnimation' => $s->siteplanlive_sold_animation,
            'focusTahap' => $focusTahap,
        ]);
    }

    /**
     * Stok live scope (cluster+tahap, atau seluruh project). BOOKING/HOLD = SOLD.
     *
     * @return array{total:int,ready:int,sold:int,ready_pct:int,sold_pct:int,sold_out:bool}
     */
    private function liveStock(Project $project, ?int $lokasiId, string $tahap): array
    {
        $units = Unit::query()->where('project_id', $project->id)->where('is_active', true)
            ->when($lokasiId, fn ($q) => $q->where('lokasi_id', $lokasiId))
            ->when($tahap !== '', fn ($q) => $q->where('tahap', $tahap))
            ->get(['status']);

        $total = $units->count();
        $ready = $units->where('status', 'TERSEDIA')->count();
        $sold = $total - $ready; // BOOKING/TERJUAL/LUNAS/HOLD = SOLD

        return [
            'total' => $total, 'ready' => $ready, 'sold' => $sold,
            'ready_pct' => $total > 0 ? (int) round($ready / $total * 100) : 0,
            'sold_pct' => $total > 0 ? (int) round($sold / $total * 100) : 0,
            'sold_out' => $total > 0 && $ready === 0,
        ];
    }

    public function show(Request $request, string $project): View
    {
        $model = Project::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereRaw('LOWER(code) = ?', [mb_strtolower($project)])->orWhere('id', $project))
            ->firstOrFail();

        // "Cluster" = Lokasi. Daftar Lokasi project ini (untuk switcher).
        $lokasiList = Lokasi::where('project_id', $model->id)
            ->where('is_active', true)->orderBy('kode')->get();

        // Lokasi terpilih dari ?cluster (kode atau id). Default: Lokasi pertama yang
        // punya gambar sendiri, else Lokasi pertama.
        $param = (string) $request->query('cluster', '');
        $lokasi = $param !== ''
            ? $lokasiList->first(fn ($l) => mb_strtolower($l->kode) === mb_strtolower($param) || (string) $l->id === $param)
            : null;
        $lokasi ??= $lokasiList->first(fn ($l) => $l->hasOwnSiteplan()) ?? $lokasiList->first();

        return view('siteplan.show', [
            'project' => $model,
            'lokasi' => $lokasi,
            'clusters' => $lokasiList,
            'siteplan' => $lokasi?->resolvedSiteplan() ?? $this->siteplan($model),
            'units' => $this->units($model, $lokasi?->id),
            'blocks' => $this->blocks($model, $lokasi?->id),
            'tahapStats' => $this->tahapStats($model, $lokasi?->id),
            // Setelan tampilan per-Lokasi (fallback project). Default true.
            'showInfo' => $lokasi ? $lokasi->resolvedShowInfo() : ($model->siteplan_show_info ?? true),
            'showStatus' => $lokasi ? $lokasi->resolvedShowStatus() : ($model->siteplan_show_status ?? true),
        ]);
    }

    /**
     * Per-tahap availability summary (Unit Ready / Sold / Hold + persen) for the
     * "Show Information" panel — mirrors the legacy site plan info box.
     *
     * @return array<int,array{tahap:string,ready:int,sold:int,hold:int,total:int,ready_pct:float,sold_pct:float,hold_pct:float}>
     */
    private function tahapStats(Project $project, ?int $lokasiId = null): array
    {
        $units = Unit::with('lokasi:id,kode,nama')
            ->where('project_id', $project->id)
            ->where('is_active', true)
            ->when($lokasiId, fn ($q) => $q->where('lokasi_id', $lokasiId))
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
    private function units(Project $project, ?int $lokasiId): array
    {
        $units = Unit::with(['unitType', 'lokasi', 'pricelists.skema'])
            ->where('project_id', $project->id)
            ->where('is_active', true)
            ->when($lokasiId, fn ($q) => $q->where('lokasi_id', $lokasiId))
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
     * Blok area (penanda tahap, tidak terikat unit) untuk project/cluster ini.
     *
     * @return array<int,array{coords:array,label:?string,color:string,opacity:float}>
     */
    private function blocks(Project $project, ?int $lokasiId): array
    {
        return SiteplanBlock::query()
            ->where('project_id', $project->id)
            ->when($lokasiId, fn ($q) => $q->where('lokasi_id', $lokasiId))
            ->get(['coords', 'label', 'color', 'opacity'])
            ->map(fn ($b) => [
                'coords' => is_array($b->coords) ? $b->coords : [],
                'label' => $b->label,
                'color' => $b->color ?: '#0a0a0a',
                'opacity' => (float) $b->opacity,
            ])
            ->filter(fn ($b) => count($b['coords']) >= 3)
            ->values()->all();
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
