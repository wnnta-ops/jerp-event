<?php

declare(strict_types=1);

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Engine\ApprovalChainResolver;
use App\Domain\Approval\Models\Approval;
use App\Domain\Approval\Models\ApprovalStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class SubmitForApproval
{
    public function __construct(private readonly ApprovalChainResolver $resolver) {}

    /**
     * @param Model $approvable Entity yang butuh approval (booking, refund, dll).
     * @param array<string, mixed> $payload Snapshot + context (akan disimpan ke approvals.payload).
     * @param array<int, int> $assignees level => user id, untuk chain step ber-approver_type
     *   'assigned' (approver dipilih saat pengajuan, mis. Dept Head si pengunggah berkas).
     * @param ?string $chainDocumentType Chain milik jenis dokumen lain (mis. Pengajuan Budget Invoice
     *   memakai chain Invoice Hutang) — null = chain jenis dokumen ini sendiri.
     */
    public function execute(string $documentType, Model $approvable, array $payload = [], array $assignees = [], ?string $chainDocumentType = null): Approval
    {
        return DB::transaction(function () use ($documentType, $approvable, $payload, $assignees, $chainDocumentType): Approval {
            $chain = $this->resolver->resolve($chainDocumentType ?? $documentType, $payload);

            $approval = Approval::create([
                'approval_chain_id' => $chain->id,
                'document_type'     => $documentType,
                'approvable_type'   => $approvable->getMorphClass(),
                'approvable_id'     => $approvable->getKey(),
                'payload'           => $payload,
                'status'            => 'pending',
                'submitted_by'      => auth()->id(),
                'submitted_at'      => now(),
            ]);

            foreach ($chain->load('steps')->steps as $step) {
                $assigned = $step->approver_type === 'assigned' ? ($assignees[$step->level] ?? null) : null;
                if ($step->approver_type === 'assigned' && ! $assigned) {
                    throw new \App\Exceptions\BusinessRuleException(
                        "Approver level {$step->level} harus dipilih saat pengajuan.",
                        errorCode: 'APPROVAL_ASSIGNEE_REQUIRED',
                    );
                }

                ApprovalStep::create([
                    'approval_id' => $approval->id,
                    'level'       => $step->level,
                    'approver_id' => $assigned,   // 'assigned' = ditunjuk sekarang; lainnya di-assign saat approver acts
                    // Snapshot: approval ini tetap mengikuti susunan chain saat diajukan.
                    'approver_type'  => $step->approver_type,
                    'approver_value' => $step->approver_value,
                    'label'          => $step->label,
                    'status'      => 'pending',
                ]);
            }

            event(new \App\Domain\Approval\Events\ApprovalSubmitted($approval));

            return $approval;
        });
    }
}
