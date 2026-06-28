<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Finance;

use App\Domain\Finance\Kuitansi\Models\Kuitansi;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KuitansiController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('finance.kuitansi.view'), 403);

        $rows = Kuitansi::query()
            ->with(['items', 'ttsReceipt:id,tts_number'])
            ->when($request->input('search'), fn ($q, $term) => $q->where('kuitansi_number', 'like', "%{$term}%"))
            ->latest('issued_date')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Finance/Kuitansi/Index', [
            'kuitansi' => $rows,
            'filters'  => $request->only(['search']),
        ]);
    }

    public function show(Kuitansi $kuitansi): Response
    {
        abort_unless(auth()->user()?->can('finance.kuitansi.view'), 403);

        return Inertia::render('Finance/Kuitansi/Show', [
            'kuitansi' => $kuitansi->load(['items', 'ttsReceipt']),
        ]);
    }
}
