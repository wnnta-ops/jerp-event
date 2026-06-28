<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Legal;

use App\Domain\Legal\Sertifikat\Models\SertifikatDocument;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SertifikatController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('legal.sertifikat.view'), 403);

        $rows = SertifikatDocument::query()->latest('issued_at')->paginate(50);
        return Inertia::render('Legal/Sertifikat/Index', ['rows' => $rows]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('legal.sertifikat.manage'), 403);

        $data = $request->validate([
            'unit_id'            => 'required|integer|exists:units,id',
            'owner_customer_id'  => 'nullable|integer|exists:customers,id',
            'type'               => 'required|in:SHM,HGB,HGU,HPL',
            'serial_number'      => 'required|string|max:64',
            'issued_at'          => 'required|date',
        ]);

        SertifikatDocument::create($data);
        return back()->with('success', 'Sertifikat tersimpan.');
    }
}
