<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landing per sub-modul (grup BO) untuk modul GL/Cash Book/Budget/COGS/Contractor.
 * Sidebar menampilkan nama grup BO sebagai sub-modul; klik → halaman Section modul ini
 * yang merender detail item grup tsb (item & route didefinisikan di tiap <Module>/Section.vue).
 */
class SubModuleSectionController extends Controller
{
    /** module key => komponen Inertia Section. */
    private const COMPONENTS = [
        'gl'         => 'BusinessSupport/Gl/Section',
        'cashbook'   => 'BusinessSupport/CashBook/Section',
        'budget'     => 'BusinessSupport/Budget/Section',
        'contractor' => 'BusinessSupport/Contractor/Section',
    ];

    public function show(Request $request, string $module, string $section): Response
    {
        abort_unless($request->user()?->canBackoffice(), 403);
        abort_unless(isset(self::COMPONENTS[$module]), 404);

        return Inertia::render(self::COMPONENTS[$module], [
            'section' => $section,
        ]);
    }
}
