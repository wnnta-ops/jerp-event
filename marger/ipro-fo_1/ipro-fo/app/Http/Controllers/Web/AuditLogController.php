<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Foundation\Audit\Models\AuditLog;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * Generic per-record audit log viewer.
     * Returns last N audit entries for a given morph type + id.
     */
    public function forRecord(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'max:50'],   // morph alias e.g. 'booking', 'contract'
            'id'   => ['required', 'integer'],
        ]);

        $logs = AuditLog::query()
            ->where('auditable_type', $data['type'])
            ->where('auditable_id', $data['id'])
            ->with('auditable')
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id'         => $log->id,
                'event'      => $log->event,
                'user'       => optional(\App\Models\User::find($log->user_id))->name ?? 'system',
                'changes'    => $this->diffSummary($log),
                'ip'         => $log->ip_address,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return response()->json(['logs' => $logs]);
    }

    /**
     * Build a compact human-readable change summary.
     *
     * @return array<int, array{field: string, from: mixed, to: mixed}>
     */
    private function diffSummary(AuditLog $log): array
    {
        $old = $log->old_values ?? [];
        $new = $log->new_values ?? [];
        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));

        $out = [];
        foreach ($keys as $key) {
            $from = $old[$key] ?? null;
            $to   = $new[$key] ?? null;
            if ($from === $to) {
                continue;
            }
            $out[] = ['field' => $key, 'from' => $from, 'to' => $to];
        }

        return $out;
    }
}
