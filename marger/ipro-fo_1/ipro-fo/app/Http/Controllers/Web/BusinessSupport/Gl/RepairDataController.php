<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Gl;

use App\Domain\BusinessSupport\Models\BsFiscalPeriod;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Repair Data — diagnosa & perbaikan integritas GL (total jurnal, field periode, periode fiskal hilang).
 */
class RepairDataController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('gl.repair.view'), 403);

        return Inertia::render('BusinessSupport/Gl/RepairData', [
            'diagnostics' => $this->diagnose(),
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['gl.repair.run', 'businesssupport.approval.act']), 403);

        $res = DB::transaction(fn () => $this->repair());

        return back()->with('success', "Repair selesai — {$res['totals']} total jurnal dikoreksi, {$res['periods']} field periode diperbaiki, {$res['created']} periode fiskal dibuat.");
    }

    /** @return array<string,int> */
    private function diagnose(): array
    {
        $tid = tenant_id();

        $drift = DB::table('bs_journals as j')
            ->leftJoin('bs_journal_lines as jl', 'jl.journal_id', '=', 'j.id')
            ->whereNull('j.deleted_at')->when($tid, fn ($q) => $q->where('j.tenant_id', $tid))
            ->groupBy('j.id', 'j.total_debit', 'j.total_credit')
            ->havingRaw('ROUND(j.total_debit,2) <> ROUND(COALESCE(SUM(jl.debit),0),2) OR ROUND(j.total_credit,2) <> ROUND(COALESCE(SUM(jl.credit),0),2)')
            ->select('j.id')->get()->count();

        $unbalanced = DB::table('bs_journals as j')
            ->leftJoin('bs_journal_lines as jl', 'jl.journal_id', '=', 'j.id')
            ->whereNull('j.deleted_at')->when($tid, fn ($q) => $q->where('j.tenant_id', $tid))
            ->groupBy('j.id')
            ->havingRaw('ROUND(COALESCE(SUM(jl.debit),0),2) <> ROUND(COALESCE(SUM(jl.credit),0),2)')
            ->select('j.id')->get()->count();

        $mismatch = DB::table('bs_journals')->whereNull('deleted_at')->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->whereRaw("CAST(strftime('%Y', journal_date) AS INTEGER) <> period_year OR CAST(strftime('%m', journal_date) AS INTEGER) <> period_month")
            ->count();

        $orphan = DB::table('bs_journal_lines as jl')
            ->leftJoin('bs_journals as j', 'j.id', '=', 'jl.journal_id')
            ->where(fn ($q) => $q->whereNull('j.id')->orWhereNotNull('j.deleted_at'))
            ->count();

        $missing = 0;
        foreach ($this->journalPeriods($tid) as $p) {
            if (! BsFiscalPeriod::where('year', $p->period_year)->where('month', $p->period_month)->exists()) {
                $missing++;
            }
        }

        return [
            'total_drift'     => $drift,
            'unbalanced'      => $unbalanced,
            'period_mismatch' => $mismatch,
            'missing_periods' => $missing,
            'orphan_lines'    => $orphan,
        ];
    }

    /** @return array<string,int> */
    private function repair(): array
    {
        $tid = tenant_id();

        // 1) Hitung ulang total_debit/total_credit dari baris.
        $driftRows = DB::table('bs_journals as j')
            ->leftJoin('bs_journal_lines as jl', 'jl.journal_id', '=', 'j.id')
            ->whereNull('j.deleted_at')->when($tid, fn ($q) => $q->where('j.tenant_id', $tid))
            ->groupBy('j.id', 'j.total_debit', 'j.total_credit')
            ->havingRaw('ROUND(j.total_debit,2) <> ROUND(COALESCE(SUM(jl.debit),0),2) OR ROUND(j.total_credit,2) <> ROUND(COALESCE(SUM(jl.credit),0),2)')
            ->selectRaw('j.id, ROUND(COALESCE(SUM(jl.debit),0),2) sd, ROUND(COALESCE(SUM(jl.credit),0),2) sc')->get();
        foreach ($driftRows as $r) {
            DB::table('bs_journals')->where('id', $r->id)->update(['total_debit' => $r->sd, 'total_credit' => $r->sc]);
        }

        // 2) Selaraskan period_year/period_month dengan journal_date.
        $mismatchRows = DB::table('bs_journals')->whereNull('deleted_at')->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->whereRaw("CAST(strftime('%Y', journal_date) AS INTEGER) <> period_year OR CAST(strftime('%m', journal_date) AS INTEGER) <> period_month")
            ->select('id', 'journal_date')->get();
        foreach ($mismatchRows as $r) {
            $d = Carbon::parse($r->journal_date);
            DB::table('bs_journals')->where('id', $r->id)->update(['period_year' => (int) $d->year, 'period_month' => (int) $d->month]);
        }

        // 3) Pastikan periode fiskal ada untuk tiap (tahun, bulan) yang dipakai jurnal.
        $created = 0;
        foreach ($this->journalPeriods($tid) as $p) {
            if (! BsFiscalPeriod::where('year', $p->period_year)->where('month', $p->period_month)->exists()) {
                BsFiscalPeriod::create(['year' => $p->period_year, 'month' => $p->period_month, 'status' => 'open']);
                $created++;
            }
        }

        return ['totals' => $driftRows->count(), 'periods' => $mismatchRows->count(), 'created' => $created];
    }

    /** @return \Illuminate\Support\Collection<int,object> */
    private function journalPeriods(?int $tid)
    {
        return DB::table('bs_journals')->whereNull('deleted_at')->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->select('period_year', 'period_month')->distinct()->get();
    }
}
