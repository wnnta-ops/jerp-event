<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Legal;

use App\Domain\Legal\Ajb\Actions\IssueAjb;
use App\Domain\Legal\Ajb\Models\AjbDocument;
use App\Domain\Sales\Booking\Models\Booking;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AjbController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('legal.ajb.view'), 403);

        $rows = AjbDocument::query()
            ->with(['booking:id,booking_number,customer_id'])
            ->latest('signed_at')
            ->paginate(50);

        return Inertia::render('Legal/Ajb/Index', ['rows' => $rows]);
    }

    public function issue(Request $request, Booking $booking, IssueAjb $action): RedirectResponse
    {
        abort_unless($request->user()?->can('legal.ajb.issue'), 403);

        $data = $request->validate([
            'notary_name' => 'required|string|max:255',
            'signed_at'   => 'required|date',
        ]);

        $ajb = $action->execute($booking, $data['notary_name'], $data['signed_at']);
        return back()->with('success', "AJB {$ajb->ajb_number} diterbitkan.");
    }
}
