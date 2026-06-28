<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Sales;

use App\Domain\Sales\Booking\Enums\BookingStatus;
use App\Domain\Sales\Booking\Services\BookingService;
use App\Domain\Sales\Contract\Actions\CompletePpjb;
use App\Domain\Sales\Contract\Actions\PrintPpjb;
use App\Domain\Sales\Contract\Actions\StartPpjbSigning;
use App\Domain\Sales\Contract\Actions\UpdateContractDocument;
use App\Domain\Sales\Contract\Enums\DocumentType;
use App\Domain\Sales\Contract\Enums\PpjbStatus;
use App\Domain\Sales\Contract\Models\Contract;
use App\Domain\Sales\Pricing\Actions\ApplyGimmickPackage;
use App\Domain\Sales\Pricing\Actions\ToggleGimmickStatus;
use App\Domain\Sales\Pricing\Models\ContractGimmick;
use App\Domain\Sales\Pricing\Models\GimmickPackage;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContractController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('sales.contract.view'), 403);

        $rows = Contract::query()
            ->with([
                'booking:id,booking_number,customer_id,unit_id',
                'booking.customer:id,code,full_name',
                'booking.unit:id,unit_code',
            ])
            ->when($request->input('search'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('contract_number', 'like', "%{$term}%")
                ->orWhereHas('booking.customer', fn ($c) => $c->where('full_name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"))))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->latest()
            ->paginate(50)->withQueryString();

        return Inertia::render('Sales/Contract/Index', [
            'contracts' => $rows,
            'filters'   => $request->only(['search', 'status']),
        ]);
    }

    public function show(Contract $contract): Response
    {
        abort_unless(auth()->user()?->can('sales.contract.view'), 403);

        // Ensure all document rows exist
        $contract->ensureDocumentRows();

        $contract->load([
            'booking.customer', 'booking.unit', 'booking.paymentScheme',
            'clauses', 'documents.uploadedBy',
            'gimmicks.gimmick', 'gimmicks.package',
        ]);

        $compliance = $contract->checkDocumentCompliance('ppjb_sign');

        $availablePackages = GimmickPackage::active()
            ->with(['items' => fn ($q) => $q->select(['gimmicks.id', 'code', 'name', 'harga_satuan'])])
            ->select(['id', 'code', 'name', 'cluster_code'])
            ->get()
            ->map(fn ($pkg) => [
                'id' => $pkg->id,
                'code' => $pkg->code,
                'name' => $pkg->name,
                'cluster_code' => $pkg->cluster_code,
                'item_count' => $pkg->items->count(),
                'total_value' => $pkg->total_value,
            ]);

        // Prev / Next for navigation
        $prev = Contract::query()->where('id', '<', $contract->id)->orderByDesc('id')->select(['id', 'contract_number'])->first();
        $next = Contract::query()->where('id', '>', $contract->id)->orderBy('id')->select(['id', 'contract_number'])->first();

        return Inertia::render('Sales/Contract/Show', [
            'contract'   => $contract,
            'ppjb_statuses'  => PpjbStatus::options(),
            'document_types' => DocumentType::options(),
            'compliance' => $compliance,
            'effective_ppjb_number' => $contract->effective_ppjb_number,
            'is_kpr'     => $contract->is_kpr,
            'prev_id'    => $prev?->id,
            'prev_label' => $prev?->contract_number,
            'next_id'    => $next?->id,
            'next_label' => $next?->contract_number,
            'available_packages' => $availablePackages,
            'total_gimmick_cost' => $contract->total_gimmick_cost,
        ]);
    }

    // ── Phase 5: Gimmick Actions ──────────────────────────

    public function applyGimmickPackage(Request $request, Contract $contract, ApplyGimmickPackage $action): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.contract.sign'), 403);

        $data = $request->validate([
            'gimmick_package_id' => ['required', 'integer', 'exists:gimmick_packages,id'],
            'replace_existing'   => ['nullable', 'boolean'],
        ]);

        $package = GimmickPackage::with('items')->findOrFail($data['gimmick_package_id']);

        $items = $action->execute($contract, $package, (bool) ($data['replace_existing'] ?? false));

        return back()->with('success', "Paket {$package->name} berhasil di-apply: {$items->count()} gimmick.");
    }

    public function toggleGimmickDiterima(Request $request, ContractGimmick $gimmick, ToggleGimmickStatus $action): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.contract.sign'), 403);

        $data = $request->validate([
            'diterima'     => ['required', 'boolean'],
            'tgl_diterima' => ['nullable', 'date'],
        ]);

        $action->setDiterima($gimmick, (bool) $data['diterima'], $data['tgl_diterima'] ?? null);

        return back()->with('success', $data['diterima'] ? 'Gimmick ditandai diterima.' : 'Gimmick di-unmark.');
    }

    public function removeGimmick(Request $request, ContractGimmick $gimmick): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.contract.sign'), 403);

        $gimmick->delete();

        return back()->with('success', 'Gimmick dihapus dari kontrak.');
    }

    /**
     * Edit data kontrak (nomor, tanggal tanda tangan, catatan).
     */
    public function update(Request $request, Contract $contract): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.contract.sign'), 403);

        $data = $request->validate([
            'contract_number' => ['required', 'string', 'max:64', 'unique:contracts,contract_number,'.$contract->id],
            'signed_at'       => ['nullable', 'date'],
            'notes'           => ['nullable', 'string', 'max:2000'],
        ]);

        $contract->update($data);

        return back()->with('success', "Kontrak {$contract->contract_number} berhasil diperbarui.");
    }

    /**
     * Halaman cetak Form Kontrak (HTML printable, auto-trigger print dialog).
     */
    public function printForm(Request $request, Contract $contract): \Illuminate\View\View
    {
        abort_unless($request->user()?->can('sales.contract.view'), 403);

        $contract->load([
            'booking.customer', 'booking.unit', 'booking.paymentScheme',
            'clauses', 'gimmicks.gimmick',
        ]);

        return view('print.contract', [
            'contract'              => $contract,
            'effective_ppjb_number' => $contract->effective_ppjb_number,
            'company'               => config('domain.company', []),
        ]);
    }

    public function sign(Request $request, Contract $contract, BookingService $bookingService): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.contract.sign'), 403);

        if ($contract->status !== 'draft') {
            return back()->with('error', 'Kontrak sudah diproses.');
        }

        $contract->update(['status' => 'signed', 'signed_at' => now()->toDateString()]);

        // Promote booking ke ACTIVE setelah PPJB signed
        $booking = $contract->booking;
        if ($booking && $booking->status === BookingStatus::Ppjb) {
            $bookingService->transitionTo($booking, BookingStatus::Active, "PPJB {$contract->contract_number} signed");
        }

        return back()->with('success', "Kontrak {$contract->contract_number} berhasil di-sign.");
    }

    // ── Phase 4: PPJB Lifecycle Actions ──────────────────

    public function ppjbPrint(Request $request, Contract $contract, PrintPpjb $action): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.contract.sign'), 403);

        try {
            $action->execute($contract);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "PPJB {$contract->effective_ppjb_number} berhasil di-print.");
    }

    public function ppjbStartSigning(Request $request, Contract $contract, StartPpjbSigning $action): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.contract.sign'), 403);

        try {
            $action->execute($contract);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'PPJB siap untuk proses tanda tangan.');
    }

    public function ppjbComplete(Request $request, Contract $contract, CompletePpjb $action, BookingService $bookingService): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.contract.sign'), 403);

        $data = $request->validate([
            'signed_at' => ['nullable', 'date'],
        ]);

        try {
            $action->execute($contract, $data['signed_at'] ?? null);

            // Auto-promote booking ke Active
            $booking = $contract->booking;
            if ($booking && $booking->status === BookingStatus::Ppjb) {
                $bookingService->transitionTo($booking, BookingStatus::Active, "PPJB {$contract->effective_ppjb_number} signed");
            }
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "PPJB {$contract->effective_ppjb_number} selesai.");
    }

    public function updateNumbering(Request $request, Contract $contract): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.contract.sign'), 403);

        $data = $request->validate([
            'ppjb_manual_number'     => ['nullable', 'string', 'max:50'],
            'ppjb_use_manual_number' => ['boolean'],
        ]);

        $contract->update($data);

        return back()->with('success', 'Nomor PPJB diperbarui.');
    }

    public function toggleDocument(Request $request, Contract $contract, UpdateContractDocument $action): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.contract.sign'), 403);

        $data = $request->validate([
            'document_type' => ['required', 'string'],
            'is_present'    => ['required', 'boolean'],
            'notes'         => ['nullable', 'string', 'max:500'],
        ]);

        $type = DocumentType::tryFrom($data['document_type']);
        if (! $type) {
            return back()->with('error', 'Tipe dokumen tidak valid.');
        }

        $action->execute($contract, $type, (bool) $data['is_present'], $data['notes'] ?? null);

        return back()->with('success', "Dokumen {$type->label()} ".( $data['is_present'] ? 'ditandai Ada' : 'ditandai Tidak Ada').".");
    }
}
