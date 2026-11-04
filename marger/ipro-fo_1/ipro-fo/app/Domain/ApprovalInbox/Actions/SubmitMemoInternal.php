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
     * @param array{memo_date:string, jenis:string, department:string, supplier_id:int, subject:string, body:string, amount?:float|null} $data
     */
    public function execute(array $data): MemoInternal
    {
        // Dengan rincian pajak: nominal memo = Dibayar (net) — dihitung di server, bukan dari layar.
        if (isset($data['dpp'])) {
            $data['amount'] = \App\Domain\BusinessSupport\Support\PajakHutang::hitung($data)['net'];
        }

        return DB::transaction(function () use ($data): MemoInternal {
            $memo = MemoInternal::create([
                ...$data,
                'jenis' => $data['jenis'] ?? 'urgent',
                'memo_number' => $this->numerator->next('MI'),
                'status' => 'diajukan',
                'created_by' => auth()->id(),
            ]);

            // 'jenis' juga konteks pemilihan chain (match_conditions {"jenis": "urgent"|"reguler"}).
            $approval = $this->submit->execute(MemoInternal::DOCUMENT_TYPE, $memo, [
                'jenis' => $memo->jenis,
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
