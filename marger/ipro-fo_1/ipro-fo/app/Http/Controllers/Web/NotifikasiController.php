<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lonceng notifikasi topbar — membaca tabel `notifications` milik user yang login
 * (diisi InboxNotifier & SendNotificationJob kanal inApp).
 */
class NotifikasiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $base = $this->milikSaya($request);

        return response()->json([
            'unread' => (clone $base)->whereNull('read_at')->count(),
            'items' => (clone $base)->latest('created_at')->limit(15)->get(['id', 'data', 'read_at', 'created_at'])
                ->map(function ($n) {
                    $d = json_decode((string) $n->data, true) ?: [];

                    return [
                        'id' => $n->id,
                        'subject' => $d['subject'] ?? 'Notifikasi',
                        'body' => $d['body'] ?? '',
                        'url' => $d['payload']['url'] ?? null,
                        'read' => $n->read_at !== null,
                        'at' => \Illuminate\Support\Carbon::parse($n->created_at)->toIso8601String(),
                    ];
                }),
        ]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $this->milikSaya($request)->where('id', $id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->milikSaya($request)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    private function milikSaya(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401);

        return DB::table('notifications')
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->id);
    }
}
