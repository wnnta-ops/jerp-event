<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\DataCenter;

use App\Domain\BusinessSupport\Models\BsEmployee;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * DATA CENTER — hub gabungan Supplier / Employee Center.
 * Customer Center DIHILANGKAN: data customer dikelola lewat sub-modul Customer di
 * Marketing & Sales, tidak dikelola dua kali di sini.
 */
class DataCenterController extends Controller
{
    public function hub(Request $request): Response
    {
        abort_unless($request->user()?->can('datacenter.hub.view'), 403);

        return Inertia::render('BusinessSupport/DataCenter/Hub', [
            'summary' => [
                'suppliers'        => BsSupplier::count(),
                'suppliers_active' => BsSupplier::where('is_active', true)->count(),
                'employees'        => BsEmployee::count(),
                'employees_active' => BsEmployee::where('is_active', true)->count(),
            ],
        ]);
    }

    /** Landing per sub-modul/center (supplier | employee). */
    public function center(Request $request, string $center): Response
    {
        abort_unless($request->user()?->can('datacenter.hub.view'), 403);
        $titles = ['supplier' => 'Supplier Center', 'employee' => 'Employee Center'];
        abort_unless(isset($titles[$center]), 404);

        return Inertia::render('BusinessSupport/DataCenter/Center', [
            'center' => $center,
            'title'  => $titles[$center],
        ]);
    }

    /** Print-Out per center (supplier | employee). */
    public function printout(Request $request, string $center): Response
    {
        abort_unless($request->user()?->can('datacenter.employee.print'), 403);
        $titles = ['supplier' => 'Supplier Center', 'employee' => 'Employee Center'];
        abort_unless(isset($titles[$center]), 404);

        return Inertia::render('BusinessSupport/DataCenter/PrintOut', [
            'center' => $center,
            'title'  => $titles[$center],
        ]);
    }
}
