<?php

namespace App\Modules\FO\Approval\Services;

use App\Modules\FO\Approval\Models\Approval;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Workflow Approval 2-level:
 *   DRAFT → submit → PENDING(L1) → approveL1 → PENDING(L2) → approveL2 → APPROVED
 *         → eksekusi otomatis → EXECUTED ;  reject di level mana pun → REJECTED.
 *
 * Jenis SINGLE-LEVEL (langsung GM): submit → PENDING(L2/GM) → approveL2 → APPROVED.
 * Mis. DISKON_SPRS (diskon saat masih SPRS) cukup persetujuan GM saja (tanpa L1).
 */
class ApprovalService
{
    /** Jenis yang cukup 1 level persetujuan (langsung GM, lewati Level 1). */
    private const SINGLE_LEVEL = ['DISKON_SPRS'];

    public function __construct(private readonly ApprovalExecutionService $executor) {}

    public function submit(Approval $a): Approval
    {
        if ($a->status !== 'DRAFT') {
            throw new RuntimeException('Hanya approval DRAFT yang bisa diajukan.');
        }
        // Single-level (GM): mulai langsung di Level 2 (final) tanpa Level 1.
        $level = in_array($a->jenis, self::SINGLE_LEVEL, true) ? 2 : 1;
        $a->update(['status' => 'PENDING', 'current_level' => $level]);

        return $a->fresh();
    }

    /** Jenis ini hanya butuh persetujuan GM (single-level)? */
    public static function isSingleLevel(?string $jenis): bool
    {
        return in_array((string) $jenis, self::SINGLE_LEVEL, true);
    }

    public function approveLevel1(Approval $a, ?string $note = null): Approval
    {
        if (! $a->isAwaitingLevel1()) {
            throw new RuntimeException('Approval tidak sedang menunggu persetujuan Level 1.');
        }
        $a->update([
            'level1_by' => Auth::id(),
            'level1_at' => now(),
            'level1_note' => $note,
            'current_level' => 2,   // lanjut ke Level 2 (final)
        ]);

        return $a->fresh();
    }

    public function approveLevel2(Approval $a, ?string $note = null): Approval
    {
        if (! $a->isAwaitingLevel2()) {
            throw new RuntimeException('Approval tidak sedang menunggu persetujuan Level 2 (final).');
        }

        $a->update([
            'status' => 'APPROVED',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'catatan_approver' => $note,
        ]);

        // Eksekusi otomatis: terapkan perubahan ke Kontrak.
        $result = $this->executor->execute($a);
        $a->update(['status' => 'EXECUTED', 'executed_at' => now(), 'executed_result' => $result]);

        return $a->fresh();
    }

    public function reject(Approval $a, string $note): Approval
    {
        if ($a->status !== 'PENDING') {
            throw new RuntimeException('Hanya approval PENDING yang bisa ditolak.');
        }
        $field = (int) $a->current_level === 1 ? 'level1_note' : 'catatan_approver';
        $a->update([
            'status' => 'REJECTED',
            $field => $note,
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);

        return $a->fresh();
    }
}
