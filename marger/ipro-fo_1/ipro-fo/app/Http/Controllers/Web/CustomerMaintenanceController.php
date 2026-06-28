<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Master\Customer\Services\CustomerImportService;
use App\Domain\Master\Customer\Services\CustomerMergeService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pemeliharaan Customer: Gabung (merge) & Upload (bulk import).
 * Mirror eProperty SALES → Customer → CustomerGabung / CustomerUpload.
 */
class CustomerMaintenanceController extends Controller
{
    public function __construct(
        private readonly CustomerMergeService $merge,
        private readonly CustomerImportService $import,
    ) {}

    // ── Gabung Customer ──
    public function mergeForm(Request $request): Response
    {
        abort_unless($request->user()?->can('master.customer.merge'), 403);

        return Inertia::render('Master/Customer/Merge', [
            'customers' => Customer::orderBy('full_name')->limit(2000)->get(['id', 'code', 'full_name', 'id_number']),
        ]);
    }

    /** Preview referensi (kontrak/booking/dll) milik customer (AJAX). */
    public function mergeReferences(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('master.customer.merge'), 403);
        $customer = Customer::findOrFail($request->integer('customer_id'));

        return response()->json(['references' => $this->merge->references($customer)]);
    }

    public function mergeApply(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('master.customer.merge'), 403);
        $v = $request->validate([
            'source_id' => ['required', 'integer', 'exists:customers,id', 'different:target_id'],
            'target_id' => ['required', 'integer', 'exists:customers,id'],
        ]);
        $source = Customer::findOrFail($v['source_id']);
        $target = Customer::findOrFail($v['target_id']);
        $moved  = $this->merge->merge($source, $target, $request->user()->id);
        $total  = array_sum($moved);

        return redirect()->route('customers.index')
            ->with('success', "Customer {$source->code} digabung ke {$target->code}. {$total} referensi dipindahkan.");
    }

    // ── Upload Customer ──
    public function uploadForm(Request $request): Response
    {
        abort_unless($request->user()?->can('master.customer.import'), 403);

        return Inertia::render('Master/Customer/Upload', [
            'columns' => CustomerImportService::COLUMNS,
            'result'  => session('import_result'),
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('master.customer.import'), 403);
        $request->validate([
            'file'    => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'dry_run' => ['boolean'],
        ]);
        $csv    = (string) file_get_contents($request->file('file')->getRealPath());
        $result = $this->import->import($csv, $request->boolean('dry_run'));

        $msg = $request->boolean('dry_run')
            ? "[Dry run] {$result['ok']} siap diimpor, {$result['skipped']} duplikat, ".count($result['failed']).' gagal.'
            : "{$result['ok']} customer diimpor, {$result['skipped']} duplikat dilewati, ".count($result['failed']).' gagal.';

        return redirect()->route('customers.upload')->with('import_result', $result)->with('success', $msg);
    }

    public function template(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('master.customer.import'), 403);
        $content = $this->import->template();

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, 'template_customer.csv', ['Content-Type' => 'text/csv']);
    }
}
