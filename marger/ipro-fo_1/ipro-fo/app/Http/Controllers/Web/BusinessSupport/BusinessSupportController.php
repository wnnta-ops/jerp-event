<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Domain\BusinessSupport\Support\ModuleMap;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BusinessSupportController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canBackoffice(), 403);

        return Inertia::render('BusinessSupport/Index', [
            'modules' => collect(ModuleMap::all())->map(fn ($m, $key) => [
                'key'      => $key,
                'label'    => $m['label'],
                'icon'     => $m['icon'],
                'desc'     => $m['desc'],
                'sections' => array_map(fn ($items) => count($items), $m['sections']),
            ])->values(),
            'foundation' => [
                'accounts'     => BsAccount::count(),
                'cost_centers' => BsCostCenter::count(),
                'suppliers'    => BsSupplier::count(),
            ],
        ]);
    }

    public function module(Request $request, string $key): Response
    {
        abort_unless($request->user()?->canBackoffice(), 403);

        $module = ModuleMap::get($key);
        abort_if($module === null, 404);

        return Inertia::render('BusinessSupport/Module', [
            'moduleKey' => $key,
            'module'    => $module,
        ]);
    }
}
