<?php

declare(strict_types=1);

namespace App\Domain\ApprovalInbox\Actions;

use App\Domain\Approval\Actions\SubmitForApproval;
use App\Domain\ApprovalInbox\Models\MemoInternal;
use App\Domain\Foundation\Numerator\Services\NumeratorService;
use Illuminate\Support\Facades\DB;

/**
 * Buat Memo Internal lalu langsung ajukan ke rantai approval 'memo_internal'.
 * Pengaju (Dept Head) = tahap 1 alur; tahap berikutnya ada di approval_chain_steps.
 */
final class SubmitMemoInternal
{
    public function __construct(
        private readonly NumeratorService $numerator,
        private readonly SubmitForApproval $submit,
    ) {}

    /**
     * @param array{memo_date:string, department:string, supplier_id:int, subject:string, body:string, amount?:float|null} $data
     */
    public function execute(array $data): MemoInternal
    {
        return DB::transaction(function () use ($data): MemoInternal {
            $memo = MemoInternal::create([
                ...$data,
                'memo_number' => $this->numerator->next('MI'),
                'status' => 'diajukan',
                'created_by' => auth()->id(),
            ]);

            $approval = $this->submit->execute(MemoInternal::DOCUMENT_TYPE, $memo, [
                'memo_number' => $memo->memo_number,
                'subject' => $memo->subject,
                'department' => $memo->department,
                'supplier' => $memo->supplier?->name,
                'amount' => $memo->amount !== null ? (float) $memo->amount : null,
            ]);

            $memo->update(['approval_id' => $approval->id]);

            return $memo->refresh();
        });
    }
}
