<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\FoBridge;

use App\Domain\Approval\Actions\ActOnApproval;
use App\Domain\Approval\Models\Approval;
use App\Domain\Approval\Models\ApprovalChain;
use App\Domain\Approval\Models\ApprovalChainStep;
use App\Domain\ApprovalInbox\Models\MemoInternal;
use App\Domain\ApprovalInbox\Services\ApprovalInboxQuery;
use App\Domain\BusinessSupport\Models\BsEmployee;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Approval Inbox untuk app approver (PWA) — dipanggil FO lewat VerifyFoBridge atas nama
 * user FO. Isi & hak akses SAMA dengan halaman web /approval-inbox (ApprovalInboxQuery,
 * ActOnApproval); di sini hanya dibentuk jadi JSON ringkas untuk HP.
 *
 * Kategori: 'urgent' = Memo Internal jenis urgent; sisanya (memo reguler, TTD Berkas,
 * Invoice Hutang, Alokasi & Koreksi Budget) = 'reguler'.
 */
class ApprovalInboxBridgeController extends Controller
{
    public function __construct(private readonly ApprovalInboxQuery $inbox) {}

    /** Perlu persetujuan saya. */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $rows = $this->inbox->needsMe($user)->map(fn (Approval $a) => $this->baris($a))->values();

        return response()->json([
            'data' => $rows,
            'counts' => [
                'urgent' => $rows->where('kategori', 'urgent')->count(),
                'reguler' => $rows->where('kategori', 'reguler')->count(),
            ],
        ]);
    }

    /** Yang sudah saya setujui / tolak, terbaru dulu. */
    public function history(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $rows = $this->inbox->history($user)->map(function (Approval $a) use ($user) {
            $langkah = $a->steps->where('approver_id', $user->id)->where('status', '!=', 'pending')->sortByDesc('acted_at')->first();

            return $this->baris($a) + [
                'keputusan' => $langkah?->status,
                'diputuskan_at' => $langkah?->acted_at?->toIso8601String(),
                'catatan_saya' => $langkah?->notes,
            ];
        })->values();

        return response()->json(['data' => $rows]);
    }

    /**
     * Dokumen yang SAYA ajukan, 90 hari terakhir — ringkas, untuk notifikasi "ajuan saya diputuskan".
     * status: pending | in_review | approved | rejected | cancelled.
     */
    public function pengajuan(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $batas = now()->subDays(90);

        $rows = $this->inbox->mine($user)
            ->filter(fn (Approval $a) => $a->submitted_at === null || $a->submitted_at->greaterThanOrEqualTo($batas))
            ->map(function (Approval $a) {
                $r = $this->inbox->row($a);

                return array_intersect_key($r, array_flip(['id', 'type', 'number', 'subject', 'amount', 'status', 'position']));
            })->values();

        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, int $approval): JsonResponse
    {
        $user = $this->user($request);
        $approval = $this->muat($approval);
        abort_unless($this->inbox->canView($user, $approval), 403, 'Anda tidak berhak melihat dokumen ini.');

        $doc = $this->inbox->detail($approval);

        return response()->json([
            'data' => $this->baris($approval, $doc) + [
                'fields' => $doc['fields'],
                'body' => $doc['body'],
                'jumlah_file' => count($doc['files']),
                'dokumen' => app(DokumenApproval::class)->daftar($approval),
                'stages' => $this->inbox->stages($approval),
                'can_act' => $this->inbox->canAct($user, $approval),
                // Revisi Finance atas alokasi / koreksi: ditampilkan saja — mengubahnya tetap di BO (desktop).
                'revisi' => (int) ($doc['revisi'] ?? 0),
                'riwayat_revisi' => array_values($doc['riwayat_revisi'] ?? []),
                'bisa_revisi' => app(\App\Domain\BusinessSupport\Services\BudgetIkutInvoice::class)->bisaRevisi($user, $approval),
            ],
        ]);
    }

    /** Isi satu dokumen untuk preview / unduh di PWA (lihat DokumenApproval). */
    public function dokumen(Request $request, int $approval, string $kunci): \Symfony\Component\HttpFoundation\Response
    {
        $user = $this->user($request);
        $approval = $this->muat($approval);
        abort_unless($this->inbox->canView($user, $approval), 403, 'Anda tidak berhak melihat dokumen ini.');

        return app(DokumenApproval::class)->isi($approval, $kunci, $request);
    }

    public function act(Request $request, int $approval, ActOnApproval $action): JsonResponse
    {
        $this->user($request);
        $approval = $this->muat($approval);

        $data = $request->validate([
            'action' => 'required|in:approve,reject',
            'notes' => 'nullable|required_if:action,reject|string|max:500',
        ], ['notes.required_if' => 'Alasan penolakan wajib diisi.']);

        try {
            $action->execute($approval->load('steps'), $data['action'], $data['notes'] ?? null);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Dokumen '.($data['action'] === 'approve' ? 'disetujui' : 'ditolak').'.',
        ]);
    }

    /**
     * Profil approver: chain aktif Approval Inbox yang melewati saya (tahap mana dari berapa)
     * dan ringkasan keputusan. "Saya" di tahap = user (id), role BO, atau Dept Head
     * ('assigned' — selama saya atasan minimal satu karyawan aktif di Employee Center).
     */
    public function profil(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $roles = $user->getRoleNames()->all();
        $deptHead = $this->atasanAktif($user);

        $milikSaya = fn (ApprovalChainStep $s): bool => match ($s->approver_type) {
            'user' => (int) $s->approver_value === (int) $user->id,
            'role' => in_array((string) $s->approver_value, $roles, true),
            'assigned' => $deptHead,
            default => false,
        };

        $chains = ApprovalChain::query()
            ->where('is_active', true)
            ->whereIn('document_type', ApprovalInboxQuery::DOCUMENT_TYPES)
            ->with('steps')
            ->orderBy('document_type')->orderBy('code')
            ->get()
            ->map(fn (ApprovalChain $c) => [
                'code' => $c->code,
                'name' => $c->name,
                'type' => ApprovalInboxQuery::TYPE_LABELS[$c->document_type] ?? $c->document_type,
                'kategori' => $c->document_type === MemoInternal::DOCUMENT_TYPE && ($c->match_conditions['jenis'] ?? null) === 'urgent' ? 'urgent' : 'reguler',
                'steps' => $c->steps->map(fn (ApprovalChainStep $s) => [
                    'level' => $s->level,
                    'label' => $s->label ?: $s->approver_value,
                    'saya' => $milikSaya($s),
                ])->values(),
            ])
            ->filter(fn (array $c) => $c['steps']->contains('saya', true))
            ->values();

        $riwayat = $this->inbox->history($user)
            ->map(fn (Approval $a) => $a->steps->where('approver_id', $user->id)->where('status', '!=', 'pending')->sortByDesc('acted_at')->first()?->status);

        return response()->json([
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $roles,
                'superadmin' => $user->hasRole('SuperAdmin'),
                'dept_head' => $deptHead,
                // TTD saya sendiri (preview saja) — diubah di BO: Pengaturan › Tanda Tangan.
                'ttd' => $this->ttd($user),
                'ttd_diperbarui' => $user->signature_updated_at?->toIso8601String(),
            ],
            'chains' => $chains,
            'ringkasan' => [
                'menunggu' => $this->inbox->needsMe($user)->count(),
                'disetujui' => $riwayat->filter(fn ($s) => $s === 'approved')->count(),
                'ditolak' => $riwayat->filter(fn ($s) => $s === 'rejected')->count(),
            ],
        ]);
    }

    // ── internal ─────────────────────────────────────────────────────────────────

    /** TTD sebagai data URI PNG; disk sama dengan UserSignatureController ('local'). */
    private function ttd(\App\Models\User $user): ?string
    {
        $disk = Storage::disk('local');
        if (! $user->signature_path || ! $disk->exists($user->signature_path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($disk->get($user->signature_path));
    }

    /** Saya atasan langsung minimal satu karyawan aktif di Employee Center (users.employee_nip = nik). */
    private function atasanAktif(\App\Models\User $user): bool
    {
        if (! $user->employee_nip) {
            return false;
        }
        $saya = BsEmployee::query()->where('nik', $user->employee_nip)->value('id');

        return $saya !== null && BsEmployee::query()->where('atasan_id', $saya)->where('is_active', true)->exists();
    }

    private function user(Request $request): \App\Models\User
    {
        $user = $request->user();
        abort_unless($user?->can('approvalinbox.view'), 403, 'Akun Anda belum punya akses Approval Inbox di BO.');

        return $user;
    }

    private function muat(int $id): Approval
    {
        $approval = Approval::findOrFail($id);
        abort_unless(in_array($approval->document_type, ApprovalInboxQuery::DOCUMENT_TYPES, true), 404);

        return $approval->load('steps')->loadMorph('approvable', [
            \App\Domain\BusinessSupport\Models\BsApBerkasUpload::class => ['supplier:id,name', 'files.jenis:id,name'],
            \App\Domain\BusinessSupport\Models\BsApInvoice::class => ['supplier:id,name'],
            \App\Domain\BusinessSupport\Models\BsBudgetCorrection::class => ['account:id,code,name', 'costCenter:id,code,name', 'budget'],
            \App\Domain\BusinessSupport\Models\BsBudgetAllocation::class => ['account:id,code,name', 'costCenter:id,code,name', 'budget', 'toBudget.account:id,code,name', 'toBudget.costCenter:id,code,name'],
            MemoInternal::class => ['supplier:id,name'],
        ]);
    }

    /** Baris inbox + kategori & catatan singkat untuk kartu di HP. */
    private function baris(Approval $approval, ?array $doc = null): array
    {
        $m = $approval->approvable;
        $doc ??= $this->inbox->detail($approval);
        $row = $this->inbox->row($approval);
        unset($row['stages']);
        // Alokasi / koreksi turunan invoice: tanpa id budget & URL lampiran BO (lampiran lewat dokumen/{alokasi|koreksi}-ID).
        $row['turunan'] = array_map(fn (array $t) => array_intersect_key($t, array_flip(['jenis', 'id', 'doc_no', 'status', 'dari', 'ke', 'nilai', 'keterangan'])),
            $row['turunan'] ?? []);

        return $row + [
            'kategori' => $m instanceof MemoInternal && $m->jenis === 'urgent' ? 'urgent' : 'reguler',
            'catatan' => $doc['body'] !== null ? Str::limit(trim(strip_tags((string) $doc['body'])), 160) : null,
        ];
    }
}
