<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\Approval\Engine\ApprovalChainResolver;
use App\Domain\Approval\Models\Approval;
use App\Domain\Approval\Models\ApprovalAction;
use App\Domain\Approval\Models\ApprovalStep;
use App\Domain\BusinessSupport\Models\BsApBerkasLink;
use App\Domain\BusinessSupport\Models\BsApBerkasUpload;
use App\Domain\BusinessSupport\Models\BsApInvoiceDraft;
use App\Domain\BusinessSupport\Models\BsEmployee;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * TTD Dept Head di Registrasi Invoice Hutang.
 *
 * Berkas yang diunggah (sendiri / lewat link supplier) TIDAK langsung diajukan. Staf menekan
 * "Ajukan ke Dept Head" di langkah Struktur: isian tersimpan sebagai draft (tertaut berkasnya) lalu
 * berkas diajukan ke TTD atasannya; staf baru bisa lanjut ke Selesai setelah Dept Head menyetujui.
 * Pembuat yang dirinya Dept Head (atasan karyawan lain di Employee Center) tidak perlu mengajukan —
 * berkasnya ditandatangani otomatis atas namanya saat invoice disimpan.
 *
 * Draft: satu draft "bebas" per user (isian yang sedang dikerjakan) + satu draft per berkas yang
 * sudah diajukan (tampil di panel Draft › Menunggu TTD Dept Head).
 */
class PengajuanDeptHead
{
    public function __construct(
        private readonly ApBerkasService $berkas,
        private readonly CostCenterPengguna $pengguna,
        private readonly ApprovalChainResolver $resolver,
    ) {}

    /** Dept Head = ada karyawan aktif yang atasan langsungnya user ini (Employee Center). */
    public function isDeptHead(User $user): bool
    {
        $empId = $this->pengguna->idKaryawan($user);

        return $empId !== null && BsEmployee::query()->where('atasan_id', $empId)->where('is_active', true)->exists();
    }

    /** Draft sudah diajukan ke Dept Head = berkasnya sudah pernah diajukan / di-TTD. */
    public function diajukan(BsApInvoiceDraft $d): bool
    {
        return $d->berkas_upload_id !== null
            && BsApBerkasUpload::query()->whereKey($d->berkas_upload_id)->whereNotNull('submitted_at')->exists();
    }

    public function draftBebas(User $user, string $jenis = 'invoice'): ?BsApInvoiceDraft
    {
        return BsApInvoiceDraft::query()->where('user_id', $user->id)->where('jenis', $jenis)->latest('updated_at')->get()
            ->first(fn (BsApInvoiceDraft $d) => ! $this->diajukan($d));
    }

    /** Simpan isian wizard: ke draft yang sedang dibuka (draft_id) atau draft bebas user. */
    public function simpanDraft(User $user, array $data, int $step, ?int $draftId = null): BsApInvoiceDraft
    {
        $berkasId = isset($data['berkas_upload_id']) && is_numeric($data['berkas_upload_id']) ? (int) $data['berkas_upload_id'] : null;
        $draft = $draftId ? BsApInvoiceDraft::query()->where('user_id', $user->id)->find($draftId) : null;
        // Draft bebas yang beralih ke berkas yang sudah diajukan → tetap draft itu (tidak menduplikasi).
        $draft ??= $berkasId ? BsApInvoiceDraft::query()->where('user_id', $user->id)->where('jenis', 'invoice')->where('berkas_upload_id', $berkasId)->first() : null;
        $draft ??= $this->draftBebas($user);
        unset($data['draft_id']);
        $isi = ['data' => $data, 'step' => $step, 'berkas_upload_id' => $berkasId];
        if ($draft) {
            $draft->update($isi);

            return $draft;
        }

        return BsApInvoiceDraft::create(['user_id' => $user->id, 'jenis' => 'invoice'] + $isi);
    }

    /**
     * "Ajukan ke Dept Head": simpan isian (draft / link supplier) lalu ajukan berkasnya ke TTD.
     *
     * @return array{berkas: BsApBerkasUpload, draft: ?BsApInvoiceDraft}
     */
    public function ajukan(User $user, array $data, int $step, ?int $draftId = null, ?int $linkId = null): array
    {
        $berkasId = isset($data['berkas_upload_id']) && is_numeric($data['berkas_upload_id']) ? (int) $data['berkas_upload_id'] : 0;
        $berkas = BsApBerkasUpload::query()->find($berkasId);
        if (! $berkas) {
            throw new BusinessRuleException('Pilih / unggah berkas tagihan dulu di langkah Input Transaksi.', errorCode: 'BERKAS_KOSONG');
        }
        $this->berkas->assertMilik($berkas, $user);

        return DB::transaction(function () use ($user, $data, $step, $draftId, $linkId, $berkas): array {
            $link = $linkId ? BsApBerkasLink::query()->where('user_id', $user->id)->find($linkId) : null;
            $draft = null;
            if ($link) {
                // Sama dengan Simpan Draft link: nomor invoice dari supplier tidak tertimpa isian wizard.
                app(LinkBerkasSupplier::class)->simpanDraft($link, $data, $step);
            } else {
                $draft = $this->simpanDraft($user, $data, $step, $draftId);
            }
            if (in_array($berkas->status, [BsApBerkasUpload::STATUS_DRAFT, BsApBerkasUpload::STATUS_DITOLAK], true)) {
                $berkas = $this->isDeptHead($user) ? $this->ttdSendiri($berkas, $user) : $this->berkas->submit($berkas, $user);
            }

            return ['berkas' => $berkas, 'draft' => $draft];
        });
    }

    /**
     * Pembuat = Dept Head: TTD berkas otomatis atas namanya (approval langsung disetujui, tanpa
     * masuk inbox siapa pun). Jejaknya sama dengan TTD biasa, jadi lembar berkas & Memo Internal
     * menampilkan tanda tangannya.
     */
    public function ttdSendiri(BsApBerkasUpload $berkas, User $user): BsApBerkasUpload
    {
        if (! in_array($berkas->status, [BsApBerkasUpload::STATUS_DRAFT, BsApBerkasUpload::STATUS_DITOLAK], true)) {
            return $berkas;
        }
        if (! $berkas->files()->exists()) {
            throw new BusinessRuleException('Unggah minimal satu file berkas.', errorCode: 'BERKAS_EMPTY');
        }
        if ($kurang = $this->berkas->berkasWajibKurang($berkas)) {
            throw new BusinessRuleException('Berkas wajib belum lengkap: '.implode(', ', $kurang).'.', errorCode: 'BERKAS_WAJIB');
        }

        return DB::transaction(function () use ($berkas, $user): BsApBerkasUpload {
            $payload = ['berkas_number' => $berkas->berkas_number, 'description' => $berkas->description,
                'amount' => $berkas->amount !== null ? (float) $berkas->amount : null, 'ttd_sendiri' => true];
            $chain = $this->resolver->resolve(BsApBerkasUpload::DOCUMENT_TYPE, $payload);
            $approval = Approval::create([
                'approval_chain_id' => $chain->id, 'document_type' => BsApBerkasUpload::DOCUMENT_TYPE,
                'approvable_type' => $berkas->getMorphClass(), 'approvable_id' => $berkas->id, 'payload' => $payload,
                'status' => 'approved', 'submitted_by' => $user->id, 'submitted_at' => now(), 'completed_at' => now(),
            ]);
            foreach ($chain->load('steps')->steps as $s) {
                $step = ApprovalStep::create([
                    'approval_id' => $approval->id, 'level' => $s->level, 'approver_id' => $user->id,
                    'approver_type' => $s->approver_type, 'approver_value' => $s->approver_value, 'label' => $s->label,
                    'status' => 'approved', 'notes' => 'TTD otomatis — pembuat adalah Dept Head', 'acted_at' => now(),
                ]);
                ApprovalAction::create(['approval_step_id' => $step->id, 'action' => 'approve', 'actor_id' => $user->id,
                    'notes' => 'TTD otomatis — pembuat adalah Dept Head', 'acted_at' => now()]);
            }
            $berkas->update(['dept_head_id' => $user->id, 'approval_id' => $approval->id,
                'status' => BsApBerkasUpload::STATUS_SIAP_INVOICE, 'submitted_at' => now()]);
            $this->berkas->log($berkas, 'diajukan', 'TTD otomatis: '.$user->name.' (Dept Head)');

            return $berkas->refresh();
        });
    }

    /** Halaman lanjutan isian invoice untuk berkas ini (draft atau link supplier); null bila tidak ada. */
    public function urlLanjut(BsApBerkasUpload $berkas): ?string
    {
        $draft = BsApInvoiceDraft::query()->where('user_id', $berkas->uploaded_by)->where('berkas_upload_id', $berkas->id)->value('id');
        if ($draft) {
            return route('bs.ap.create', ['draft' => $draft]);
        }
        $link = BsApBerkasLink::query()->where('berkas_upload_id', $berkas->id)->whereIn('status', [BsApBerkasLink::MENUNGGU, BsApBerkasLink::TERISI])->value('id');

        return $link ? route('bs.ap.create', ['link' => $link]) : null;
    }

    /**
     * Panel Draft › Menunggu TTD Dept Head: isian invoice (draft / link) yang berkasnya sudah diajukan
     * dan belum jadi invoice. sudah = Dept Head sudah TTD (tinggal dilanjutkan).
     *
     * @return list<array<string,mixed>>
     */
    public function menunggu(User $user): array
    {
        $drafts = BsApInvoiceDraft::query()->where('user_id', $user->id)->where('jenis', 'invoice')->whereNotNull('berkas_upload_id')
            ->get(['id', 'berkas_upload_id', 'data', 'updated_at'])->keyBy('berkas_upload_id');
        $links = BsApBerkasLink::query()->where('user_id', $user->id)->whereIn('status', [BsApBerkasLink::MENUNGGU, BsApBerkasLink::TERISI])
            ->whereNotNull('berkas_upload_id')->get(['id', 'berkas_upload_id', 'data', 'invoice_number'])->keyBy('berkas_upload_id');
        $ids = $drafts->keys()->merge($links->keys())->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return BsApBerkasUpload::query()->whereIn('id', $ids)->whereNotNull('submitted_at')
            ->whereIn('status', [BsApBerkasUpload::STATUS_MENUNGGU_TTD, BsApBerkasUpload::STATUS_SIAP_INVOICE, BsApBerkasUpload::STATUS_DITOLAK])
            ->whereDoesntHave('invoices')
            ->with(['supplier:id,name', 'deptHead:id,name'])->latest('submitted_at')->get()
            ->map(function (BsApBerkasUpload $b) use ($drafts, $links) {
                $d = $drafts->get($b->id);
                $l = $links->get($b->id);
                $data = $d?->data ?? $l?->data ?? [];

                return [
                    'berkas_id' => $b->id, 'berkas_number' => $b->berkas_number, 'invoice_number' => $l?->invoice_number,
                    'supplier' => $b->supplier?->name, 'description' => $data['description'] ?? $b->description,
                    'nilai' => isset($data['dpp']) && is_numeric($data['dpp']) ? (float) $data['dpp'] : ($b->amount !== null ? (float) $b->amount : null),
                    'dept_head' => $b->deptHead?->name, 'diajukan' => $b->submitted_at?->toIso8601String(),
                    'status' => match ($b->status) {
                        BsApBerkasUpload::STATUS_SIAP_INVOICE => 'disetujui',
                        BsApBerkasUpload::STATUS_DITOLAK => 'ditolak',
                        default => 'menunggu',
                    },
                    'url' => $d ? route('bs.ap.create', ['draft' => $d->id]) : route('bs.ap.create', ['link' => $l->id]),
                ];
            })->values()->all();
    }
}
