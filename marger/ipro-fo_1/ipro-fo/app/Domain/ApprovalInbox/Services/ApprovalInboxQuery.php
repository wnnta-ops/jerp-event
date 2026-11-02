<?php

declare(strict_types=1);

namespace App\Domain\ApprovalInbox\Services;

use App\Domain\Approval\Models\Approval;
use App\Domain\Approval\Models\ApprovalChainStep;
use App\Domain\ApprovalInbox\Models\MemoInternal;
use App\Domain\BusinessSupport\Models\BsApBerkasUpload;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsBudgetCorrection;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

/**
 * Siapa melihat apa di Approval Inbox, plus pelacak tahap (tracker) per dokumen.
 *
 *  - Pengajuan Saya       : yang SAYA ajukan saja (staf tidak melihat milik orang lain).
 *  - Perlu Persetujuan    : dokumen yang tahap aktifnya menunggu role/user saya.
 *  - Riwayat              : dokumen yang pernah saya setujui/tolak.
 *
 * Jenis dokumen: Memo Internal, TTD Dept Head atas Upload Berkas, dan Invoice Hutang dari
 * berkas. Tracker invoice memuat alur penuh sejak berkas diunggah.
 *
 * Aturan "berhak bertindak" sama dengan ActOnApproval (role / user / assigned di chain step,
 * SuperAdmin selalu boleh) supaya tombol yang tampil = aksi yang diterima server.
 */
final class ApprovalInboxQuery
{
    /** Jenis dokumen yang dikelola inbox ini. */
    public const DOCUMENT_TYPES = [
        MemoInternal::DOCUMENT_TYPE,
        BsApBerkasUpload::DOCUMENT_TYPE,
        BsApInvoice::APPROVAL_DOCUMENT_TYPE,
        BsBudgetCorrection::DOCUMENT_TYPE,
    ];

    public const TYPE_LABELS = [
        MemoInternal::DOCUMENT_TYPE => 'Memo Internal',
        BsApBerkasUpload::DOCUMENT_TYPE => 'TTD Berkas',
        BsApInvoice::APPROVAL_DOCUMENT_TYPE => 'Invoice Hutang',
        BsBudgetCorrection::DOCUMENT_TYPE => 'Koreksi Budget',
    ];

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

    /** Langkah yang SUDAH saya putuskan (approver 'assigned' tercatat sejak pengajuan). */
    public function history(User $user): Collection
    {
        return $this->base()
            ->whereHas('steps', fn ($q) => $q->where('approver_id', $user->id)->where('status', '!=', 'pending'))
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
        // Sama dengan ActOnApproval::NO_SELF_APPROVAL — pengaju tidak menyetujui dokumennya sendiri.
        if ((int) $approval->submitted_by === (int) $user->id
            && in_array($approval->document_type, \App\Domain\Approval\Actions\ActOnApproval::NO_SELF_APPROVAL, true)) {
            return false;
        }
        $chainStep = $this->chainStep($approval, (int) $current->level);

        return $chainStep !== null && (
            ($chainStep->approver_type === 'role' && $user->hasRole((string) $chainStep->approver_value))
            || ($chainStep->approver_type === 'user' && (int) $chainStep->approver_value === (int) $user->id)
            || ($chainStep->approver_type === 'assigned' && (int) $current->approver_id === (int) $user->id)
        );
    }

    /** Pengaju, yang ditunjuk / pernah bertindak, yang sedang mendapat giliran, atau SuperAdmin. */
    public function canView(User $user, Approval $approval): bool
    {
        return (int) $approval->submitted_by === (int) $user->id
            || $approval->steps->contains(fn ($s) => (int) $s->approver_id === (int) $user->id)
            || $this->canAct($user, $approval)
            || $user->hasRole('SuperAdmin');
    }

    /**
     * Tahap-tahap alur untuk tracker.
     *
     * @return list<array{label:string, role:?string, state:string, by:?string, at:?string, notes:?string, signature:?string}>
     *   state: done | rejected | current | waiting | skipped
     *   signature: URL TTD orang yang menyetujui (hanya tahap 'done'), null bila belum mengunggah
     */
    public function stages(Approval $approval): array
    {
        $leading = $this->leadingStages($approval);

        $users = $this->users($approval->steps->pluck('approver_id'));
        $rejected = false;
        $currentLevel = $approval->steps->where('status', 'pending')->min('level');
        $stages = $leading;
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
            $who = $step->approver_id ? ($users[$step->approver_id] ?? null) : null;

            $stages[] = [
                'label' => $this->stepLabel($approval, (int) $step->level, $chainStep),
                'role' => $chainStep?->approver_type === 'assigned' ? 'Ditunjuk' : $chainStep?->approver_value,
                'state' => $state,
                'by' => $who?->name,
                'at' => $step->acted_at?->toIso8601String(),
                'notes' => $step->notes,
                'signature' => $state === 'done' ? $who?->signatureUrl() : null,
            ];
        }

        return $stages;
    }

    /** Ringkasan satu baris untuk tabel inbox. */
    public function row(Approval $approval): array
    {
        $stages = $this->stages($approval);
        $current = collect($stages)->first(fn ($s) => in_array($s['state'], ['current', 'rejected'], true));
        $doc = $this->header($approval);
        $submitter = $this->users(collect([$approval->submitted_by]))[$approval->submitted_by] ?? null;

        return [
            'id' => $approval->id,
            'document_type' => $approval->document_type,
            'type' => self::TYPE_LABELS[$approval->document_type] ?? $approval->document_type,
            'number' => $doc['number'],
            'subject' => $doc['title'],
            'department' => $doc['sub'],
            'amount' => $doc['amount'],
            'status' => $approval->status,
            'submitter' => $submitter?->name,
            'submitted_at' => $approval->submitted_at?->toIso8601String(),
            'position' => $current['label'] ?? ($approval->status === 'approved' ? 'Selesai' : '—'),
            'done' => collect($stages)->where('state', 'done')->count(),
            'total' => count($stages),
            'stages' => array_map(fn ($s) => ['label' => $s['label'], 'state' => $s['state']], $stages),
        ];
    }

    /**
     * Isi dokumen untuk halaman detail: judul, kolom ringkas, isi, file, & tautan ke modul asal.
     *
     * @return array{number:?string, title:?string, type:string, fields:list<array{label:string,value:?string}>, body:?string, files:list<array{name:string,jenis:?string,url:string}>, links:list<array{label:string,url:string}>}
     */
    public function detail(Approval $approval): array
    {
        $doc = $this->header($approval);
        $m = $approval->approvable;
        $rp = fn ($v) => $v !== null ? 'Rp '.number_format((float) $v, 0, ',', '.') : null;
        $out = ['number' => $doc['number'], 'title' => $doc['title'], 'type' => self::TYPE_LABELS[$approval->document_type] ?? $approval->document_type,
            'fields' => [], 'body' => null, 'files' => [], 'links' => []];

        if ($m instanceof MemoInternal) {
            $out['fields'] = [
                ['label' => 'Tanggal', 'value' => $m->memo_date?->toDateString()],
                ['label' => 'Departemen', 'value' => $m->department],
                ['label' => 'Nominal', 'value' => $rp($m->amount) ?? 'Tanpa nominal'],
            ];
            $out['body'] = $m->body;
        } elseif ($m instanceof BsApBerkasUpload) {
            $out['fields'] = [
                ['label' => 'Tanggal', 'value' => $m->upload_date?->toDateString()],
                ['label' => 'Supplier', 'value' => $m->supplier?->name ?? '—'],
                ['label' => 'Nominal', 'value' => $rp($m->amount) ?? '—'],
            ];
            $out['files'] = $this->files($m);
            $out['links'][] = ['label' => 'Buka berkas', 'url' => route('bs.ap.berkas.show', $m->id)];
        } elseif ($m instanceof BsApInvoice) {
            $berkas = $this->berkasOf($m);
            $out['fields'] = [
                ['label' => 'Tgl. Invoice', 'value' => $m->invoice_date?->toDateString()],
                ['label' => 'Supplier', 'value' => $m->supplier?->name],
                ['label' => 'No. Invoice Supplier', 'value' => $m->supplier_invoice_no],
                ['label' => 'Total', 'value' => $rp($m->total)],
                ['label' => 'Hutang Bersih', 'value' => $rp($m->net_payable)],
                ['label' => 'Berkas Asal', 'value' => $berkas?->berkas_number],
            ];
            $out['files'] = $berkas ? $this->files($berkas) : [];
            $out['links'][] = ['label' => 'Buka invoice', 'url' => route('bs.ap.show', $m->id)];
            if ($berkas) {
                $out['links'][] = ['label' => 'Buka berkas', 'url' => route('bs.ap.berkas.show', $berkas->id)];
            }
        } elseif ($m instanceof BsBudgetCorrection) {
            $bulan = \App\Domain\BusinessSupport\Models\BsBudget::MONTHS[max(1, (int) $m->period_from) - 1].' '.$m->year;
            $budget = $m->budget;
            $inv = $m->source_type === BsBudgetCorrection::SOURCE_INVOICE ? BsApInvoice::query()->find($m->source_id) : null;
            $out['fields'] = [
                ['label' => 'Periode', 'value' => $bulan],
                ['label' => 'Account', 'value' => trim($m->account?->code.' - '.$m->account?->name, ' -')],
                ['label' => 'Cost Center', 'value' => $m->costCenter ? trim($m->costCenter->code.' '.$m->costCenter->name) : 'Semua'],
                ['label' => 'Anggaran bulan ini (sebelum koreksi)', 'value' => $budget ? $rp(\App\Domain\BusinessSupport\Services\BudgetGuardService::anggaranBulan($budget, (int) $m->period_from)) : null],
                ['label' => 'Nilai pengajuan', 'value' => $rp((float) $m->new_amount - (float) $m->old_amount)],
                ['label' => 'Invoice asal', 'value' => $inv?->invoice_number],
            ];
            $out['body'] = $m->note;
            if ($inv) {
                $out['links'][] = ['label' => 'Buka invoice', 'url' => route('bs.ap.show', $inv->id)];
            }
        }

        return $out;
    }

    // ── internal ─────────────────────────────────────────────────────────────────

    private function base()
    {
        return Approval::query()
            ->whereIn('document_type', self::DOCUMENT_TYPES)
            ->with(['steps', 'approvable' => fn (MorphTo $m) => $m->morphWith([
                BsApBerkasUpload::class => ['supplier:id,name', 'files.jenis:id,name'],
                BsApInvoice::class => ['supplier:id,name'],
                BsBudgetCorrection::class => ['account:id,code,name', 'costCenter:id,code,name', 'budget'],
            ])]);
    }

    /** @return array{number:?string, title:?string, sub:?string, amount:?float} */
    private function header(Approval $approval): array
    {
        $m = $approval->approvable;

        return match (true) {
            $m instanceof MemoInternal => ['number' => $m->memo_number, 'title' => $m->subject, 'sub' => $m->department,
                'amount' => $m->amount !== null ? (float) $m->amount : null],
            $m instanceof BsApBerkasUpload => ['number' => $m->berkas_number, 'title' => $m->description, 'sub' => $m->supplier?->name ?? 'Upload Berkas',
                'amount' => $m->amount !== null ? (float) $m->amount : null],
            $m instanceof BsApInvoice => ['number' => $m->invoice_number, 'title' => $m->description, 'sub' => $m->supplier?->name,
                'amount' => (float) $m->total],
            $m instanceof BsBudgetCorrection => ['number' => $m->doc_no, 'title' => 'Koreksi budget '.$m->account?->code.' '.$m->account?->name,
                'sub' => $m->costCenter?->name ?? 'Semua cost center', 'amount' => round((float) $m->new_amount - (float) $m->old_amount, 2)],
            default => ['number' => '#'.$approval->id, 'title' => null, 'sub' => null, 'amount' => null],
        };
    }

    /** Tahap sebelum chain step: pengaju; untuk invoice — pengunggah berkas, TTD Dept Head, tim AP. */
    private function leadingStages(Approval $approval): array
    {
        $by = fn (?int $id) => $id ? ($this->users(collect([$id]))[$id] ?? null) : null;
        $stage = fn (string $label, string $role, ?User $u, $at) => [
            'label' => $label, 'role' => $role, 'state' => 'done', 'by' => $u?->name,
            'at' => $at?->toIso8601String(), 'notes' => null, 'signature' => $u?->signatureUrl(),
        ];
        $submitter = $by((int) $approval->submitted_by);

        if ($approval->document_type === BsApBerkasUpload::DOCUMENT_TYPE) {
            return [$stage('Pengunggah', 'Upload berkas', $submitter, $approval->submitted_at)];
        }

        if ($approval->document_type === BsApInvoice::APPROVAL_DOCUMENT_TYPE && $approval->approvable instanceof BsApInvoice) {
            $berkas = $this->berkasOf($approval->approvable);
            $lead = [];
            if ($berkas && $berkas->approval_id) {
                $ttd = Approval::with('steps')->find($berkas->approval_id);
                if ($ttd) {
                    $lead[] = $stage('Pengunggah', 'Upload berkas', $by((int) $ttd->submitted_by), $ttd->submitted_at);
                    $s = $ttd->steps->sortBy('level')->first();
                    $lead[] = $stage('Dept. Head (TTD)', 'Ditunjuk', $by((int) $s?->approver_id), $s?->acted_at)
                        + ['notes' => $s?->notes];
                }
            }
            $lead[] = $stage('Tim AP', 'Buat invoice', $submitter, $approval->submitted_at);

            return $lead;
        }

        if ($approval->document_type === BsBudgetCorrection::DOCUMENT_TYPE) {
            return [$stage('Pengaju', 'Koreksi dari invoice hutang', $submitter, $approval->submitted_at)];
        }

        return [$stage('Dept. Head', 'Pengajuan', $submitter, $approval->submitted_at)];
    }

    private function berkasOf(BsApInvoice $invoice): ?BsApBerkasUpload
    {
        // Invoice dari berkas ditandai source_type 'berkas' (satu berkas bisa beberapa invoice).
        return $invoice->source_type === \App\Domain\BusinessSupport\Services\ApBerkasService::INVOICE_SOURCE
            ? BsApBerkasUpload::withTrashed()->with('files.jenis:id,name')->find($invoice->source_id)
            : null;
    }

    /** @return list<array{name:string,jenis:?string,url:string}> */
    private function files(BsApBerkasUpload $berkas): array
    {
        return $berkas->files->map(fn ($f) => [
            'name' => $f->original_name,
            'jenis' => $f->jenis?->name,
            'url' => route('bs.ap.berkas.file', [$berkas->id, $f->id]),
        ])->all();
    }

    /** @var array<int, User> */
    private array $userCache = [];

    /** @return array<int, User> */
    private function users(Collection $ids): array
    {
        $missing = $ids->filter()->map(fn ($v) => (int) $v)->unique()->diff(array_keys($this->userCache));
        if ($missing->isNotEmpty()) {
            User::query()->whereIn('id', $missing)
                ->get(['id', 'name', 'tenant_id', 'signature_path', 'signature_updated_at'])
                ->each(fn (User $u) => $this->userCache[$u->id] = $u);
        }

        return $this->userCache;
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
        if ($approval->document_type === BsApBerkasUpload::DOCUMENT_TYPE) {
            return 'Dept. Head (TTD)';
        }
        if (in_array($approval->document_type, [MemoInternal::DOCUMENT_TYPE, BsApInvoice::APPROVAL_DOCUMENT_TYPE], true)
            && isset(MemoInternal::STEPS[$level])) {
            return MemoInternal::STEPS[$level][1];
        }

        return (string) ($chainStep?->approver_value ?? "Level {$level}");
    }
}
