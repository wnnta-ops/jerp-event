<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Cogs;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCogsBudget;
use App\Domain\BusinessSupport\Models\BsCogsCategory;
use App\Domain\BusinessSupport\Models\BsCogsLocation;
use App\Domain\BusinessSupport\Models\BsCogsProvision;
use App\Domain\BusinessSupport\Models\BsCogsUnitTypeAccount;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CogsSetupController extends Controller
{
    private function projects(): array
    {
        return Project::orderBy('name')->get(['id', 'code', 'name'])->toArray();
    }

    private function accounts(): array
    {
        return BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name'])->toArray();
    }

    // ── Lokasi ──
    public function locationIndex(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cogs'), 403);

        return Inertia::render('BusinessSupport/Cogs/Setup/Location', [
            'rows'     => BsCogsLocation::orderBy('code')->get(),
            'projects' => $this->projects(),
        ]);
    }

    public function locationStore(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $v = $request->validate(['project_id' => ['nullable', 'integer'], 'code' => ['required', 'string', 'max:40'], 'name' => ['required', 'string', 'max:150'], 'is_active' => ['nullable', 'boolean'], 'notes' => ['nullable', 'string', 'max:200']]);
        BsCogsLocation::create(array_merge($v, ['tenant_id' => tenant_id(), 'created_by' => $request->user()->id]));

        return back()->with('success', 'Lokasi ditambahkan.');
    }

    public function locationUpdate(Request $request, BsCogsLocation $location): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $location->update($request->validate(['project_id' => ['nullable', 'integer'], 'code' => ['required', 'string', 'max:40'], 'name' => ['required', 'string', 'max:150'], 'is_active' => ['nullable', 'boolean'], 'notes' => ['nullable', 'string', 'max:200']]));

        return back()->with('success', 'Lokasi diperbarui.');
    }

    public function locationDestroy(Request $request, BsCogsLocation $location): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $location->delete();

        return back()->with('success', 'Lokasi dihapus.');
    }

    // ── Ketentuan (per project) ──
    public function provisionIndex(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cogs'), 403);

        return Inertia::render('BusinessSupport/Cogs/Setup/Provision', [
            'rows'     => BsCogsProvision::get(),
            'projects' => $this->projects(),
            'accounts' => $this->accounts(),
        ]);
    }

    public function provisionSave(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $v = $request->validate([
            'project_id'             => ['required', 'integer'],
            'acc_uang_muka_id'       => ['nullable', 'integer'],
            'acc_pendapatan_id'      => ['nullable', 'integer'],
            'acc_piutang_id'         => ['nullable', 'integer'],
            'acc_cip_id'             => ['nullable', 'integer'],
            'acc_persediaan_unit_id' => ['nullable', 'integer'],
            'acc_hpp_id'             => ['nullable', 'integer'],
            'basis'                  => ['nullable', 'in:price,equal,luas'],
            'notes'                  => ['nullable', 'string', 'max:200'],
        ]);
        BsCogsProvision::updateOrCreate(['project_id' => $v['project_id']], array_merge($v, ['tenant_id' => tenant_id()]));

        return back()->with('success', 'Ketentuan disimpan.');
    }

    // ── Set Akun Budget (Kategori Budget + komponen + COA) ──
    public function categoryIndex(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cogs'), 403);

        return Inertia::render('BusinessSupport/Cogs/Setup/Category', [
            'rows'       => BsCogsCategory::with('defaultAccount:id,code,name')->orderBy('code')->get(),
            'components' => BsCogsCategory::COMPONENTS,
            'accounts'   => $this->accounts(),
        ]);
    }

    public function categoryStore(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $v = $request->validate(['code' => ['required', 'string', 'max:40'], 'name' => ['required', 'string', 'max:150'], 'component' => ['required', 'string', 'max:20'], 'default_account_id' => ['nullable', 'integer'], 'is_active' => ['nullable', 'boolean']]);
        BsCogsCategory::create(array_merge($v, ['tenant_id' => tenant_id()]));

        return back()->with('success', 'Kategori budget ditambahkan.');
    }

    public function categoryUpdate(Request $request, BsCogsCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $category->update($request->validate(['code' => ['required', 'string', 'max:40'], 'name' => ['required', 'string', 'max:150'], 'component' => ['required', 'string', 'max:20'], 'default_account_id' => ['nullable', 'integer'], 'is_active' => ['nullable', 'boolean']]));

        return back()->with('success', 'Kategori budget diperbarui.');
    }

    public function categoryDestroy(Request $request, BsCogsCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $category->delete();

        return back()->with('success', 'Kategori budget dihapus.');
    }

    // ── Set COA Jenis Unit ──
    public function unitCoaIndex(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cogs'), 403);

        return Inertia::render('BusinessSupport/Cogs/Setup/UnitCoa', [
            'rows'     => BsCogsUnitTypeAccount::with('account:id,code,name')->get(),
            'projects' => $this->projects(),
            'accounts' => $this->accounts(),
        ]);
    }

    public function unitCoaStore(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $v = $request->validate(['project_id' => ['nullable', 'integer'], 'unit_type' => ['required', 'string', 'max:80'], 'account_id' => ['required', 'integer']]);
        BsCogsUnitTypeAccount::create(array_merge($v, ['tenant_id' => tenant_id()]));

        return back()->with('success', 'COA jenis unit ditambahkan.');
    }

    public function unitCoaDestroy(Request $request, BsCogsUnitTypeAccount $unitCoa): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $unitCoa->delete();

        return back()->with('success', 'COA jenis unit dihapus.');
    }

    // ── Budget (manual) ──
    public function budgetIndex(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cogs'), 403);
        $year = (int) $request->integer('year', (int) now()->year);

        $rows = BsCogsBudget::with(['category:id,code,name,component', 'location:id,code,name', 'account:id,code,name'])
            ->where('year', $year)->orderBy('project_id')->orderBy('id')->get();

        return Inertia::render('BusinessSupport/Cogs/Setup/Budget', [
            'year'       => $year,
            'rows'       => $rows,
            'projects'   => $this->projects(),
            'locations'  => BsCogsLocation::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'categories' => BsCogsCategory::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'component', 'default_account_id']),
            'accounts'   => $this->accounts(),
            'total'      => (float) $rows->sum('amount'),
        ]);
    }

    public function budgetStore(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $v = $this->budgetRules($request);
        BsCogsBudget::create(array_merge($v, ['tenant_id' => tenant_id(), 'created_by' => $request->user()->id]));

        return back()->with('success', 'Budget ditambahkan.');
    }

    public function budgetUpdate(Request $request, BsCogsBudget $budget): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $budget->update($this->budgetRules($request));

        return back()->with('success', 'Budget diperbarui.');
    }

    public function budgetDestroy(Request $request, BsCogsBudget $budget): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $budget->delete();

        return back()->with('success', 'Budget dihapus.');
    }

    private function budgetRules(Request $request): array
    {
        return $request->validate([
            'year'        => ['required', 'integer', 'min:2000', 'max:2100'],
            'project_id'  => ['required', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'category_id' => ['required', 'integer'],
            'account_id'  => ['nullable', 'integer'],
            'amount'      => ['required', 'numeric', 'min:0'],
            'notes'       => ['nullable', 'string', 'max:200'],
        ]);
    }

    // ── Uploads (CSV) ──
    public function uploadForm(Request $request, string $kind): Response
    {
        abort_unless($request->user()?->canModule('cogs'), 403);
        $meta = [
            'kategori' => ['title' => 'Upload Kategori Budget', 'header' => ['code', 'name', 'component'], 'route' => 'bs.cogs.upload.kategori.store'],
            'akun'     => ['title' => 'Upload Akun Budget', 'header' => ['category_code', 'account_code'], 'route' => 'bs.cogs.upload.akun.store'],
            'nilai'    => ['title' => 'Upload Nilai Budget', 'header' => ['year', 'project_code', 'location_code', 'category_code', 'amount'], 'route' => 'bs.cogs.upload.nilai.store'],
        ][$kind] ?? abort(404);

        return Inertia::render('BusinessSupport/Cogs/Setup/Upload', ['kind' => $kind, 'meta' => $meta]);
    }

    public function uploadKategori(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        return $this->processUpload($request, function (array $r): void {
            $code = trim((string) ($r[0] ?? ''));
            if ($code === '') {
                return;
            }
            BsCogsCategory::updateOrCreate(['tenant_id' => tenant_id(), 'code' => $code], [
                'name' => $r[1] ?? $code, 'component' => in_array($r[2] ?? '', array_keys(BsCogsCategory::COMPONENTS), true) ? $r[2] : 'bangunan',
            ]);
        });
    }

    public function uploadAkun(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        return $this->processUpload($request, function (array $r): void {
            $cat = BsCogsCategory::where('code', trim((string) ($r[0] ?? '')))->first();
            $acc = BsAccount::where('code', trim((string) ($r[1] ?? '')))->first();
            if ($cat && $acc) {
                $cat->update(['default_account_id' => $acc->id]);
            }
        });
    }

    public function uploadNilai(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        return $this->processUpload($request, function (array $r): void {
            $year = (int) ($r[0] ?? now()->year);
            $proj = Project::where('code', trim((string) ($r[1] ?? '')))->first();
            if (! $proj) {
                return;
            }
            $loc  = BsCogsLocation::where('code', trim((string) ($r[2] ?? '')))->first();
            $cat  = BsCogsCategory::where('code', trim((string) ($r[3] ?? '')))->first();
            if (! $cat) {
                return;
            }
            $amount = round((float) preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string) ($r[4] ?? 0))), 2);
            BsCogsBudget::updateOrCreate(
                ['tenant_id' => tenant_id(), 'year' => $year, 'project_id' => $proj->id, 'location_id' => $loc?->id, 'category_id' => $cat->id],
                ['account_id' => $cat->default_account_id, 'amount' => $amount],
            );
        });
    }

    private function processUpload(Request $request, callable $rowFn): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:4096']]);
        $rows = array_map('str_getcsv', file($request->file('file')->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        if (! $rows) {
            return back()->with('error', 'File kosong.');
        }
        // Lewati header bila kolom pertama bukan angka/kode yang dikenal (heuristik: ada huruf "code"/"kode"/"year").
        $first = strtolower(trim((string) ($rows[0][0] ?? '')));
        if (in_array($first, ['code', 'kode', 'year', 'tahun', 'category_code', 'project_code'], true)) {
            array_shift($rows);
        }
        $n = 0;
        DB::transaction(function () use ($rows, $rowFn, &$n): void {
            foreach ($rows as $r) {
                $rowFn($r);
                $n++;
            }
        });

        return back()->with('success', "$n baris diproses.");
    }
}
