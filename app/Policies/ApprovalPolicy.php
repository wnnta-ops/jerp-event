<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\FO\Approval\Models\Approval;
use App\Modules\FO\Approval\Models\ApprovalSetup;

/**
 * Otorisasi Approval — SIAPA boleh bertindak (bukan validasi STATE; state dicek
 * di ApprovalService). Konfigurasi di config/approval.php.
 *
 *  - super_admin selalu boleh (override administratif).
 *  - SETUP APPROVAL (menu Approval > Setup Approval): bila user L1/L2 DITUNJUK
 *    untuk jenis tsb, HANYA user itu yang berhak pada levelnya (penunjukan =
 *    pemberian hak, permission granular tak lagi dicek). Separation of duties
 *    tetap berlaku.
 *  - Tanpa penunjukan: aktor wajib punya permission `.approve` sesuai jenis
 *    (config jenis_permission); persetujuan FINAL hanya role final_roles.
 *  - Separation of duties: pemohon tak boleh menyetujui sendiri; approver L1 tak
 *    boleh sekaligus jadi approver final (kecuali APPROVAL_ALLOW_SELF=true).
 */
class ApprovalPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('super_admin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Approval $a): bool
    {
        return true;
    }

    public function approveLevel1(User $user, Approval $a): bool
    {
        // Ada user L1 yang DITUNJUK → hanya dia (penunjukan = pemberian hak).
        if ($assigned = ApprovalSetup::forJenis($a->jenis)?->level1_user_id) {
            return $this->notRequester($user, $a) && (int) $user->id === (int) $assigned;
        }

        return $this->notRequester($user, $a) && $this->canActOnJenis($user, $a);
    }

    public function approveLevel2(User $user, Approval $a): bool
    {
        // Ada user L2 yang DITUNJUK → hanya dia (SoD tetap berlaku).
        if ($assigned = ApprovalSetup::forJenis($a->jenis)?->level2_user_id) {
            return $this->notRequester($user, $a)
                && $this->notLevel1Approver($user, $a)
                && (int) $user->id === (int) $assigned;
        }

        return $this->notRequester($user, $a)
            && $this->notLevel1Approver($user, $a)
            && $this->canActOnJenis($user, $a)
            && $this->isFinalApprover($user);
    }

    public function reject(User $user, Approval $a): bool
    {
        // Penunjukan per level: yang berhak menolak = user yang ditunjuk pada level itu.
        $setup = ApprovalSetup::forJenis($a->jenis);
        $assigned = (int) $a->current_level === 1 ? $setup?->level1_user_id : $setup?->level2_user_id;
        if ($assigned) {
            return (int) $user->id === (int) $assigned;
        }

        if (! $this->canActOnJenis($user, $a)) {
            return false;
        }

        // Penolakan di level final hanya oleh final approver; di L1 cukup approver L1.
        return (int) $a->current_level === 1 || $this->isFinalApprover($user);
    }

    private function canActOnJenis(User $user, Approval $a): bool
    {
        $map = (array) config('approval.jenis_permission', []);
        $perm = $map[$a->jenis] ?? config('approval.fallback_permission');

        return is_string($perm) && $perm !== '' ? $user->can($perm) : false;
    }

    private function isFinalApprover(User $user): bool
    {
        $roles = (array) config('approval.final_roles', []);

        return $roles === [] ? true : $user->hasAnyRole($roles);
    }

    private function notRequester(User $user, Approval $a): bool
    {
        return (bool) config('approval.allow_self_approval')
            || (int) $a->requested_by !== (int) $user->id;
    }

    private function notLevel1Approver(User $user, Approval $a): bool
    {
        return (bool) config('approval.allow_self_approval')
            || (int) $a->level1_by !== (int) $user->id;
    }
}
