<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Setup;

use App\Domain\BusinessSupport\Models\BsDepartment;
use App\Domain\BusinessSupport\Models\BsLedger;
use App\Domain\BusinessSupport\Models\BsLocation;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Business Support → Setup → Dimensi Jurnal (Departemen / Ledger / Lokasi).
 * Satu controller mengelola tiga master dimensi analitik jurnal.
 */
class JournalDimensionController extends Controller
{
    /** @var array<string,array{0:class-string<Model>,1:string,2:string}> */
    private const TYPES = [
        'department' => [BsDepartment::class, 'bs_departments', 'Departemen'],
        'ledger'     => [BsLedger::class, 'bs_ledgers', 'Ledger'],
        'location'   => [BsLocation::class, 'bs_locations', 'Lokasi'],
    ];

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        return Inertia::render('BusinessSupport/Setup/JournalDimensions', [
            'departments' => BsDepartment::orderBy('code')->get(['id', 'code', 'name', 'is_active']),
            'ledgers'     => BsLedger::orderBy('code')->get(['id', 'code', 'name', 'is_active']),
            'locations'   => BsLocation::orderBy('code')->get(['id', 'code', 'name', 'is_active']),
        ]);
    }

    public function store(Request $request, string $type): RedirectResponse
    {
        [$model, $table, $label] = $this->resolve($request, $type);
        $model::create($this->data($request, $table));

        return back()->with('success', "{$label} ditambahkan.");
    }

    public function update(Request $request, string $type, int $id): RedirectResponse
    {
        [$model, $table, $label] = $this->resolve($request, $type);
        $model::findOrFail($id)->update($this->data($request, $table, $id));

        return back()->with('success', "{$label} diperbarui.");
    }

    public function destroy(Request $request, string $type, int $id): RedirectResponse
    {
        [$model, $table, $label] = $this->resolve($request, $type);
        $model::findOrFail($id)->delete();

        return back()->with('success', "{$label} dihapus.");
    }

    /** @return array{0:class-string<Model>,1:string,2:string} */
    private function resolve(Request $request, string $type): array
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        abort_unless(isset(self::TYPES[$type]), 404);

        return self::TYPES[$type];
    }

    /** @return array<string,mixed> */
    private function data(Request $request, string $table, ?int $id = null): array
    {
        return $request->validate([
            'code'      => ['required', 'string', 'max:32', Rule::unique($table, 'code')->ignore($id)->whereNull('deleted_at')],
            'name'      => ['required', 'string', 'max:150'],
            'is_active' => ['boolean'],
        ]);
    }
}
