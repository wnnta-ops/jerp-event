<?php

namespace App\Modules\BO\PurchaseRequest\Services;

use App\Modules\BO\PurchaseRequest\Models\PurchaseOrder;
use App\Modules\BO\PurchaseRequest\Models\PurchaseRequest;
use App\Services\Numerator\NumeratorServiceInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PurchaseRequestService
{
    public function __construct(private readonly NumeratorServiceInterface $numerator) {}

    public function nextPrNumber(int $companyId): string
    {
        return $this->numerator->next('PR-BO', $companyId);
    }

    public function nextPoNumber(int $companyId): string
    {
        return $this->numerator->next('PO', $companyId);
    }

    /**
     * Two-step approval: first call → APPROVED_1, second → APPROVED.
     */
    public function approve(PurchaseRequest $pr): PurchaseRequest
    {
        if (! in_array($pr->status, ['DRAFT', 'APPROVED_1'], true)) {
            throw new RuntimeException("PR {$pr->no_pr} berstatus {$pr->status}, tidak bisa di-approve.");
        }

        $level = $pr->approval_level + 1;
        $pr->update([
            'approval_level' => $level,
            'status' => $level >= 2 ? 'APPROVED' : 'APPROVED_1',
        ]);

        return $pr->refresh();
    }

    public function reject(PurchaseRequest $pr, ?string $reason = null): PurchaseRequest
    {
        if (in_array($pr->status, ['PO', 'REJECTED'], true)) {
            throw new RuntimeException("PR {$pr->no_pr} berstatus {$pr->status}.");
        }

        $pr->update([
            'status' => 'REJECTED',
            'keterangan' => trim(($pr->keterangan ? $pr->keterangan.' | ' : '').'DITOLAK: '.($reason ?? '-')),
        ]);

        return $pr->refresh();
    }

    /**
     * Convert a fully-approved PR into a PO (copying its lines).
     */
    public function convertToPo(PurchaseRequest $pr, ?int $supplierId = null): PurchaseOrder
    {
        if ($pr->status !== 'APPROVED') {
            throw new RuntimeException("PR {$pr->no_pr} belum APPROVED (status {$pr->status}).");
        }

        return DB::transaction(function () use ($pr, $supplierId) {
            $po = PurchaseOrder::create([
                'no_po' => $this->nextPoNumber((int) $pr->company_id),
                'tgl' => now()->toDateString(),
                'company_id' => $pr->company_id,
                'pr_id' => $pr->id,
                'supplier_id' => $supplierId,
                'total' => $pr->estimasi_total,
                'status' => 'DRAFT',
            ]);

            foreach ($pr->lines as $line) {
                $po->lines()->create([
                    'urutan' => $line->urutan,
                    'deskripsi' => $line->deskripsi,
                    'qty' => $line->qty,
                    'satuan' => $line->satuan,
                    'harga' => $line->harga_estimasi,
                    'subtotal' => $line->subtotal,
                ]);
            }

            $pr->update(['status' => 'PO', 'po_id' => $po->id]);

            return $po->load('lines');
        });
    }
}
