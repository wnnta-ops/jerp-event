<?php

declare(strict_types=1);

namespace App\Domain\ApprovalInbox\Services;

use App\Domain\Approval\Models\Approval;
use App\Domain\Approval\Models\ApprovalChainStep;
use App\Domain\ApprovalInbox\Models\MemoInternal;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Siapa melihat apa di Approval Inbox, plus pelacak tahap (tracker) per dokumen.
 *
 *  - Pengajuan Saya       : yang SAYA ajukan saja (staf tidak melihat milik orang lain).
 *  - Perlu Persetujuan    : dokumen yang tahap aktifnya menunggu role/user saya.
 *  - Riwayat              : dokumen yang pernah saya setujui/tolak.
 *
 * Aturan "berhak bertindak" sama dengan ActOnApproval (role/user di chain step,
 * SuperAdmin selalu boleh) supaya tombol yang tampil = aksi yang diterima server.
 */
final class ApprovalInboxQuery
{
    /** Jenis dokumen yang dikelola inbox ini. */
    public const DOCUMENT_TYPES = [MemoInternal::DOCUMENT_TYPE];

    /** @return Collection<int, Approval> */
    public function mine(User $user): Collection
    {
        return $this->base()->where('submitted_by', $user->id)->latest('submitted_at')->get();
    }

    /** @return Collection<int, Approval> */
    public function needsMe(User $user): Collection
    {
        return $this->base()
            ->whereIn('status', ['pending', 'in_review'])
            ->oldest('submitted_at')
            ->get()
            ->filter(fn (Approval $a) => $this->canAct($user, $a))
            ->values();
    }

    /** @return Collection<int, Approval> */
    public function history(User $user): Collection
    {
        return $this->base()
            ->whereHas('steps', fn ($q) => $q->where('approver_id', $user->id))
            ->latest('updated_at')
            ->get();
    }

    public function canAct(User $user, Approval $approval): bool
    {
        if (! in_array($approval->status, ['pending', 'in_review'], true)) {
            return false;
        }
        $current = $approval->steps->where('status', 'pending')->sortBy('level')->first();
        if (! $current) {
            return false;
        }
        if ($user->hasRole('SuperAdmin')) {
            return true;
        }
        $chainStep = $this->chainStep($approval, (int) $current->level);

        return $chainStep !== null && (
            ($chainStep->approver_type === 'role' && $user->hasRole((string) $chainStep->approver_value))
            || ($chainStep->approver_type === 'user' && (int) $chainStep->approver_value === (int) $user->id)
        );
    }

    /** Pengaju, yang pernah bertindak, yang sedang mendapat giliran, atau SuperAdmin. */
    public function canView(User $user, Approval $approval): bool
    {
        return (int) $approval->submitted_by === (int) $user->id
            || $approval->steps->contains(fn ($s) => (int) $s->approver_id === (int) $user->id)
            || $this->canAct($user, $approval)
            || $user->hasRole('SuperAdmin');
    }

    /**
     * Tahap-tahap alur untuk tracker: tahap 1 = pengaju, sisanya dari chain step.
     *
     * @return list<array{label:string, role:?string, state:string, by:?string, at:?string, notes:?string, signature:?string}>
     *   state: done | rejected | current | waiting | skipped
     *   signature: URL TTD orang yang menyetujui (hanya tahap 'done'), null bila belum mengunggah
     */
    public function stages(Approval $approval): array
    {
        $users = User::query()
            ->whereIn('id', $approval->steps->pluck('approver_id')->push($approval->submitted_by)->filter()->unique())
            ->get(['id', 'name', 'tenant_id', 'signature_path', 'signature_updated_at'])
            ->keyBy('id');
        $ttd = fn (?int $id) => $id && isset($users[$id]) ? $users[$id]->signatureUrl() : null;

        $stages = [[
            'label' => 'Dept. Head',
            'role' => 'Pengajuan',
            'state' => 'done',
            'by' => $users[$approval->submitted_by]->name ?? null,
            'at' => $approval->submitted_at?->toIso8601String(),
            'notes' => null,
            'signature' => $ttd((int) $approval->submitted_by),
        ]];

        $rejected = false;
        $currentLevel = $approval->steps->where('status', 'pending')->min('level');
        foreach ($approval->steps->sortBy('level') as $step) {
            $chainStep = $this->chainStep($approval, (int) $step->level);
            $state = match (true) {
                $step->status === 'approved' => 'done',
                $step->status === 'rejected' => 'rejected',
                $rejected => 'skipped',
                $approval->status === 'rejected' => 'skipped',
                (int) $step->level === (int) $currentLevel => 'current',
                default => 'waiting',
            };
            $rejected = $rejected || $step->status === 'rejected';

            $stages[] = [
                'label' => $this->stepLabel($approval, (int) $step->level, $chainStep),
                'role' => $chainStep?->approver_value,
                'state' => $state,
                'by' => $step->approver_id ? ($users[$step->approver_id]->name ?? null) : null,
                'at' => $step->acted_at?->toIso8601String(),
                'notes' => $step->notes,
                'signature' => $state === 'done' ? $ttd((int) $step->approver_id) : null,
            ];
        }

        return $stages;
    }

    /** Ringkasan satu baris untuk tabel inbox. */
    public function row(Approval $approval): array
    {
        $stages = $this->stages($approval);
        $current = collect($stages)->first(fn ($s) => in_array($s['state'], ['current', 'rejected'], true));
        $memo = $approval->approvable;

        return [
            'id' => $approval->id,
            'number' => $memo?->memo_number,
            'subject' => $memo?->subject,
            'department' => $memo?->department,
            'amount' => $memo?->amount !== null ? (float) $memo->amount : null,
            'type' => 'Memo Internal',
            'status' => $approval->status,
            'submitter' => $stages[0]['by'],
            'submitted_at' => $approval->submitted_at?->toIso8601String(),
            'position' => $current['label'] ?? ($approval->status === 'approved' ? 'Selesai' : '—'),
            'done' => collect($stages)->where('state', 'done')->count(),
            'total' => count($stages),
            'stages' => array_map(fn ($s) => ['label' => $s['label'], 'state' => $s['state']], $stages),
        ];
    }

    private function base()
    {
        return Approval::query()
            ->whereIn('document_type', self::DOCUMENT_TYPES)
            ->with(['steps', 'approvable']);
    }

    /** @var array<int, Collection<int, ApprovalChainStep>> */
    private array $chainSteps = [];

    private function chainStep(Approval $approval, int $level): ?ApprovalChainStep
    {
        $this->chainSteps[$approval->approval_chain_id] ??= ApprovalChainStep::query()
            ->where('approval_chain_id', $approval->approval_chain_id)->get()->keyBy('level');

        return $this->chainSteps[$approval->approval_chain_id][$level] ?? null;
    }

    private function stepLabel(Approval $approval, int $level, ?ApprovalChainStep $chainStep): string
    {
        if ($approval->document_type === MemoInternal::DOCUMENT_TYPE && isset(MemoInternal::STEPS[$level])) {
            return MemoInternal::STEPS[$level][1];
        }

        return (string) ($chainStep?->approver_value ?? "Level {$level}");
    }
}
