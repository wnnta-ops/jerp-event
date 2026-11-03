<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\ApprovalInbox\Models\MemoInternal;
use App\Domain\ApprovalInbox\Services\MemoUntukInvoice;
use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsApKuitansi;
use App\Domain\BusinessSupport\Models\BsApKuitansiLine;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Memo Urgent dibayar di Registrasi Pengeluaran Kas & Bank (AP) SEBELUM invoice hutangnya ada.
 *
 *   Bayar memo : Dr Uang Muka (dipilih kasir, 10107…) / Cr Kas/Bank — kuitansi langsung aktif
 *                (memo sudah disetujui sampai Direktur; TTD MW / BA menyusul di cetakan).
 *   Invoice    : WAJIB menyusul, menarik memo itu (total ≥ yang sudah dibayar). Saat invoice dibayar
 *                di Kuitansi, uang muka memo WAJIB dipotong: Dr Hutang / Cr Uang Muka (+ Cr Kas sisanya).
 *   Penegakan  : pengaju yang punya memo dibayar tanpa invoice lewat N hari (parameter
 *                memo.batas_hari_invoice) tidak bisa mengajukan memo baru.
 *
 * Satu memo dibayar sekali, paling banyak sebesar nominal memo.
 */
final class PembayaranMemoUrgent
{
    public const BATAS_HARI_DEFAULT = 14;

    /** Akun uang muka yang boleh didebit saat membayar memo (UANG MUKA … di aset lancar). */
    public const PREFIX_AKUN_UANG_MUKA = '10107';

    public function __construct(private readonly JournalService $journal) {}

    /** Akun uang muka yang bisa dipilih kasir. */
    public function akunUangMuka(): Collection
    {
        return BsAccount::query()->where('is_active', true)->where('is_postable', true)
            ->where('code', 'like', self::PREFIX_AKUN_UANG_MUKA.'%')->orderBy('code')->get(['id', 'code', 'name']);
    }

    /**
     * Memo yang bisa dibayar: tahap Direktur sudah menyetujui, belum ditolak, menyebut supplier,
     * belum dibayar, dan belum ditarik ke invoice hutang (yang sudah punya invoice dibayar lewat invoicenya).
     */
    public function bisaDibayar(): Collection
    {
        $dibayar = BsApKuitansi::query()->whereNotNull('memo_internal_id')->whereIn('status', ['pending', 'active'])->pluck('memo_internal_id');
        $berinvoice = BsApInvoice::query()->whereNotNull('memo_internal_id')->where('status', '!=', BsApInvoice::STATUS_BATAL)->pluck('memo_internal_id');

        return MemoInternal::query()
            ->where('status', '!=', 'ditolak')->whereNotNull('supplier_id')
            ->whereNotIn('id', $dibayar->merge($berinvoice)->all() ?: [0])
            ->whereHas('approval.steps', fn ($q) => $q->where('level', MemoUntukInvoice::LEVEL_DIREKTUR)->where('status', 'approved'))
            ->with(['approval.steps', 'supplier:id,code,name'])
            ->orderByDesc('memo_date')->orderByDesc('id')->get();
    }

    /**
     * Bayar satu memo. Kuitansi langsung aktif + jurnal terposting.
     *
     * @param  array{memo_internal_id:int, kuitansi_date:string, cash_account_id:int, advance_account_id:int, amount:float|int|string,
     *               alat_transaksi?:string, kasir?:?string, manual_no?:?string, bg_number?:?string, description?:?string}  $data
     *
     * @throws BusinessRuleException
     */
    public function bayar(array $data, int $userId): BsApKuitansi
    {
        return DB::transaction(function () use ($data, $userId): BsApKuitansi {
            $memo = MemoInternal::query()->lockForUpdate()->findOrFail((int) $data['memo_internal_id']);
            if (! $this->bisaDibayar()->contains('id', $memo->id)) {
                throw new BusinessRuleException("Memo {$memo->memo_number} belum disetujui Direktur, sudah dibayar, atau sudah punya invoice.", errorCode: 'MEMO_BAYAR_TIDAK_BOLEH');
            }
            $nilai = round((float) $data['amount'], 2);
            if ($nilai <= 0) {
                throw new BusinessRuleException('Nominal dibayar wajib diisi.', errorCode: 'MEMO_BAYAR_KOSONG');
            }
            if ($nilai > (float) $memo->amount + 0.001) {
                throw new BusinessRuleException('Nominal dibayar melebihi nominal memo (Rp '.number_format((float) $memo->amount, 0, ',', '.').').', errorCode: 'MEMO_BAYAR_LEBIH');
            }
            if (! $this->akunUangMuka()->contains('id', (int) $data['advance_account_id'])) {
                throw new BusinessRuleException('Pilih akun uang muka (10107…).', errorCode: 'MEMO_BAYAR_AKUN');
            }

            $kui = BsApKuitansi::create([
                'kuitansi_number' => app(ApKuitansiService::class)->nextNumber($data['kuitansi_date']),
                'kuitansi_date' => $data['kuitansi_date'],
                'supplier_id' => $memo->supplier_id,
                'memo_internal_id' => $memo->id,
                'cash_account_id' => $data['cash_account_id'],
                'advance_account_id' => $data['advance_account_id'],
                'alat_transaksi' => $data['alat_transaksi'] ?? 'transfer',
                'kasir' => $data['kasir'] ?? null,
                'manual_no' => $data['manual_no'] ?? null,
                'bg_number' => $data['bg_number'] ?? null,
                'description' => $data['description'] ?? ('Memo Urgent '.$memo->memo_number.' — '.$memo->subject),
                'total' => $nilai,
                'status' => 'active',
                'created_by' => $userId, 'approved_by' => $userId, 'approved_at' => now(),
            ]);
            $jurnal = $this->journal->post([
                'journal_date' => $kui->kuitansi_date->toDateString(),
                'source' => 'ap',
                'reference' => 'KUI',
                'description' => 'Kuitansi Memo Urgent '.$memo->memo_number.' — '.$memo->subject,
                'created_by' => $userId,
            ], [
                ['account_id' => (int) $data['advance_account_id'], 'debit' => $nilai, 'credit' => 0, 'description' => 'Uang muka Memo Urgent '.$memo->memo_number],
                ['account_id' => (int) $data['cash_account_id'], 'debit' => 0, 'credit' => $nilai, 'description' => 'Pembayaran Memo Urgent '.$memo->memo_number],
            ]);
            $kui->update(['journal_id' => $jurnal->id]);

            return $kui;
        });
    }

    /** Kuitansi aktif yang membayar memo ini (null bila belum dibayar). */
    public function kuitansiMemo(int $memoId): ?BsApKuitansi
    {
        return BsApKuitansi::query()->where('memo_internal_id', $memoId)->where('status', 'active')->latest('id')->first();
    }

    /** Uang muka kuitansi memo yang belum dipotong ke invoice (kuitansi menunggu/aktif dihitung terpakai). */
    public function sisaUangMuka(BsApKuitansi $memoKui, ?int $kecualiKuitansiId = null): float
    {
        $terpakai = BsApKuitansiLine::query()->where('um_kuitansi_id', $memoKui->id)
            ->when($kecualiKuitansiId, fn ($q, $id) => $q->where('kuitansi_id', '!=', $id))
            ->whereIn('kuitansi_id', BsApKuitansi::query()->select('id')->whereIn('status', ['pending', 'active']))
            ->sum('um_amount');

        return round((float) $memoKui->total - (float) $terpakai, 2);
    }

    /**
     * Uang muka memo yang WAJIB dipotong saat invoice ini dibayar: [kuitansi memo, sisa] atau null.
     *
     * @return array{0:BsApKuitansi, 1:float}|null
     */
    public function uangMukaInvoice(BsApInvoice $inv, ?int $kecualiKuitansiId = null): ?array
    {
        if (! $inv->memo_internal_id || ! ($k = $this->kuitansiMemo((int) $inv->memo_internal_id))) {
            return null;
        }
        $sisa = $this->sisaUangMuka($k, $kecualiKuitansiId);

        return $sisa > 0.009 ? [$k, $sisa] : null;
    }

    /** Invoice yang menarik memo sudah dibayar: total hutangnya tidak boleh di bawah yang sudah dibayar. */
    public function assertInvoiceCukup(BsApInvoice $inv): void
    {
        if (! $inv->memo_internal_id || ! ($k = $this->kuitansiMemo((int) $inv->memo_internal_id))) {
            return;
        }
        if ((float) $inv->payable_amount + 0.001 < (float) $k->total) {
            throw new BusinessRuleException('Hutang invoice (Rp '.number_format((float) $inv->payable_amount, 0, ',', '.').') lebih kecil dari Memo Urgent yang sudah dibayar (Rp '
                .number_format((float) $k->total, 0, ',', '.').', '.$k->kuitansi_number.'). Selesaikan selisihnya dulu.', errorCode: 'MEMO_INVOICE_KURANG');
        }
    }

    /**
     * Memo sudah dibayar tapi invoice hutangnya belum masuk (opsional: milik satu pengaju).
     *
     * @return Collection<int, array{memo_id:int, memo_number:string, subject:string, supplier:?string, pengaju:?string, pengaju_id:int,
     *                               kuitansi_id:int, kuitansi_number:string, tanggal_bayar:string, nilai:float, umur:int, lewat:bool, approval_url:?string}>
     */
    public function outstanding(?int $pengajuId = null): Collection
    {
        $berinvoice = BsApInvoice::query()->whereNotNull('memo_internal_id')->where('status', '!=', BsApInvoice::STATUS_BATAL)->pluck('memo_internal_id');
        $batas = $this->batasHari();

        return BsApKuitansi::query()->whereNotNull('memo_internal_id')->where('status', 'active')
            ->whereNotIn('memo_internal_id', $berinvoice->all() ?: [0])
            ->whereHas('memo', fn ($q) => $q->when($pengajuId, fn ($q, $id) => $q->where('created_by', $id)))
            ->with(['memo.creator:id,name', 'supplier:id,name'])
            ->orderBy('kuitansi_date')->orderBy('id')->get()
            ->map(function (BsApKuitansi $k) use ($batas) {
                $umur = (int) $k->kuitansi_date->startOfDay()->diffInDays(now()->startOfDay());

                return [
                    'memo_id' => $k->memo->id, 'memo_number' => $k->memo->memo_number, 'subject' => (string) $k->memo->subject,
                    'supplier' => $k->supplier?->name, 'pengaju' => $k->memo->creator?->name, 'pengaju_id' => (int) $k->memo->created_by,
                    'kuitansi_id' => $k->id, 'kuitansi_number' => $k->kuitansi_number, 'tanggal_bayar' => $k->kuitansi_date->toDateString(),
                    'nilai' => (float) $k->total, 'umur' => $umur, 'lewat' => $umur > $batas,
                    'approval_url' => $k->memo->approval_id ? route('approval-inbox.show', $k->memo->approval_id) : null,
                ];
            })->values();
    }

    /** Pengaju ini masih boleh mengajukan memo baru? (tidak ada memo dibayar tanpa invoice lewat batas hari) */
    public function terblokir(User $u): bool
    {
        return $this->outstanding($u->id)->contains('lewat', true);
    }

    public function batasHari(): int
    {
        $v = DB::table('parameters')->where('tenant_id', tenant_id() ?? default_tenant_id())
            ->where('group', 'memo')->where('key', 'batas_hari_invoice')->value('value');

        return is_numeric($v) && (int) $v >= 0 ? (int) $v : self::BATAS_HARI_DEFAULT;
    }

    /**
     * Kotak TTD cetakan kuitansi memo: Kasir, lalu Direktur & MW / BA dari approval memo. TTD digital
     * tampil otomatis begitu tahapnya disetujui (data URI dari users.signature_path).
     *
     * @return list<array{title:string, name:?string, signed:bool, at:?string, img:?string}>
     */
    public function kotakTtd(BsApKuitansi $k): array
    {
        $img = function (?User $u): ?string {
            if (! $u || ! $u->signature_path || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($u->signature_path)) {
                return null;
            }

            return 'data:image/png;base64,'.base64_encode(\Illuminate\Support\Facades\Storage::disk('local')->get($u->signature_path));
        };
        $kasir = $k->created_by ? User::find($k->created_by) : null;
        $kotak = [['title' => 'Kasir', 'name' => $k->kasir ?? $kasir?->name, 'signed' => true, 'at' => $k->created_at?->format('d/m/Y H:i'), 'img' => $img($kasir)]];
        $steps = $k->memo?->approval?->steps ?? collect();
        foreach ([MemoUntukInvoice::LEVEL_DIREKTUR, array_key_last(MemoInternal::STEPS)] as $level) {
            $st = $steps->firstWhere('level', $level);
            $u = $st?->status === 'approved' && $st->approver_id ? User::find($st->approver_id) : null;
            $kotak[] = ['title' => MemoInternal::STEPS[$level][1], 'name' => $u?->name, 'signed' => $u !== null,
                'at' => $u ? $st->acted_at?->format('d/m/Y H:i') : null, 'img' => $img($u)];
        }

        return $kotak;
    }
}
