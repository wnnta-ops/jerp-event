<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\DataCenter;

use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsEmployee;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/** Employee Center: Registrasi (page), Merge, Repair, HR Department (master). */
class EmployeeCenterController extends Controller
{
    /** Registrasi Karyawan — halaman penuh (POST ke bs.employees.store yang sudah ada). */
    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('datacenter.employee.create'), 403);

        return Inertia::render('BusinessSupport/DataCenter/Employee/Create', [
            // Isian "Departemen" pada layar itu bersumber cost center — satu isian, bukan dua
            // master yang menanyakan hal yang sama. Master HR Department tidak pernah diisi.
            'costCenters' => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    // ── Merge Karyawan ──
    public function mergeForm(Request $request): Response
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        return Inertia::render('BusinessSupport/DataCenter/Employee/Merge', [
            'employees' => BsEmployee::orderBy('name')->get(['id', 'nik', 'name']),
        ]);
    }

    public function mergeApply(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['datacenter.employee.merge', 'businesssupport.setup.manage']), 403);
        $v = $request->validate(['source_id' => ['required', 'integer', 'different:target_id', 'exists:bs_employees,id'], 'target_id' => ['required', 'integer', 'exists:bs_employees,id']]);

        $moved = 0;
        foreach (['bs_payroll_lines', 'sec_attendances', 'sec_job_histories'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'employee_id')) {
                $moved += DB::table($table)->where('employee_id', $v['source_id'])->update(['employee_id' => $v['target_id']]);
            }
        }
        BsEmployee::where('id', $v['source_id'])->delete();

        return to_route('bs.employees.index')->with('success', "Karyawan digabung — $moved referensi dipindahkan.");
    }

    // ── Repair Data Karyawan ──
    public function repair(Request $request): Response
    {
        abort_unless($request->user()?->can('datacenter.employee.repair'), 403);

        $dupNik = BsEmployee::whereNotNull('nik')->where('nik', '!=', '')->select('nik')->groupBy('nik')->havingRaw('COUNT(*) > 1')->pluck('nik');
        $noNik = BsEmployee::whereNull('nik')->orWhere('nik', '')->count();

        return Inertia::render('BusinessSupport/DataCenter/Employee/Repair', [
            'issues' => [
                ['key' => 'dup_nik', 'label' => 'NIK karyawan duplikat', 'count' => $dupNik->count()],
                ['key' => 'no_nik', 'label' => 'Karyawan tanpa NIK', 'count' => $noNik],
            ],
        ]);
    }

    public function repairRun(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['datacenter.employee.repair', 'businesssupport.setup.manage']), 403);
        $fixed = 0;
        DB::transaction(function () use (&$fixed): void {
            foreach (BsEmployee::where(fn ($q) => $q->whereNull('nik')->orWhere('nik', ''))->get() as $e) {
                $e->update(['nik' => 'EMP-'.str_pad((string) $e->id, 4, '0', STR_PAD_LEFT)]);
                $fixed++;
            }
        });

        return back()->with('success', "Repair selesai — $fixed NIK dibuatkan.");
    }
}
