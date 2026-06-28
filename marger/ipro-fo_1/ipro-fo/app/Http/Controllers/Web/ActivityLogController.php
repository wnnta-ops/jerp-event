<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Crm\Enums\ActivityCategory;
use App\Domain\Crm\Models\ActivityLog;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /**
     * List activity logs for a subject (JSON, for tab Jurnal).
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', 'string', 'max:50'],
            'subject_id'   => ['required', 'integer'],
        ]);

        $logs = ActivityLog::query()
            ->where('subject_type', $data['subject_type'])
            ->where('subject_id', $data['subject_id'])
            ->with('loggedBy:id,name')
            ->latest('logged_at')
            ->limit(100)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id'             => $log->id,
                'category'       => $log->category->value,
                'category_label' => $log->category->label(),
                'category_color' => $log->category->color(),
                'description'    => $log->description,
                'logged_at'      => $log->logged_at?->toIso8601String(),
                'logged_by'      => $log->loggedBy?->name ?? 'system',
                'attachment'     => $log->attachment_name,
            ]);

        return response()->json([
            'logs'       => $logs,
            'categories' => ActivityCategory::options(),
        ]);
    }

    /**
     * Store a new activity log entry.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', 'string', 'max:50'],
            'subject_id'   => ['required', 'integer'],
            'category'     => ['required', 'string'],
            'description'  => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $category = ActivityCategory::tryFrom($data['category']);
        abort_unless($category !== null, 422, 'Kategori tidak valid.');

        ActivityLog::create([
            'tenant_id'    => $request->user()?->tenant_id ?? 1,
            'subject_type' => $data['subject_type'],
            'subject_id'   => $data['subject_id'],
            'category'     => $category->value,
            'description'  => $data['description'],
            'logged_at'    => now(),
            'logged_by'    => $request->user()?->id,
        ]);

        return back()->with('success', 'Aktivitas dicatat ke jurnal.');
    }
}
