<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Sales;

use App\Domain\Sales\Migration\Services\MigrationImportService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Support\Xlsx;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Migrasi Data (eProperty SALES → Migrate): Upload Kontrak / Jadwal / Pembayaran
 * dari Excel (.xlsx) untuk cutover data legacy.
 */
class MigrationController extends Controller
{
    public function __construct(private readonly MigrationImportService $service) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('sales.contract.view'), 403);

        return Inertia::render('Sales/Migration/Index', [
            'columns' => MigrationImportService::COLUMNS,
            'result'  => session('migration_result'),
            'lastType'=> session('migration_type'),
        ]);
    }

    public function upload(Request $request, string $type): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.contract.update'), 403);
        abort_unless(isset(MigrationImportService::COLUMNS[$type]), 404);
        $request->validate([
            'file'    => ['required', 'file', 'mimes:xlsx', 'max:10240'],
            'dry_run' => ['boolean'],
        ], [
            'file.mimes' => 'Berkas harus Excel (.xlsx). Unduh template untuk contoh susunannya.',
        ]);
        try {
            $baris = Xlsx::baca($request->file('file')->getRealPath());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $result = $this->service->import($type, $baris, $request->user()->id, $request->boolean('dry_run'));

        $label = ['jadwal' => 'Jadwal', 'pembayaran' => 'Pembayaran', 'kontrak' => 'Kontrak'][$type];
        $msg = ($request->boolean('dry_run') ? '[Dry run] ' : '')."Migrasi {$label}: {$result['ok']} baris diproses, ".count($result['failed']).' gagal.';

        return redirect()->route('sales.migrasi.index')
            ->with('migration_result', $result)->with('migration_type', $type)->with('success', $msg);
    }

    public function template(Request $request, string $type): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('sales.contract.view'), 403);
        abort_unless(isset(MigrationImportService::COLUMNS[$type]), 404);

        return response($this->service->template($type), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"template_migrasi_{$type}.xlsx\"",
        ]);
    }
}
