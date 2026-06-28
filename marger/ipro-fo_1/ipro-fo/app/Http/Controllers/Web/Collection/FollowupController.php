<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Collection;

use App\Domain\Collection\Followup\Actions\CreateFollowup;
use App\Domain\Collection\Followup\Models\Followup;
use App\Domain\Master\Customer\Models\Customer;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FollowupController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('collection.followup.view'), 403);

        $rows = Followup::query()
            ->with(['customer:id,code,full_name,phone'])
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->latest('contacted_at')
            ->paginate(50);

        return Inertia::render('Collection/Followup/Index', [
            'followups' => $rows,
            'filters'   => $request->only(['status']),
        ]);
    }

    public function store(Request $request, CreateFollowup $action): RedirectResponse
    {
        abort_unless($request->user()?->can('collection.followup.create'), 403);

        $data = $request->validate([
            'customer_id'    => 'required|exists:customers,id',
            'channel'        => 'required|in:call,visit,email,wa,sms',
            'contacted_at'   => 'required|date',
            'result'         => 'required|string',
            'notes'          => 'required|string|max:1000',
            'next_action_at' => 'nullable|date|after:now',
        ]);

        $action->execute($data);

        return back()->with('success', 'Follow-up tercatat.');
    }
}
