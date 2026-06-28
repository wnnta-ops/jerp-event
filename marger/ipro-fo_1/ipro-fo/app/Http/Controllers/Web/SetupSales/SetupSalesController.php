<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\SetupSales;

use App\Domain\Master\Customer\Models\Agent;
use App\Domain\Master\Sales\Models\SalesPerson;
use App\Domain\Master\Unit\Models\Pricelist;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Master\Unit\Models\UnitType;
use App\Domain\Sales\Pricing\Models\PaymentScheme;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Modul SETUP SALES (mirror eProperty "adminjual") — hub + dashboard + reminder + log.
 */
class SetupSalesController extends Controller
{
    /** Auditable types yang relevan dengan Setup Sales (untuk Log File). */
    private const SALES_TYPES = [
        PaymentScheme::class, SalesPerson::class, Agent::class, Pricelist::class, Unit::class,
    ];

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('setupsales.view'), 403);

        $byStatus = Unit::query()->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status');

        return Inertia::render('SetupSales/Index', [
            'stats' => [
                'schemes'   => PaymentScheme::where('is_active', true)->count(),
                'sales'     => SalesPerson::where('is_active', true)->count(),
                'agents'    => Agent::where('is_active', true)->count(),
                'pricelists'=> Pricelist::where('is_active', true)->count(),
                'units'     => (int) $byStatus->sum(),
                'available' => (int) ($byStatus['available'] ?? 0),
                'sold'      => (int) (($byStatus['sold'] ?? 0) + ($byStatus['booked'] ?? 0)),
            ],
        ]);
    }

    /** Dashboard — ringkasan stok per tipe + komposisi skema. */
    public function dashboard(Request $request): Response
    {
        abort_unless($request->user()?->can('setupsales.view'), 403);

        $types = UnitType::query()->orderBy('name')->get(['id', 'code', 'name']);
        $units = Unit::query()->select('unit_type_id', 'status', DB::raw('count(*) as c'), DB::raw('sum(current_price) as v'))
            ->groupBy('unit_type_id', 'status')->get();

        $perType = $types->map(function ($t) use ($units) {
            $rows = $units->where('unit_type_id', $t->id);

            return [
                'code' => $t->code, 'name' => $t->name,
                'total'     => (int) $rows->sum('c'),
                'available' => (int) $rows->where('status', 'available')->sum('c'),
                'booked'    => (int) $rows->where('status', 'booked')->sum('c'),
                'sold'      => (int) $rows->where('status', 'sold')->sum('c'),
                'value'     => round((float) $rows->sum('v'), 2),
            ];
        })->values();

        // Unit tanpa tipe.
        $noType = $units->whereNull('unit_type_id');
        if ($noType->isNotEmpty()) {
            $perType->push([
                'code' => '-', 'name' => '(Tanpa Tipe)',
                'total' => (int) $noType->sum('c'),
                'available' => (int) $noType->where('status', 'available')->sum('c'),
                'booked' => (int) $noType->where('status', 'booked')->sum('c'),
                'sold' => (int) $noType->where('status', 'sold')->sum('c'),
                'value' => round((float) $noType->sum('v'), 2),
            ]);
        }

        $schemeMix = PaymentScheme::query()->select('type', DB::raw('count(*) as c'))->groupBy('type')->pluck('c', 'type');

        return Inertia::render('SetupSales/Dashboard', [
            'perType'    => $perType,
            'schemeMix'  => collect(PaymentScheme::TYPES)->map(fn ($label, $key) => [
                'type' => $key, 'label' => $label, 'count' => (int) ($schemeMix[$key] ?? 0),
            ])->values(),
            'totals' => [
                'units'  => Unit::count(),
                'value'  => round((float) Unit::sum('current_price'), 2),
                'schemes'=> PaymentScheme::count(),
            ],
        ]);
    }

    /** Reminder — hal yang perlu tindak lanjut di setup penjualan. */
    public function reminder(Request $request): Response
    {
        abort_unless($request->user()?->can('setupsales.view'), 403);

        $soon = now()->addDays(30)->toDateString();
        $expiring = Pricelist::where('is_active', true)->whereNotNull('effective_to')
            ->whereDate('effective_to', '<=', $soon)->orderBy('effective_to')
            ->get(['id', 'name', 'effective_to'])
            ->map(fn ($p) => ['type' => 'pricelist', 'title' => "Price List \"{$p->name}\" berakhir", 'date' => $p->effective_to?->toDateString(), 'severity' => 'soon', 'ref' => $p->id]);

        $unpriced = Unit::where(fn ($q) => $q->whereNull('current_price')->orWhere('current_price', '<=', 0))
            ->orderBy('unit_code')->limit(50)->get(['id', 'unit_code'])
            ->map(fn ($u) => ['type' => 'unit', 'title' => "Unit {$u->unit_code} belum punya harga", 'date' => null, 'severity' => 'overdue', 'ref' => $u->id]);

        $noTerms = PaymentScheme::where('is_active', true)->whereDoesntHave('terms')
            ->orderBy('name')->get(['id', 'code', 'name'])
            ->map(fn ($s) => ['type' => 'scheme', 'title' => "Skema {$s->code} belum punya termin", 'date' => null, 'severity' => 'soon', 'ref' => $s->id]);

        $items = $expiring->concat($unpriced)->concat($noTerms)->values();

        return Inertia::render('SetupSales/Reminder', [
            'items'   => $items,
            'summary' => ['pricelist' => $expiring->count(), 'unit' => $unpriced->count(), 'scheme' => $noTerms->count()],
        ]);
    }

    /** Log File — audit trail entitas Setup Sales. */
    public function log(Request $request): Response
    {
        abort_unless($request->user()?->can('setupsales.view'), 403);

        $rows = DB::table('audit_logs')
            ->whereIn('auditable_type', self::SALES_TYPES)
            ->when($request->input('event'), fn ($q, $v) => $q->where('event', $v))
            ->when($request->input('search'), fn ($q, $s) => $q->where('auditable_type', 'like', "%{$s}%"))
            ->orderByDesc('id')->paginate(60)->withQueryString();

        $userMap = User::whereIn('id', collect($rows->items())->pluck('user_id')->filter()->unique())->pluck('name', 'id');
        $rows->getCollection()->transform(fn ($r) => (array) $r + [
            'user_name'  => $userMap[$r->user_id] ?? null,
            'short_type' => class_basename($r->auditable_type),
        ]);

        return Inertia::render('SetupSales/Log', [
            'logs'    => $rows,
            'filters' => $request->only(['event', 'search']),
            'events'  => DB::table('audit_logs')->whereIn('auditable_type', self::SALES_TYPES)->select('event')->distinct()->orderBy('event')->pluck('event'),
        ]);
    }
}
