<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Modules\FO\Approval\Models\Approval;
use App\Modules\FO\Approval\Services\ApprovalMobileMapper;
use App\Modules\FO\Approval\Services\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API approval untuk aplikasi mobile approver. Memetakan semua jenis approval
 * J-ERP ke kontrak app (mockService.ts), dibacking ApprovalService (alur 2-level
 * / single-level GM) yang sudah ada.
 */
class ApprovalController
{
    public function __construct(
        private readonly ApprovalMobileMapper $mapper,
        private readonly ApprovalService $service,
    ) {}

    /** Queue: needs (perlu diputuskan) / inchain (sudah di-endorse, menunggu final) / done. */
    public function queue(Request $request): JsonResponse
    {
        $me = (int) $request->user()->id;

        $pending = Approval::with(['requester', 'subject'])
            ->where('status', 'PENDING')->latest('tgl_approval')->get();

        // "in chain" = saya sudah endorse L1, menunggu L2 final. Sisanya = "needs you".
        [$inchain, $needs] = $pending->partition(
            fn (Approval $a) => (int) $a->current_level === 2 && (int) $a->level1_by === $me
        );

        $done = Approval::with(['requester', 'subject', 'approver'])
            ->whereIn('status', ['EXECUTED', 'APPROVED', 'REJECTED'])
            ->latest('approved_at')->limit(50)->get();

        return response()->json([
            'needs' => $needs->values()->map(fn (Approval $a) => $this->mapper->toRequestItem($a)),
            'inchain' => $inchain->values()->map(fn (Approval $a) => $this->mapper->toRequestItem($a)),
            'done' => $done->map(fn (Approval $a) => $this->mapper->toHistoryEntry($a)),
        ]);
    }

    public function show(Approval $approval): JsonResponse
    {
        $approval->load(['requester', 'subject', 'approver', 'level1Approver']);

        return response()->json($this->mapper->toRequestItem($approval));
    }

    /** Dokumen pendukung — approval J-ERP belum punya lampiran (kosong dulu). */
    public function docs(Approval $approval): JsonResponse
    {
        return response()->json([]);
    }

    /** approve / reject — memajukan rantai (approveLevel1/2) atau menolak. */
    public function decide(Request $request, Approval $approval): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:approved,rejected'],
            'reason' => ['nullable', 'string'],
        ]);

        if ($approval->status !== 'PENDING') {
            return response()->json(['ok' => false, 'message' => 'Approval tidak sedang menunggu keputusan.'], 422);
        }

        try {
            if ($data['action'] === 'rejected') {
                $this->service->reject($approval, $data['reason'] ?: 'Ditolak');
            } elseif ((int) $approval->current_level === 1) {
                $this->service->approveLevel1($approval, $data['reason'] ?? null);
            } else {
                $this->service->approveLevel2($approval, $data['reason'] ?? null);
            }
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'decision' => $data['action'],
            'request' => $this->mapper->toRequestItem($approval->fresh()->load(['requester', 'subject', 'approver', 'level1Approver'])),
        ]);
    }

    /** Bulk approve / reject. */
    public function decideMany(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
            'action' => ['required', 'in:approved,rejected'],
            'reason' => ['nullable', 'string'],
        ]);

        $count = 0;
        foreach (Approval::whereIn('id', $data['ids'])->where('status', 'PENDING')->get() as $a) {
            try {
                if ($data['action'] === 'rejected') {
                    $this->service->reject($a, $data['reason'] ?: 'Ditolak');
                } elseif ((int) $a->current_level === 1) {
                    $this->service->approveLevel1($a, $data['reason'] ?? null);
                } else {
                    $this->service->approveLevel2($a, $data['reason'] ?? null);
                }
                $count++;
            } catch (\Throwable $e) {
                // lewati yang gagal, lanjut batch
            }
        }

        return response()->json(['ok' => $count > 0, 'count' => $count]);
    }

    /**
     * Reviewer menandai dokumen sesuai / minta revisi. J-ERP belum memodelkan
     * tahap reviewer — endpoint tetap ada agar app tak rusak (no-op aman).
     */
    public function review(Request $request, Approval $approval): JsonResponse
    {
        $request->validate(['ok' => ['required', 'boolean'], 'reason' => ['nullable', 'string']]);

        return response()->json(['ok' => true, 'decision' => $request->boolean('ok') ? 'approved' : 'rejected']);
    }

    public function history(Request $request): JsonResponse
    {
        $rows = Approval::with(['requester', 'subject', 'approver'])
            ->whereIn('status', ['EXECUTED', 'APPROVED', 'REJECTED'])
            ->latest('approved_at')->limit(100)->get();

        return response()->json($rows->map(fn (Approval $a) => $this->mapper->toHistoryEntry($a)));
    }

    /** Spesimen tanda tangan approver — belum ada di J-ERP (kosong dulu). */
    public function signature(Request $request): JsonResponse
    {
        return response()->json(['sig' => '']);
    }
}
