<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\DataCenter;

use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Domain\BusinessSupport\Models\BsSupplierCategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/** Supplier Center: Show/360, Laporan Master, Merge, Upload, Repair, Kategori. */
class SupplierCenterController extends Controller
{
    /** Laporan Master Supplier. */
    public function report(Request $request): Response
    {
        abort_unless($request->user()?->can('datacenter.supplier.report'), 403);

        $rows = BsSupplier::query()
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")->orWhere('city', 'like', "%{$s}%")))
            ->when($request->input('status') !== null && $request->input('status') !== '', fn ($q) => $q->where('is_active', $request->boolean('status')))
            ->orderBy('code')->get(['id', 'code', 'name', 'category', 'city', 'phone', 'npwp', 'bank_name', 'bank_account_no', 'is_active']);

        return Inertia::render('BusinessSupport/DataCenter/Supplier/Report', [
            'rows' => $rows, 'filters' => $request->only(['search', 'status']),
            'summary' => ['total' => $rows->count(), 'active' => $rows->where('is_active', true)->count()],
        ]);
    }

    /** Supplier 360 — profil + agregasi transaksi (AP invoice / PO / SPK). */
    public function show(Request $request, BsSupplier $supplier): Response
    {
        abort_unless($request->user()?->can('datacenter.supplier.show'), 403);

        $agg = function (string $table, string $amountCol = 'net_payable') use ($supplier): array {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'supplier_id')) {
                return ['count' => 0, 'total' => 0.0];
            }
            $q = DB::table($table)->where('supplier_id', $supplier->id);
            if (Schema::hasColumn($table, 'deleted_at')) {
                $q->whereNull('deleted_at');
            }
            $total = Schema::hasColumn($table, $amountCol) ? (float) $q->sum($amountCol) : 0.0;

            return ['count' => (clone $q)->count(), 'total' => round($total, 2)];
        };

        return Inertia::render('BusinessSupport/DataCenter/Supplier/Show', [
            'supplier' => $supplier->load('categoryRef:id,code,name'),
            'stats'    => [
                'ap'  => $agg('bs_ap_invoices', 'net_payable'),
                'po'  => $agg('bs_purchase_orders', 'total'),
                'spk' => $agg('bs_work_orders', 'contract_value'),
            ],
        ]);
    }

    // ── Kategori Supplier (master) ──
    public function categoryIndex(Request $request): Response
    {
        abort_unless($request->user()?->can('datacenter.supplier.category.view'), 403);

        return Inertia::render('BusinessSupport/DataCenter/Supplier/Category', [
            'rows' => BsSupplierCategory::orderBy('code')->get(),
        ]);
    }

    public function categoryStore(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        BsSupplierCategory::create(array_merge($this->catRules($request), ['tenant_id' => tenant_id()]));

        return back()->with('success', 'Kategori supplier ditambahkan.');
    }

    public function categoryUpdate(Request $request, BsSupplierCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $category->update($this->catRules($request));

        return back()->with('success', 'Kategori supplier diperbarui.');
    }

    public function categoryDestroy(Request $request, BsSupplierCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $category->delete();

        return back()->with('success', 'Kategori supplier dihapus.');
    }

    private function catRules(Request $request): array
    {
        return $request->validate(['code' => ['required', 'string', 'max:40'], 'name' => ['required', 'string', 'max:150'], 'is_active' => ['nullable', 'boolean'], 'notes' => ['nullable', 'string', 'max:200']]);
    }

    // ── Merge Supplier ──
    public function mergeForm(Request $request): Response
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        return Inertia::render('BusinessSupport/DataCenter/Supplier/Merge', [
            'suppliers' => BsSupplier::orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function mergeApply(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $v = $request->validate(['source_id' => ['required', 'integer', 'different:target_id', 'exists:bs_suppliers,id'], 'target_id' => ['required', 'integer', 'exists:bs_suppliers,id']]);

        $moved = $this->remapId('supplier_id', (int) $v['source_id'], (int) $v['target_id']);
        BsSupplier::where('id', $v['source_id'])->delete();

        return to_route('bs.suppliers.index')->with('success', "Supplier digabung — $moved referensi dipindahkan, sumber dinonaktifkan.");
    }

    /** Remap kolom *_id dari source→target di tabel-tabel konsumen yang memilikinya. */
    private function remapId(string $column, int $source, int $target): int
    {
        $moved = 0;
        $candidates = ['bs_ap_invoices', 'bs_purchase_orders', 'bs_work_orders', 'bs_goods_receipts', 'bs_ap_payments', 'bs_material_requests'];
        foreach ($candidates as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                $moved += DB::table($table)->where($column, $source)->update([$column => $target]);
            }
        }

        return $moved;
    }

    // ── Upload Supplier (CSV) ──
    public function uploadForm(Request $request): Response
    {
        abort_unless($request->user()?->can('datacenter.supplier.upload'), 403);

        return Inertia::render('BusinessSupport/DataCenter/Supplier/Upload', [
            'header' => ['code', 'name', 'category', 'phone', 'email', 'city', 'npwp', 'bank_name', 'bank_account_no', 'bank_account_name'],
        ]);
    }

    public function template(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('datacenter.supplier.upload'), 403);
        $csv = "code,name,category,phone,email,city,npwp,bank_name,bank_account_no,bank_account_name\nSUP-9001,CV Contoh,material,0811,a@b.c,Sidoarjo,1234567890123456,BCA,123456,CV Contoh\n";

        return response($csv)->header('Content-Type', 'text/csv')->header('Content-Disposition', 'attachment; filename="template_supplier.csv"');
    }

    public function upload(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:4096']]);
        $rows = array_map('str_getcsv', file($request->file('file')->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        if (! $rows) {
            return back()->with('error', 'File kosong.');
        }
        if (strtolower(trim((string) ($rows[0][0] ?? ''))) === 'code') {
            array_shift($rows);
        }
        $n = 0;
        DB::transaction(function () use ($rows, &$n): void {
            foreach ($rows as $r) {
                $name = trim((string) ($r[1] ?? ''));
                if ($name === '') {
                    continue;
                }
                $code = trim((string) ($r[0] ?? '')) ?: 'SUP-'.str_pad((string) (BsSupplier::withTrashed()->count() + 1), 4, '0', STR_PAD_LEFT);
                BsSupplier::updateOrCreate(['tenant_id' => tenant_id(), 'code' => $code], [
                    'name' => $name, 'category' => $r[2] ?? null, 'phone' => $r[3] ?? null, 'email' => $r[4] ?? null,
                    'city' => $r[5] ?? null, 'npwp' => preg_replace('/\D/', '', (string) ($r[6] ?? '')) ?: null,
                    'bank_name' => $r[7] ?? null, 'bank_account_no' => $r[8] ?? null, 'bank_account_name' => $r[9] ?? null, 'is_active' => true,
                ]);
                $n++;
            }
        });

        return back()->with('success', "$n supplier diproses.");
    }

    // ── Repair Data Supplier ──
    public function repair(Request $request): Response
    {
        abort_unless($request->user()?->can('datacenter.supplier.repair'), 403);

        $dupCodes = BsSupplier::select('code')->groupBy('code')->havingRaw('COUNT(*) > 1')->pluck('code');
        $noCode   = BsSupplier::whereNull('code')->orWhere('code', '')->count();
        $noBank   = BsSupplier::where(fn ($q) => $q->whereNull('bank_account_no')->orWhere('bank_account_no', ''))->count();

        return Inertia::render('BusinessSupport/DataCenter/Supplier/Repair', [
            'issues' => [
                ['key' => 'dup_code', 'label' => 'Kode supplier duplikat', 'count' => $dupCodes->count()],
                ['key' => 'no_code', 'label' => 'Supplier tanpa kode', 'count' => $noCode],
                ['key' => 'no_bank', 'label' => 'Tanpa rekening bank', 'count' => $noBank],
            ],
        ]);
    }

    public function repairRun(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $fixed = 0;
        DB::transaction(function () use (&$fixed): void {
            foreach (BsSupplier::where(fn ($q) => $q->whereNull('code')->orWhere('code', ''))->get() as $s) {
                $s->update(['code' => 'SUP-'.str_pad((string) $s->id, 4, '0', STR_PAD_LEFT)]);
                $fixed++;
            }
        });

        return back()->with('success', "Repair selesai — $fixed kode supplier dibuatkan.");
    }
}
