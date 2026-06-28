<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Security;

use App\Domain\Security\Models\SecAttendance;
use App\Domain\Security\Models\SecTechnicalProblem;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Modul SECURITY (mirror eProperty) — hub + Security Log (audit viewer).
 */
class SecurityController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('security.view'), 403);

        return Inertia::render('Security/Index', [
            'stats' => [
                'users'        => User::count(),
                'absen_today'  => SecAttendance::whereDate('date', now()->toDateString())->count(),
                'tech_open'    => SecTechnicalProblem::whereIn('status', ['open', 'in_progress'])->count(),
                'audit_today'  => DB::table('audit_logs')->whereDate('created_at', now()->toDateString())->count(),
            ],
        ]);
    }

    /** Security Log — viewer atas tabel audit_logs. */
    public function log(Request $request): Response
    {
        abort_unless($request->user()?->can('security.view'), 403);

        $rows = DB::table('audit_logs')
            ->when($request->input('event'), fn ($q, $v) => $q->where('event', $v))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('auditable_type', 'like', "%{$s}%")->orWhere('ip_address', 'like', "%{$s}%")))
            ->when($request->input('date'), fn ($q, $d) => $q->whereDate('created_at', $d))
            ->orderByDesc('id')->paginate(60)->withQueryString();

        // Resolve user names.
        $userMap = User::whereIn('id', collect($rows->items())->pluck('user_id')->filter()->unique())->pluck('name', 'id');
        $rows->getCollection()->transform(fn ($r) => (array) $r + ['user_name' => $userMap[$r->user_id] ?? null]);

        return Inertia::render('Security/Log', [
            'logs'    => $rows,
            'filters' => $request->only(['event', 'search', 'date']),
            'events'  => DB::table('audit_logs')->select('event')->distinct()->orderBy('event')->pluck('event'),
        ]);
    }
}
