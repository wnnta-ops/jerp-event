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
     */
    public function execute(string $documentType, Model $approvable, array $payload = []): Approval
    {
        return DB::transaction(function () use ($documentType, $approvable, $payload): Approval {
            $chain = $this->resolver->resolve($documentType, $payload);

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
                ApprovalStep::create([
                    'approval_id' => $approval->id,
                    'level'       => $step->level,
                    'approver_id' => null,        // di-assign saat approver acts
                    'status'      => 'pending',
                ]);
            }

            // TODO: notify level-1 approver (in-app + WA)

            return $approval;
        });
    }
}
