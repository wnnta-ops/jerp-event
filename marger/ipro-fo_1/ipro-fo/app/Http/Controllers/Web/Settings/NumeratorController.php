<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Settings;

use App\Domain\Foundation\Numerator\Models\Numerator;
use App\Domain\Foundation\Numerator\Services\NumeratorService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NumeratorController extends Controller
{
    public function __construct(private readonly NumeratorService $service) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('system.setup.numerator'), 403);

        return Inertia::render('Settings/Numerators/Index', [
            'numerators' => Numerator::orderBy('code')->get(),
        ]);
    }

    public function preview(Request $request, string $code): JsonResponse
    {
        return response()->json([
            'preview' => $this->service->preview($code, $request->only(['PROJECT', 'COMPANY'])),
        ]);
    }

    public function update(Request $request, Numerator $numerator): RedirectResponse
    {
        abort_unless($request->user()?->can('system.setup.numerator'), 403);

        $data = $request->validate([
            'prefix'          => 'required|string|max:32',
            'format'          => 'required|string|max:128',
            'seq_padding'     => 'required|integer|min:1|max:10',
            'reset_strategy'  => 'required|in:never,yearly,monthly,daily',
            'current_seq'     => 'nullable|integer|min:0',
        ]);
        $numerator->update($data);

        return back()->with('success', "Numerator {$numerator->code} diperbarui.");
    }
}
