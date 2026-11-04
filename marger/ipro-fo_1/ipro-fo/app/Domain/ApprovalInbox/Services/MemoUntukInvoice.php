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
 * invoice hutang. Memo menyebut supplier-nya: invoice yang menarik memo harus ke supplier yang
 * sama (memo lama tanpa supplier boleh ke supplier mana pun).
 */
final class MemoUntukInvoice
{
    /**
     * @return Collection<int, array{id:int, number:string, date:?string, department:?string, supplier_id:?int, supplier:?string, subject:string, amount:float, status:string}>
     */
    public function pilihan(?int $kecualiInvoiceId = null): Collection
    {
        $terpakai = BsApInvoice::query()->whereNotNull('memo_internal_id')
            ->when($kecualiInvoiceId, fn ($q, $id) => $q->whereKeyNot($id))
            ->pluck('memo_internal_id')->all();

        return $this->siapTarik($terpakai)
            // Memo Urgent baru boleh ditarik setelah DIBAYAR di Kuitansi Hutang (Bayar Memo Urgent).
            ->where(fn ($q) => $q->where('jenis', '!=', 'urgent')->orWhereIn('id', $this->memoDibayar()))
            ->with(['approval.steps', 'supplier:id,name'])
            ->orderByDesc('memo_date')->orderByDesc('id')->get()
            ->map(fn (MemoInternal $m) => $this->ringkas($m))->values();
    }

    /**
     * Memo Urgent yang sudah disetujui Direktur tapi BELUM dibayar — belum bisa ditarik; ditampilkan
     * di form invoice supaya pengguna tahu harus dibayar dulu di Kuitansi Hutang.
     *
     * @return Collection<int, array{number:string, subject:string}>
     */
    public function menungguBayar(): Collection
    {
        $terpakai = BsApInvoice::query()->whereNotNull('memo_internal_id')->pluck('memo_internal_id')->all();

        return $this->siapTarik($terpakai)->where('jenis', 'urgent')->whereNotIn('id', $this->memoDibayar())
            ->orderByDesc('memo_date')->get(['id', 'memo_number', 'subject'])
            ->map(fn (MemoInternal $m) => ['number' => $m->memo_number, 'subject' => (string) $m->subject])->values();
    }

    private function siapTarik(array $terpakai)
    {
        return MemoInternal::query()
            ->where('status', '!=', 'ditolak')
            ->whereNotIn('id', $terpakai ?: [0])
            ->lewatDirektur();
    }

    /** Memo yang sudah dibayar lewat Kuitansi Hutang (kuitansi aktif). */
    private function memoDibayar(): array
    {
        return \App\Domain\BusinessSupport\Models\BsApKuitansi::query()->whereNotNull('memo_internal_id')
            ->where('status', 'active')->pluck('memo_internal_id')->all() ?: [0];
    }

    /** Boleh ditarik ke invoice ini? (aturan sama dengan pilihan()) */
    public function boleh(int $memoId, ?int $kecualiInvoiceId = null): bool
    {
        return $this->pilihan($kecualiInvoiceId)->contains('id', $memoId);
    }

    /** Akun Hutang PPh per jenis — sama dengan pphDefaults di form invoice. */
    private const AKUN_PPH = ['pph21' => '20104040402', 'pph23' => '20104040403', 'pph4_2' => '20104040401'];

    /**
     * Rincian pajak memo DIKUNCI di invoice: Nilai Transaksi, PPN & PPh ditimpa dari memo, apa pun
     * kiriman layar. Memo lama tanpa rincian → data invoice tidak diubah.
     *
     * @param  array<string, mixed>  $v
     * @return array<string, mixed>
     */
    public function kunciPajak(int $memoId, array $v): array
    {
        $pajak = MemoInternal::query()->find($memoId)?->pajak();
        if (! $pajak) {
            return $v;
        }
        $v = array_merge($v, $pajak);
        $v['pph_account_id'] = $pajak['pph_type'] && isset(self::AKUN_PPH[$pajak['pph_type']])
            ? \App\Domain\BusinessSupport\Models\BsAccount::where('code', self::AKUN_PPH[$pajak['pph_type']])->value('id')
            : null;

        return $v;
    }

    /** Supplier memo cocok dengan supplier invoice? Memo tanpa supplier (memo lama) selalu cocok. */
    public function supplierCocok(int $memoId, ?int $supplierId): bool
    {
        $memoSupplier = MemoInternal::query()->whereKey($memoId)->value('supplier_id');

        return $memoSupplier === null || (int) $memoSupplier === (int) $supplierId;
    }

    /** @return array{id:int, number:string, date:?string, department:?string, supplier_id:?int, supplier:?string, subject:string, amount:float, status:string} */
    public function ringkas(MemoInternal $m): array
    {
        return [
            'id' => $m->id, 'number' => $m->memo_number, 'date' => $m->memo_date?->toDateString(),
            'department' => $m->department,
            'supplier_id' => $m->supplier_id ? (int) $m->supplier_id : null, 'supplier' => $m->supplier?->name,
            'subject' => (string) $m->subject, 'amount' => (float) $m->amount,
            'pajak' => $m->pajak(),
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

        return $menunggu ? 'Menunggu '.(MemoInternal::labelTahap($menunggu) ?? 'approval') : 'Diajukan';
    }
}
