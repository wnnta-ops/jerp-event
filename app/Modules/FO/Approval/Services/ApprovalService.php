<?php

namespace App\Modules\FO\Approval\Services;

use App\Modules\FO\Approval\Models\Approval;
use App\Modules\FO\Approval\Models\ApprovalSetup;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Workflow Approval 2-level:
 *   DRAFT → submit → PENDING(L1) → approveL1 → PENDING(L2) → approveL2 → APPROVED
 *         → eksekusi otomatis → EXECUTED ;  reject di level mana pun → REJECTED.
 *
 * Jenis SINGLE-LEVEL (langsung GM): submit → PENDING(L2/GM) → approveL2 → APPROVED.
 * Mis. DISKON_SPRS (diskon saat masih SPRS) cukup persetujuan GM saja (tanpa L1).
 *
 * SETUP APPROVAL (fo_approval_setups) — penunjukan USER per jenis, mengalahkan
 * aturan di atas bila diisi:
 *   - L1 diisi, L2 kosong → single level: persetujuan user L1 LANGSUNG final
 *     & tereksekusi (tanpa menunggu L2).
 *   - L1 & L2 diisi → dua level berjalan penuh (L1 lalu L2 final).
 *   - Kosong semua → fallback aturan lama (permission + final_roles).
 *
 * Otorisasi (siapa boleh) ditegakkan via Gate (App\Policies\ApprovalPolicy);
 * integritas state + anti double-execute via lockForUpdate + transaksi tunggal.
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

        $setup = ApprovalSetup::forJenis($a->jenis);

        // BYPASS saat Event NUP: toggle jenis ini nyala DAN ada event NUP yang
        // sedang berlangsung → langsung tereksekusi TANPA approval (dicatat jelas).
        if ($setup?->shouldBypassNow()) {
            return DB::transaction(function () use ($a) {
                $a->update([
                    'status' => 'APPROVED',
                    'current_level' => 2,
                    'approved_by' => Auth::id(),
                    'approved_at' => now(),
                    'catatan_approver' => 'BYPASS otomatis — Event NUP sedang berlangsung (Setup Approval).',
                ]);
                $result = $this->executor->execute($a);
                $a->update(['status' => 'EXECUTED', 'executed_at' => now(), 'executed_result' => $result]);

                return $a->fresh();
            });
        }

        if ($setup?->hasAssignedUsers()) {
            // Setup Approval: mulai di L1 bila user L1 ditunjuk; bila hanya L2
            // yang diisi, langsung ke level final.
            $level = $setup->level1_user_id ? 1 : 2;
        } else {
            // Single-level (GM): mulai langsung di Level 2 (final) tanpa Level 1.
            $level = in_array($a->jenis, self::SINGLE_LEVEL, true) ? 2 : 1;
        }
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
        return DB::transaction(function () use ($a, $note) {
            $a = Approval::query()->whereKey($a->getKey())->lockForUpdate()->firstOrFail();

            if (! $a->isAwaitingLevel1()) {
                throw new RuntimeException('Approval tidak sedang menunggu persetujuan Level 1.');
            }
            Gate::authorize('approveLevel1', $a);

            // Setup Approval single level (L1 diisi, L2 kosong): persetujuan L1
            // LANGSUNG final + eksekusi — dalam transaksi yang sama (rollback utuh
            // bila eksekusi gagal), selaras jalur approveLevel2.
            $setup = ApprovalSetup::forJenis($a->jenis);
            if ($setup?->isSingleLevel()) {
                $a->update([
                    'level1_by' => Auth::id(),
                    'level1_at' => now(),
                    'level1_note' => $note,
                    'status' => 'APPROVED',
                    'approved_by' => Auth::id(),
                    'approved_at' => now(),
                    'catatan_approver' => $note,
                ]);
                $result = $this->executor->execute($a);
                $a->update(['status' => 'EXECUTED', 'executed_at' => now(), 'executed_result' => $result]);

                return $a->fresh();
            }

            $a->update([
                'level1_by' => Auth::id(),
                'level1_at' => now(),
                'level1_note' => $note,
                'current_level' => 2,   // lanjut ke Level 2 (final)
            ]);

            return $a->fresh();
        });
    }

    public function approveLevel2(Approval $a, ?string $note = null): Approval
    {
        return DB::transaction(function () use ($a, $note) {
            // Kunci baris: cegah dua persetujuan final paralel (double-execute).
            $a = Approval::query()->whereKey($a->getKey())->lockForUpdate()->firstOrFail();

            if (! $a->isAwaitingLevel2()) {
                throw new RuntimeException('Approval tidak sedang menunggu persetujuan Level 2 (final).');
            }
            Gate::authorize('approveLevel2', $a);

            $a->update([
                'status' => 'APPROVED',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
                'catatan_approver' => $note,
            ]);

            // Eksekusi otomatis di transaksi yang sama: bila gagal, status APPROVED
            // ikut rollback (tidak ada state APPROVED-tapi-tak-tereksekusi).
            $result = $this->executor->execute($a);
            $a->update(['status' => 'EXECUTED', 'executed_at' => now(), 'executed_result' => $result]);

            return $a->fresh();
        });
    }

    public function reject(Approval $a, string $note): Approval
    {
        return DB::transaction(function () use ($a, $note) {
            $a = Approval::query()->whereKey($a->getKey())->lockForUpdate()->firstOrFail();

            if ($a->status !== 'PENDING') {
                throw new RuntimeException('Hanya approval PENDING yang bisa ditolak.');
            }
            Gate::authorize('reject', $a);

            // Penolakan dicatat di kolom rejected_* (approved_* tetap murni penyetuju).
            $field = (int) $a->current_level === 1 ? 'level1_note' : 'catatan_approver';
            $a->update([
                'status' => 'REJECTED',
                $field => $note,
                'rejected_by' => Auth::id(),
                'rejected_at' => now(),
            ]);

            return $a->fresh();
        });
    }
}
