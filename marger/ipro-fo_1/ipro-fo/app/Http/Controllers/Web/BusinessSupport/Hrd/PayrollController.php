<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Hrd;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsEmployee;
use App\Domain\BusinessSupport\Models\BsPayrollRun;
use App\Domain\BusinessSupport\Services\PayrollService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PayrollController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('bs.payroll.view'), 403);

        $rows = BsPayrollRun::query()->withCount('lines')->latest('run_date')->latest('id')->paginate(25);

        return Inertia::render('BusinessSupport/Hrd/Payroll', [
            'runs'         => $rows,
            'now'          => ['year' => (int) now()->year, 'month' => (int) now()->month],
            'activeCount'  => BsEmployee::where('is_active', true)->count(),
            'estBruto'     => (float) BsEmployee::where('is_active', true)->sum(DB::raw('base_salary + allowance')),
            'cashAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)->where('code', 'like', '101010%')->orderBy('code')->get(['id', 'code', 'name']),
            'defaultCash'  => BsAccount::where('code', config('business_support.gl_accounts.cash'))->value('id'),
        ]);
    }

    public function store(Request $request, PayrollService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('bs.payroll.create'), 403);

        $v = $request->validate([
            'period_year'     => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month'    => ['required', 'integer', 'min:1', 'max:12'],
            'run_date'        => ['required', 'date'],
            'cash_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            'pph21_pct'       => ['nullable', 'numeric', 'min:0', 'max:100'],
            'bpjs_pct'        => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        try {
            $run = $svc->processRun($v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.payroll.show', $run)->with('success', "Payroll {$run->run_number} diproses — jurnal otomatis terbentuk.");
    }

    public function show(Request $request, BsPayrollRun $payroll): Response
    {
        abort_unless($request->user()?->can('bs.payroll.view'), 403);

        return Inertia::render('BusinessSupport/Hrd/PayrollShow', [
            'run'   => $payroll->load('lines'),
        ]);
    }
}
