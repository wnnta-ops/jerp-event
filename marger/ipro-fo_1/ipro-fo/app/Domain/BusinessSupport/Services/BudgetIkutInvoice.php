<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\Approval\Models\Approval;
use App\Domain\Approval\Models\ApprovalStep;
use App\Domain\ApprovalInbox\Services\ApprovalInboxQuery;
use App\Domain\BusinessSupport\Models\BsApBudgetRequest;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsBudgetAllocation;
use App\Domain\BusinessSupport\Models\BsBudgetCorrection;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Alokasi / Koreksi Budget yang diajukan dari Registrasi Invoice Hutang tidak punya approval
 * sendiri — mereka TURUNAN approval invoicenya dan mengikuti chain Invoice Hutang:
 *
 *   - Invoice biasa (dari berkas)   : turunan approval 'ap_invoice' (source_type ap_invoice).
 *   - Invoice Memo Urgent (ditahan) : turunan approval 'ap_budget_request' — chain-nya chain
 *                                     Invoice Hutang (source_type ap_budget_request).
 *
 * Satu tombol Setujui di Approval Inbox = invoice + turunannya. Disetujui penuh → anggaran
 * dipindah / dikoreksi; ditolak → turunan ikut ditolak.
 *
 * Revisi: approver tahap FINANCE, saat gilirannya, boleh mengubah turunan (nilai, budget asal,
 * jenis, tambah / hapus). Ada perubahan → turunan lama dibatalkan, yang baru dibuat, dan approval
 * diulang dari tahap 1 dengan tanda [REVISI] (payload.revisi).
 */
final class BudgetIkutInvoice
{
    public const LABEL_FINANCE = 'Finance';

    public function __construct(
        private readonly BudgetAllocationService $alokasi,
        private readonly BudgetCorrectionService $koreksi,
    ) {}

    /** Approval yang bisa membawa turunan budget. */
    public static function berlaku(Approval $approval): bool
    {
        return in_array($approval->document_type, [BsApInvoice::APPROVAL_DOCUMENT_TYPE, BsApBudgetRequest::DOCUMENT_TYPE], true);
    }

    /**
     * Turunan approval ini (yang belum dibatalkan).
     *
     * @return Collection<int, BsBudgetAllocation|BsBudgetCorrection>
     */
    public function turunan(Approval $approval): Collection
    {
        [$tipe, $id, $sejak] = match ($approval->document_type) {
            BsApInvoice::APPROVAL_DOCUMENT_TYPE => [BsBudgetAllocation::SOURCE_INVOICE, (int) $approval->approvable_id, null],
            BsApBudgetRequest::DOCUMENT_TYPE => [BsApBudgetRequest::SOURCE, (int) $approval->approvable_id,
                BsApBudgetRequest::query()->whereKey($approval->approvable_id)->value('diajukan_at')],
            default => [null, null, null],
        };
        if (! $tipe) {
            return collect();
        }
        // Ronde pengajuan ulang (diajukan_at): turunan ronde sebelumnya tidak dihitung.
        $q = fn (string $kelas) => $kelas::query()->where('source_type', $tipe)->where('source_id', $id)->whereNull('approval_id')
            ->when($sejak, fn ($w) => $w->where('created_at', '>=', Carbon::parse($sejak)->startOfSecond()))
            ->where('status', '!=', 'batal')->orderBy('id')
            ->with($kelas === BsBudgetAllocation::class
                ? ['budget.account:id,code,name', 'budget.costCenter:id,code,name', 'toBudget.account:id,code,name', 'toBudget.costCenter:id,code,name']
                : ['budget.account:id,code,name', 'budget.costCenter:id,code,name'])
            ->get();

        return $q(BsBudgetAllocation::class)->concat($q(BsBudgetCorrection::class))->values();
    }

    /** Approval induk sebuah alokasi / koreksi turunan (null = punya approval sendiri / manual). */
    public function induk(BsBudgetAllocation|BsBudgetCorrection $m): ?Approval
    {
        if ($m->approval_id) {
            return null;
        }

        return match ($m->source_type) {
            BsBudgetAllocation::SOURCE_INVOICE => Approval::query()->where('document_type', BsApInvoice::APPROVAL_DOCUMENT_TYPE)
                ->where('approvable_id', $m->source_id)->latest('id')->first(),
            BsApBudgetRequest::SOURCE => ($aid = BsApBudgetRequest::query()->whereKey($m->source_id)->value('approval_id'))
                ? Approval::query()->find($aid) : null,
            default => null,
        };
    }

    /** Approval induk sudah diputuskan penuh → putuskan turunannya. Dipanggil DI DALAM transaksi approval. */
    public function putuskan(Approval $approval, bool $setuju, int $userId): void
    {
        foreach ($this->turunan($approval)->where('status', 'baru') as $m) {
            $m instanceof BsBudgetAllocation
                ? $this->alokasi->putuskan($m, $setuju, $userId)
                : $this->koreksi->putuskan($m, $setuju, $userId);
        }
    }

    /**
     * Untuk layar: tiap turunan + lampirannya.
     *
     * @return list<array<string,mixed>>
     */
    public function ringkas(Approval $approval): array
    {
        $label = fn (?BsBudget $b) => $b ? trim($b->account?->code.' '.$b->account?->name).' · '.($b->costCenter?->name ?? 'Semua cost center') : null;

        return $this->turunan($approval)->map(fn ($m) => $m instanceof BsBudgetAllocation ? [
            'jenis' => 'alokasi', 'id' => $m->id, 'doc_no' => $m->doc_no, 'status' => $m->status,
            'dari_budget_id' => $m->budget_id, 'dari' => $label($m->budget),
            'budget_id' => $m->to_budget_id, 'ke' => $label($m->toBudget),
            'nilai' => (float) $m->amount, 'keterangan' => $m->notes,
            'lampiran_url' => LampiranPerubahanBudget::url($m),
        ] : [
            'jenis' => 'koreksi', 'id' => $m->id, 'doc_no' => $m->doc_no, 'status' => $m->status,
            'dari_budget_id' => null, 'dari' => null,
            'budget_id' => $m->budget_id, 'ke' => $label($m->budget),
            'nilai' => round(array_sum($m->tambahanBulan()), 2), 'keterangan' => $m->note,
            'lampiran_url' => LampiranPerubahanBudget::url($m),
        ])->values()->all();
    }

    /** Berapa kali approval ini direvisi Finance (0 = belum pernah). */
    public static function revisiKe(Approval $approval): int
    {
        return (int) ($approval->payload['revisi'] ?? 0);
    }

    /** Approver tahap Finance, saat gilirannya, boleh merevisi turunan. */
    public function bisaRevisi(User $user, Approval $approval): bool
    {
        if (! self::berlaku($approval) || ! in_array($approval->status, ['pending', 'in_review'], true)) {
            return false;
        }
        $kini = $approval->steps->where('status', 'pending')->sortBy('level')->first();

        return $kini && strcasecmp((string) $kini->label, self::LABEL_FINANCE) === 0
            && app(ApprovalInboxQuery::class)->canAct($user, $approval);
    }

    /**
     * Revisi turunan oleh Finance. Tidak ada perubahan → tidak terjadi apa-apa (false). Ada → turunan
     * lama dibatalkan, yang baru dibuat, approval diulang dari tahap 1 dengan tanda [REVISI].
     *
     * @param  list<array{jenis:string, dari_budget_id?:?int, budget_id:int, nilai:float|int|string, keterangan?:?string}>  $baru
     *
     * @throws BusinessRuleException
     */
    public function revisi(Approval $approval, User $user, array $baru, ?string $catatan = null): bool
    {
        if (! $this->bisaRevisi($user, $approval)) {
            throw new BusinessRuleException('Revisi alokasi / koreksi hanya oleh approver Finance saat gilirannya.', errorCode: 'REVISI_FORBIDDEN');
        }
        $baru = array_values(array_filter(array_map(fn ($b) => [
            'jenis' => $b['jenis'] === 'alokasi' ? 'alokasi' : 'koreksi',
            'dari_budget_id' => ($b['jenis'] ?? '') === 'alokasi' && ! empty($b['dari_budget_id']) ? (int) $b['dari_budget_id'] : null,
            'budget_id' => (int) ($b['budget_id'] ?? 0),
            'nilai' => round((float) ($b['nilai'] ?? 0), 2),
            'keterangan' => trim((string) ($b['keterangan'] ?? '')),
        ], $baru), fn ($b) => $b['nilai'] > 0));
        foreach ($baru as $b) {
            if (! $b['budget_id'] || ($b['jenis'] === 'alokasi' && ! $b['dari_budget_id'])) {
                throw new BusinessRuleException('Lengkapi budget untuk tiap alokasi / koreksi.', errorCode: 'REVISI_DATA');
            }
            if ($b['jenis'] === 'alokasi' && $b['dari_budget_id'] === $b['budget_id']) {
                throw new BusinessRuleException('Budget asal dan tujuan alokasi tidak boleh sama.', errorCode: 'REVISI_DATA');
            }
        }

        $lama = $this->turunan($approval);
        $kunci = fn (array $b) => implode('|', [$b['jenis'], (int) $b['dari_budget_id'], $b['budget_id'], number_format($b['nilai'], 2, '.', '')]);
        $sekarang = collect($this->ringkas($approval))->map(fn ($r) => $kunci(['jenis' => $r['jenis'], 'dari_budget_id' => $r['dari_budget_id'],
            'budget_id' => (int) $r['budget_id'], 'nilai' => round((float) $r['nilai'], 2)]))->sort()->values()->all();
        if ($sekarang === collect($baru)->map($kunci)->sort()->values()->all()) {
            return false;
        }

        [$sumber, $tanggal, $label, $excludeJournal] = $this->asal($approval);
        $bulan = (int) Carbon::parse($tanggal)->month;

        DB::transaction(function () use ($approval, $user, $baru, $lama, $sumber, $tanggal, $label, $excludeJournal, $bulan, $catatan): void {
            foreach ($lama as $m) {
                $m->forceFill(['status' => 'batal'])->save();
            }
            foreach ($baru as $b) {
                $note = mb_substr(trim($b['keterangan'].' · '.$label.' · revisi Finance', ' ·'), 0, 200);
                if ($b['jenis'] === 'alokasi') {
                    $this->alokasi->pindah($b['dari_budget_id'], $b['budget_id'], [$bulan => $b['nilai']], $tanggal, $note, $user->id,
                        $sumber, $excludeJournal, langsung: false);
                } else {
                    $budget = BsBudget::query()->lockForUpdate()->findOrFail($b['budget_id']);
                    $this->koreksi->ajukanManual($budget, [$bulan => $b['nilai']], $tanggal, $note, $user->id, $sumber, null, approvalSendiri: false);
                }
            }
            $this->ulangDariAwal($approval, $user, $catatan);
        });

        return true;
    }

    /** Semua tahap kembali menunggu, approval kembali ke tahap 1, tanda revisi bertambah. */
    private function ulangDariAwal(Approval $approval, User $user, ?string $catatan): void
    {
        $payload = $approval->payload ?? [];
        $payload['revisi'] = self::revisiKe($approval) + 1;
        $payload['riwayat_revisi'][] = ['oleh' => $user->id, 'nama' => $user->name, 'at' => now()->toIso8601String(), 'catatan' => $catatan];
        foreach ($approval->steps as $s) {
            ApprovalStep::query()->whereKey($s->id)->update([
                'status' => 'pending', 'acted_at' => null, 'notes' => null,
                // 'assigned' ditunjuk sejak pengajuan — tetap; lainnya diisi lagi saat bertindak.
                'approver_id' => $s->approver_type === 'assigned' ? $s->approver_id : null,
            ]);
        }
        $approval->forceFill(['status' => 'pending', 'payload' => $payload, 'completed_at' => null])->save();
        $approval->load('steps');
        event(new \App\Domain\Approval\Events\ApprovalSubmitted($approval));
    }

    /** @return array{0:array{type:string,id:int}, 1:string, 2:string, 3:?int} sumber, tanggal invoice, label, jurnal yang dikecualikan */
    private function asal(Approval $approval): array
    {
        if ($approval->document_type === BsApInvoice::APPROVAL_DOCUMENT_TYPE) {
            $inv = BsApInvoice::query()->findOrFail($approval->approvable_id);

            return [['type' => BsBudgetAllocation::SOURCE_INVOICE, 'id' => $inv->id], $inv->invoice_date->toDateString(), (string) $inv->invoice_number, $inv->journal_id];
        }
        $req = BsApBudgetRequest::query()->findOrFail($approval->approvable_id);
        $edit = $req->invoice_id ? BsApInvoice::query()->find($req->invoice_id) : null;

        return [['type' => BsApBudgetRequest::SOURCE, 'id' => $req->id], (string) ($req->data['invoice_date'] ?? now()->toDateString()),
            (string) ($req->invoice_number ?? $edit?->invoice_number), $edit?->journal_id];
    }
}
