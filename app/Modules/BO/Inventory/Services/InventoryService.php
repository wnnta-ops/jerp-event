<?php

namespace App\Modules\BO\Inventory\Services;

use App\Modules\BO\Inventory\Models\IvtItem;
use App\Modules\BO\Inventory\Models\IvtMovement;
use App\Services\Numerator\NumeratorServiceInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryService
{
    public function __construct(private readonly NumeratorServiceInterface $numerator) {}

    public function nextNumber(): string
    {
        return $this->numerator->next('IVT-MOV');
    }

    /**
     * Approve an FPB (goods request) so it can be posted.
     */
    public function approve(IvtMovement $movement): IvtMovement
    {
        if ($movement->status !== 'DRAFT') {
            throw new RuntimeException("Movement {$movement->no_movement} sudah {$movement->status}.");
        }

        $movement->update(['status' => 'APPROVED', 'approved_by' => Auth::id()]);

        return $movement->refresh();
    }

    /**
     * Apply a movement to stock atomically (row-lock on the item). FPB must be
     * APPROVED first; other types post directly from DRAFT.
     */
    public function post(IvtMovement $movement): IvtMovement
    {
        if ($movement->applied) {
            throw new RuntimeException("Movement {$movement->no_movement} sudah diterapkan.");
        }
        if ($movement->requiresApproval() && $movement->status !== 'APPROVED') {
            throw new RuntimeException("FPB {$movement->no_movement} harus di-approve dulu.");
        }
        if (! in_array($movement->status, ['DRAFT', 'APPROVED'], true)) {
            throw new RuntimeException("Movement {$movement->no_movement} berstatus {$movement->status}.");
        }

        return DB::transaction(function () use ($movement) {
            /** @var IvtItem $item */
            $item = IvtItem::query()->lockForUpdate()->findOrFail($movement->item_id);

            $delta = $movement->arah === 'MASUK' ? (float) $movement->qty : -(float) $movement->qty;
            $newStock = (float) $item->stok + $delta;

            if ($newStock < 0) {
                throw new RuntimeException(
                    "Stok {$item->kode} tidak cukup: tersedia {$item->stok}, diminta {$movement->qty}."
                );
            }

            $item->update(['stok' => $newStock]);
            $movement->update(['status' => 'POSTED', 'applied' => true]);

            return $movement->refresh();
        });
    }

    /**
     * Reverse a posted movement (restores stock).
     */
    public function void(IvtMovement $movement): IvtMovement
    {
        if (! $movement->applied) {
            $movement->update(['status' => 'BATAL']);

            return $movement->refresh();
        }

        return DB::transaction(function () use ($movement) {
            $item = IvtItem::query()->lockForUpdate()->findOrFail($movement->item_id);
            $delta = $movement->arah === 'MASUK' ? -(float) $movement->qty : (float) $movement->qty;
            $item->update(['stok' => (float) $item->stok + $delta]);
            $movement->update(['status' => 'BATAL', 'applied' => false]);

            return $movement->refresh();
        });
    }
}
