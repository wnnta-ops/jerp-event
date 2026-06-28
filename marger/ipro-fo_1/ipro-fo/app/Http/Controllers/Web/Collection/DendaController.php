<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Collection;

use App\Domain\Collection\Denda\Models\Denda;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DendaController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('collection.denda.view'), 403);

        $rows = Denda::query()
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->latest('computed_date')
            ->paginate(50);

        return Inertia::render('Collection/Denda/Index', [
            'dendas'  => $rows,
            'filters' => $request->only(['status']),
        ]);
    }
}
