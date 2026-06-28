<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Legal;

use App\Domain\Legal\Bast\Actions\IssueBast;
use App\Domain\Legal\Bast\Models\BastDocument;
use App\Domain\Sales\Booking\Models\Booking;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BastController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('legal.bast.view'), 403);

        $rows = BastDocument::query()
            ->with(['booking:id,booking_number,customer_id'])
            ->latest('handed_over_at')
            ->paginate(50);

        return Inertia::render('Legal/Bast/Index', ['rows' => $rows]);
    }

    public function issue(Request $request, Booking $booking, IssueBast $action): RedirectResponse
    {
        abort_unless($request->user()?->can('legal.bast.issue'), 403);

        $data = $request->validate([
            'handed_over_at' => 'required|date',
            'checklist'      => 'nullable|array',
        ]);

        $bast = $action->execute($booking, $data['handed_over_at'], $data['checklist'] ?? []);
        return back()->with('success', "BAST {$bast->bast_number} diterbitkan.");
    }
}
