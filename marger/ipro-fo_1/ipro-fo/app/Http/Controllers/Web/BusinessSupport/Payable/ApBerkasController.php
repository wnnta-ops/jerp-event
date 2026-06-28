<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Payable;

use App\Domain\BusinessSupport\Models\BsApBerkas;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsApInvoiceBerkas;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ApBerkasController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        return Inertia::render('BusinessSupport/Payable/Berkas', [
            'berkas' => BsApBerkas::orderBy('name')->get(['id', 'code', 'name', 'is_mandatory', 'is_active']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        BsApBerkas::create($this->data($request));

        return back()->with('success', 'Kelengkapan berkas ditambahkan.');
    }

    public function update(Request $request, BsApBerkas $berkas): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $berkas->update($this->data($request, $berkas->id));

        return back()->with('success', 'Kelengkapan berkas diperbarui.');
    }

    public function destroy(Request $request, BsApBerkas $berkas): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $berkas->delete();

        return back()->with('success', 'Kelengkapan berkas dihapus.');
    }

    /** Toggle status lengkap berkas pada sebuah invoice. */
    public function toggle(Request $request, BsApInvoice $invoice, BsApBerkas $berkas): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        $row = BsApInvoiceBerkas::firstOrCreate(['ap_invoice_id' => $invoice->id, 'berkas_id' => $berkas->id], ['is_complete' => false]);
        $row->update(['is_complete' => ! $row->is_complete]);

        return back(303);
    }

    /** @return array<string,mixed> */
    private function data(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'code'         => ['required', 'string', 'max:40', Rule::unique('bs_ap_berkas', 'code')->ignore($id)->where('tenant_id', tenant_id())],
            'name'         => ['required', 'string', 'max:150'],
            'is_mandatory' => ['boolean'],
            'is_active'    => ['boolean'],
        ]);
    }
}
