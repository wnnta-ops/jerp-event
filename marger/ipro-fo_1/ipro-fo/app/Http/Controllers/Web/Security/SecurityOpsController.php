<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Security;

use App\Domain\BusinessSupport\Models\BsEmployee;
use App\Domain\Security\Models\SecAttendance;
use App\Domain\Security\Models\SecJobHistory;
use App\Domain\Security\Models\SecTechnicalProblem;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Security ops: Absensi, Job History, Technical Problem.
 */
class SecurityOpsController extends Controller
{
    // ── Absensi ──
    public function absensi(Request $request): Response
    {
        abort_unless($request->user()?->can('security.view'), 403);
        $date = $request->input('date') ?: now()->toDateString();

        return Inertia::render('Security/Absensi', [
            'date'    => $date,
            'rows'    => SecAttendance::with('employee:id,nik,name,position')->whereDate('date', $date)->latest('id')->get(),
            'employees' => BsEmployee::where('is_active', true)->orderBy('name')->get(['id', 'nik', 'name', 'position']),
            'statuses'  => SecAttendance::STATUSES,
        ]);
    }

    public function storeAbsensi(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('security.manage'), 403);
        $v = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:bs_employees,id'],
            'date'        => ['required', 'date'],
            'clock_in'    => ['nullable', 'date_format:H:i'],
            'clock_out'   => ['nullable', 'date_format:H:i'],
            'status'      => ['required', 'in:hadir,izin,sakit,alpa,cuti,dinas'],
            'note'        => ['nullable', 'string', 'max:255'],
        ]);
        // Catatan: pakai whereDate agar cocok dengan kolom DATE yang tersimpan
        // bersama komponen waktu (00:00:00) — updateOrCreate(['date'=>...]) gagal
        // mencocokkan format sehingga memicu duplikasi/unique violation.
        $attendance = SecAttendance::where('employee_id', $v['employee_id'])
            ->whereDate('date', $v['date'])->first();
        if ($attendance) {
            $attendance->update([...$v, 'created_by' => $request->user()->id]);
        } else {
            SecAttendance::create([...$v, 'created_by' => $request->user()->id]);
        }

        return back()->with('success', 'Absensi dicatat.');
    }

    // ── Job History ──
    public function jobHistory(Request $request): Response
    {
        abort_unless($request->user()?->can('security.view'), 403);
        $employeeId = $request->integer('employee_id') ?: null;

        return Inertia::render('Security/JobHistory', [
            'employeeId' => $employeeId,
            'rows'       => SecJobHistory::with('employee:id,nik,name')
                ->when($employeeId, fn ($q, $id) => $q->where('employee_id', $id))
                ->orderByDesc('start_date')->get(),
            'employees'  => BsEmployee::orderBy('name')->get(['id', 'nik', 'name', 'position']),
        ]);
    }

    public function storeJobHistory(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('security.manage'), 403);
        $v = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:bs_employees,id'],
            'position'    => ['required', 'string', 'max:150'],
            'division'    => ['nullable', 'string', 'max:150'],
            'start_date'  => ['required', 'date'],
            'end_date'    => ['nullable', 'date', 'after_or_equal:start_date'],
            'note'        => ['nullable', 'string', 'max:255'],
        ]);
        SecJobHistory::create([...$v, 'created_by' => $request->user()->id]);

        return back()->with('success', 'Riwayat jabatan ditambahkan.');
    }

    // ── Technical Problem ──
    public function technical(Request $request): Response
    {
        abort_unless($request->user()?->can('security.view'), 403);

        return Inertia::render('Security/Technical', [
            'rows' => SecTechnicalProblem::with(['reporter:id,name', 'assignee:id,name'])
                ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
                ->latest('id')->paginate(50)->withQueryString(),
            'filters'    => $request->only('status'),
            'users'      => User::orderBy('name')->get(['id', 'name']),
            'priorities' => SecTechnicalProblem::PRIORITIES,
            'statuses'   => SecTechnicalProblem::STATUSES,
        ]);
    }

    public function storeTechnical(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('security.manage'), 403);
        $v = $request->validate([
            'title'       => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'module'      => ['nullable', 'string', 'max:100'],
            'priority'    => ['required', 'in:low,medium,high'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        SecTechnicalProblem::create([
            ...$v, 'ticket_no' => $this->nextTicket(), 'status' => 'open', 'reported_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Technical problem dicatat.');
    }

    public function updateTechnical(Request $request, SecTechnicalProblem $problem): RedirectResponse
    {
        abort_unless($request->user()?->can('security.manage'), 403);
        $v = $request->validate([
            'status'      => ['required', 'in:open,in_progress,resolved,closed'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $problem->update([
            'status'      => $v['status'],
            'assigned_to' => $v['assigned_to'] ?? $problem->assigned_to,
            'resolved_at' => in_array($v['status'], ['resolved', 'closed'], true) ? ($problem->resolved_at ?? now()) : null,
        ]);

        return back()->with('success', 'Status diperbarui.');
    }

    private function nextTicket(): string
    {
        $d = Carbon::now();
        $seq = SecTechnicalProblem::withTrashed()->whereYear('created_at', $d->year)->count() + 1;
        do {
            $no = sprintf('TP/%s/%05d', $d->format('y'), $seq);
            $seq++;
        } while (SecTechnicalProblem::withTrashed()->where('ticket_no', $no)->exists());

        return $no;
    }
}
