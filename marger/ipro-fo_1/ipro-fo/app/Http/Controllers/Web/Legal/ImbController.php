<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Legal;

use App\Domain\Legal\Imb\Models\ImbDocument;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ImbController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('legal.imb.view'), 403);

        $rows = ImbDocument::query()->latest('issued_at')->paginate(50);
        return Inertia::render('Legal/Imb/Index', ['rows' => $rows]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('legal.imb.manage'), 403);

        $data = $request->validate([
            'unit_id'    => 'required|integer|exists:units,id',
            'imb_number' => 'required|string|max:64',
            'issued_at'  => 'required|date',
        ]);

        ImbDocument::create($data);
        return back()->with('success', 'IMB tersimpan.');
    }
}
