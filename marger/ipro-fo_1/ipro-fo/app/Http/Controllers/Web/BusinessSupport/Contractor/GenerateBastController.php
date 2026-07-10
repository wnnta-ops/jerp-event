<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Contractor;

use App\Domain\BusinessSupport\Models\BsBastUnit;
use App\Domain\Master\Unit\Models\Unit;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\BusinessSupport\Contractor\Concerns\HandlesXlsx;
use App\Http\Controllers\Web\BusinessSupport\Contractor\Concerns\MapsUnitBast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Generate BAST (Contractor Management) — daftar unit (link dari modul Unit
 * Setup Sales FO) + status Sudah/Belum BAST, plus import Excel daftar unit.
 */
class GenerateBastController extends Controller
{
    use HandlesXlsx;
    use MapsUnitBast;

    /** Urutan kolom lampiran daftar unit (untuk template & parser import). */
    private const COLUMNS = ['Proyek', 'No. Unit', 'Lokasi', 'Tahap', 'Blok', 'Nomer', 'Tipe', 'Cluster', 'LT', 'LB', 'Harga jual', 'Status'];

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        // Baris dasar = unit master (source of truth) + status BAST.
        $units = Unit::query()
            ->with([
                'cluster:id,code,name', 'block:id,code',
                'unitType:id,code,name,lt,lb', 'project:id,code,name,location',
            ])
            ->orderBy('unit_code')
            ->get();
        $bastIds  = $this->bastUnitIds($units->pluck('id')->all());
        $unitRows = $units->map(fn (Unit $u) => $this->mapUnitRow($u, in_array($u->id, $bastIds, true)));

        // Baris import yang BELUM ada di master (supplemen) — selalu "belum BAST".
        $existingCodes = $units->pluck('unit_code')->map(fn ($c) => (string) $c)->all();
        $importRows    = BsBastUnit::orderBy('unit_no')->get()
            ->reject(fn (BsBastUnit $r) => in_array((string) $r->unit_no, $existingCodes, true))
            ->map(fn (BsBastUnit $r) => [
                'source'      => 'import',
                'unit_id'     => null,
                'id'          => $r->id,
                'project'     => $r->project ?: '—',
                'unit_no'     => $r->unit_no,
                'location'    => $r->location ?: '—',
                'phase'       => (string) ($r->phase ?? ''),
                'block'       => $r->block ?: '—',
                'number'      => (string) ($r->number ?? ''),
                'type'        => $r->type ?: '—',
                'cluster'     => $r->cluster ?: '—',
                'lt'          => $r->lt,
                'lb'          => $r->lb,
                'price'       => (float) $r->price,
                'unit_status' => $r->unit_status ?: '—',
                'bast_status' => 'belum',
            ]);

        $rows = $unitRows->concat($importRows)->values();

        // Filter (server-side sederhana).
        $search = trim((string) $request->input('search', ''));
        $status = (string) $request->input('bast', '');
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows   = $rows->filter(fn ($r) => str_contains(mb_strtolower(
                $r['unit_no'].' '.$r['project'].' '.$r['cluster'].' '.$r['type']
            ), $needle))->values();
        }
        if (in_array($status, ['sudah', 'belum'], true)) {
            $rows = $rows->where('bast_status', $status)->values();
        }

        return Inertia::render('BusinessSupport/Contractor/GenerateBast', [
            'rows'    => $rows,
            'columns' => self::COLUMNS,
            'filters' => ['search' => $search, 'bast' => $status],
            'summary' => [
                'total'    => $rows->count(),
                'sudah'    => $rows->where('bast_status', 'sudah')->count(),
                'belum'    => $rows->where('bast_status', 'belum')->count(),
                'imported' => $importRows->count(),
            ],
        ]);
    }

    /** Unduh template Excel (header 12 kolom + contoh dari unit master). */
    public function importTemplate(Request $request): HttpResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $rows  = [self::COLUMNS];
        $units = Unit::with(['cluster:id,code,name', 'block:id,code', 'unitType:id,code,name,lt,lb', 'project:id,code,name,location'])
            ->orderBy('unit_code')->limit(200)->get();
        foreach ($units as $u) {
            $m      = $this->mapUnitRow($u, false);
            $rows[] = [$m['project'], $m['unit_no'], $m['location'], $m['phase'], $m['block'], $m['number'], $m['type'], $m['cluster'], $m['lt'] ?? '', $m['lb'] ?? '', $m['price'], $m['unit_status']];
        }
        if (count($rows) === 1) {
            $rows[] = ['JAVA RESIDENCE', 'JRK3-001', 'Krian', 'Tahap 1', 'A', '1', '36/72', 'Cluster 3', 72, 36, 350000000, 'Tersedia'];
        }

        return $this->xlsxDownload($rows, 'template-generate-bast.xlsx', 'Generate BAST');
    }

    /** Import Excel/CSV daftar unit → upsert bs_bast_units per (tenant, No. Unit). */
    public function import(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120']]);

        $rows     = $this->xlsxRead($request->file('file')->getRealPath());
        $imported = 0;
        $userId   = $request->user()->id;

        foreach ($rows as $i => $r) {
            $unitNo = trim((string) ($r[1] ?? ''));
            // Lewati header (baris pertama yang mengandung label) & baris tanpa No. Unit.
            $c0 = mb_strtolower(trim((string) ($r[0] ?? '')));
            if ($i === 0 && (str_contains($c0, 'proyek') || str_contains($c0, 'no') || $c0 === '')) {
                continue;
            }
            if ($unitNo === '') {
                continue;
            }

            BsBastUnit::updateOrCreate(
                ['tenant_id' => tenant_id(), 'unit_no' => $unitNo],
                [
                    'project'     => $this->clean($r[0] ?? null),
                    'location'    => $this->clean($r[2] ?? null),
                    'phase'       => $this->clean($r[3] ?? null),
                    'block'       => $this->clean($r[4] ?? null),
                    'number'      => $this->clean($r[5] ?? null),
                    'type'        => $this->clean($r[6] ?? null),
                    'cluster'     => $this->clean($r[7] ?? null),
                    'lt'          => $this->intOrNull($r[8] ?? null),
                    'lb'          => $this->intOrNull($r[9] ?? null),
                    'price'       => $this->numeric($r[10] ?? null),
                    'unit_status' => $this->clean($r[11] ?? null),
                    'created_by'  => $userId,
                ]
            );
            $imported++;
        }

        return back()->with('success', "Import selesai — {$imported} baris unit diproses.");
    }

    /** Hapus baris import (supplemen). */
    public function destroy(Request $request, BsBastUnit $bastUnit): RedirectResponse
    {
        abort_unless($request->user()?->canModule('contractor'), 403);
        $bastUnit->delete();

        return back()->with('success', 'Baris unit import dihapus.');
    }

    private function clean(mixed $v): ?string
    {
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    private function intOrNull(mixed $v): ?int
    {
        $s = preg_replace('/[^\d]/', '', (string) $v);

        return ($s === null || $s === '') ? null : (int) $s;
    }

    private function numeric(mixed $v): float
    {
        return (float) preg_replace('/[^\d.]/', '', str_replace(',', '', (string) $v));
    }
}
