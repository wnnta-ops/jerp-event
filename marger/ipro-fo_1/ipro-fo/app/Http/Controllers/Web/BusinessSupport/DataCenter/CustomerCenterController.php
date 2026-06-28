<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\DataCenter;

use App\Domain\Master\Customer\Models\Customer;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Customer Center: Repair Data (list/create/merge/upload sudah ada di customers.*). */
class CustomerCenterController extends Controller
{
    public function repair(Request $request): Response
    {
        abort_unless($request->user()?->can('master.customer.view'), 403);

        $dupCode = Customer::whereNotNull('code')->where('code', '!=', '')->select('code')->groupBy('code')->havingRaw('COUNT(*) > 1')->pluck('code');
        $noCode  = Customer::whereNull('code')->orWhere('code', '')->count();
        $noName  = Customer::whereNull('full_name')->orWhere('full_name', '')->count();

        return Inertia::render('BusinessSupport/DataCenter/Customer/Repair', [
            'issues' => [
                ['key' => 'dup_code', 'label' => 'Kode customer duplikat', 'count' => $dupCode->count()],
                ['key' => 'no_code', 'label' => 'Customer tanpa kode', 'count' => $noCode],
                ['key' => 'no_name', 'label' => 'Customer tanpa nama', 'count' => $noName],
            ],
        ]);
    }

    public function repairRun(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('master.customer.import'), 403);
        $fixed = 0;
        DB::transaction(function () use (&$fixed): void {
            foreach (Customer::where(fn ($q) => $q->whereNull('code')->orWhere('code', ''))->get() as $c) {
                $c->update(['code' => 'CUST-'.str_pad((string) $c->id, 5, '0', STR_PAD_LEFT)]);
                $fixed++;
            }
        });

        return back()->with('success', "Repair selesai — $fixed kode customer dibuatkan.");
    }
}
