<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Legal;

use App\Domain\Legal\Ppjb\Actions\IssuePpjb;
use App\Domain\Legal\Ppjb\Models\PpjbDocument;
use App\Domain\Sales\Contract\Models\Contract;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PpjbController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('legal.ppjb.view'), 403);

        $rows = PpjbDocument::query()
            ->with(['contract:id,contract_number,booking_id'])
            ->latest('signed_at')
            ->paginate(50);

        return Inertia::render('Legal/Ppjb/Index', [
            'rows' => $rows,
        ]);
    }

    public function issue(Contract $contract, IssuePpjb $action): RedirectResponse
    {
        abort_unless(auth()->user()?->can('legal.ppjb.issue'), 403);

        $ppjb = $action->execute($contract);
        return back()->with('success', "PPJB {$ppjb->ppjb_number} diterbitkan.");
    }
}
