<?php

declare(strict_types=1);

namespace App\Domain\ApprovalInbox\Services;

use App\Domain\ApprovalInbox\Models\MemoInternal;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use Illuminate\Support\Collection;

/**
 * Memo Urgent yang ditarik ke Registrasi Invoice Hutang (satu memo per invoice).
 *
 * Yang bisa ditarik: memo yang tahap DIREKTUR-nya sudah menyetujui (rantainya tetap berlanjut ke
 * MW / BA), belum ditolak, dan belum dipakai invoice lain. Status memo ikut tampil di daftar
 * invoice hutang.
 */
final class MemoUntukInvoice
{
    /** Level tahap Direktur di MemoInternal::STEPS. */
    public const LEVEL_DIREKTUR = 4;

    /**
     * @return Collection<int, array{id:int, number:string, date:?string, department:?string, subject:string, amount:float, status:string}>
     */
    public function pilihan(?int $kecualiInvoiceId = null): Collection
    {
        $terpakai = BsApInvoice::query()->whereNotNull('memo_internal_id')
            ->when($kecualiInvoiceId, fn ($q, $id) => $q->whereKeyNot($id))
            ->pluck('memo_internal_id')->all();

        return MemoInternal::query()
            ->where('status', '!=', 'ditolak')
            ->whereNotIn('id', $terpakai ?: [0])
            ->whereHas('approval.steps', fn ($q) => $q->where('level', self::LEVEL_DIREKTUR)->where('status', 'approved'))
            ->with('approval.steps')
            ->orderByDesc('memo_date')->orderByDesc('id')->get()
            ->map(fn (MemoInternal $m) => $this->ringkas($m))->values();
    }

    /** Boleh ditarik ke invoice ini? (aturan sama dengan pilihan()) */
    public function boleh(int $memoId, ?int $kecualiInvoiceId = null): bool
    {
        return $this->pilihan($kecualiInvoiceId)->contains('id', $memoId);
    }

    /** @return array{id:int, number:string, date:?string, department:?string, subject:string, amount:float, status:string} */
    public function ringkas(MemoInternal $m): array
    {
        return [
            'id' => $m->id, 'number' => $m->memo_number, 'date' => $m->memo_date?->toDateString(),
            'department' => $m->department, 'subject' => (string) $m->subject, 'amount' => (float) $m->amount,
            'status' => self::status($m),
            'url' => $m->approval_id ? route('approval-inbox.show', $m->approval_id) : null,
        ];
    }

    /** "Disetujui" / "Ditolak" / "Menunggu <tahap>" — tahap pertama yang belum disetujui. */
    public static function status(MemoInternal $m): string
    {
        if ($m->status === 'disetujui') {
            return 'Disetujui';
        }
        if ($m->status === 'ditolak') {
            return 'Ditolak';
        }
        $menunggu = $m->approval?->steps?->sortBy('level')->first(fn ($s) => $s->status !== 'approved');

        return $menunggu ? 'Menunggu '.(MemoInternal::STEPS[(int) $menunggu->level][1] ?? 'approval') : 'Diajukan';
    }
}
