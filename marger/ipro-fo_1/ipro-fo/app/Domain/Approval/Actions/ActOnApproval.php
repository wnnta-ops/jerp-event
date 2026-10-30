<?php

declare(strict_types=1);

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Models\Approval;
use App\Domain\Approval\Models\ApprovalAction;
use App\Domain\Approval\Models\ApprovalStep;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

final class ActOnApproval
{
    /** Jenis dokumen yang tidak boleh disetujui oleh pengajunya sendiri (kecuali SuperAdmin). */
    public const NO_SELF_APPROVAL = ['memo_internal', 'ap_berkas', 'ap_invoice'];

    /**
     * @return Approval refresh setelah aksi.
     */
    public function execute(Approval $approval, string $action, ?string $notes = null): Approval
    {
        if (! in_array($action, ['approve', 'reject', 'delegate'], true)) {
            throw new BusinessRuleException('Action tidak valid.', errorCode: 'INVALID_ACTION');
        }

        if ($approval->status !== 'pending' && $approval->status !== 'in_review') {
            throw new BusinessRuleException(
                "Approval sudah {$approval->status}.",
                errorCode: 'APPROVAL_ALREADY_COMPLETED',
            );
        }

        return DB::transaction(function () use ($approval, $action, $notes): Approval {
            $currentStep = ApprovalStep::query()
                ->where('approval_id', $approval->id)
                ->where('status', 'pending')
                ->orderBy('level')
                ->first();

            if (! $currentStep) {
                throw new BusinessRuleException('Tidak ada step yang menunggu approval.', errorCode: 'NO_PENDING_STEP');
            }

            $userId = auth()->id();
            $user   = auth()->user();

            // Validasi user berhak meng-approve step ini (cek approver_type + approver_value
            // di chain step). SuperAdmin selalu boleh (override administratif).
            $chainStep = DB::table('approval_chain_steps')
                ->where('approval_chain_id', $approval->approval_chain_id)
                ->where('level', $currentStep->level)
                ->first();

            $authorized = $user !== null && (
                $user->hasRole('SuperAdmin')
                || ($chainStep && $chainStep->approver_type === 'role' && $user->hasRole((string) $chainStep->approver_value))
                || ($chainStep && $chainStep->approver_type === 'user' && (int) $chainStep->approver_value === (int) $userId)
                // 'assigned' = approver ditunjuk per pengajuan (ApprovalStep.approver_id diisi saat submit).
                || ($chainStep && $chainStep->approver_type === 'assigned' && (int) $currentStep->approver_id === (int) $userId)
            );

            // Pembuat dokumen tidak boleh menyetujui/menolak dokumennya sendiri (Memo Internal,
            // Upload Berkas, Invoice dari berkas). Jenis lain tetap seperti sebelumnya.
            if ($authorized && in_array($approval->document_type, self::NO_SELF_APPROVAL, true)
                && (int) $approval->submitted_by === (int) $userId && ! $user->hasRole('SuperAdmin')) {
                throw new BusinessRuleException(
                    'Anda yang mengajukan dokumen ini — persetujuannya harus oleh orang lain.',
                    errorCode: 'APPROVAL_SELF',
                );
            }

            if (! $authorized) {
                $need = $chainStep?->approver_type === 'assigned'
                    ? 'penanda tangan yang ditunjuk'
                    : ($chainStep ? "{$chainStep->approver_type} {$chainStep->approver_value}" : 'approver yang sesuai');
                throw new BusinessRuleException(
                    "Anda tidak berhak meng-approve langkah ini (butuh {$need}).",
                    errorCode: 'APPROVAL_FORBIDDEN',
                );
            }

            $currentStep->update([
                'approver_id' => $userId,
                'status'      => $action === 'reject' ? 'rejected' : 'approved',
                'notes'       => $notes,
                'acted_at'    => now(),
            ]);

            ApprovalAction::create([
                'approval_step_id' => $currentStep->id,
                'action'           => $action,
                'actor_id'         => $userId,
                'notes'            => $notes,
                'acted_at'         => now(),
            ]);

            if ($action === 'reject') {
                $approval->update(['status' => 'rejected', 'completed_at' => now()]);
                event(new \App\Domain\Approval\Events\ApprovalRejected($approval));

                return $approval->refresh();
            }

            // Approved at current step. Cek apakah ada step berikutnya
            $next = ApprovalStep::query()
                ->where('approval_id', $approval->id)
                ->where('status', 'pending')
                ->orderBy('level')
                ->first();

            if (! $next) {
                $approval->update(['status' => 'approved', 'completed_at' => now()]);
                event(new \App\Domain\Approval\Events\ApprovalApproved($approval));
            } else {
                $approval->update(['status' => 'in_review']);
                event(new \App\Domain\Approval\Events\ApprovalStepApproved($approval));
            }

            return $approval->refresh();
        });
    }
}
