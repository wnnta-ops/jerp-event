<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Commission;

use App\Domain\Commission\Models\Incentive;
use App\Domain\Commission\Models\IncentiveScheme;
use App\Domain\Commission\Services\IncentiveService;
use App\Domain\Master\Customer\Models\Agent;
use App\Domain\Master\Sales\Models\SalesPerson;
use App\Domain\Master\Unit\Models\UnitType;
use App\Domain\Sales\Booking\Models\Booking;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Insentif BLT & Reward (eProperty KOMISI → BLT/Reward): Setup, Pengajuan, Realisasi.
 */
class IncentiveController extends Controller
{
    public function __construct(private readonly IncentiveService $service) {}

    public function index(Request $request, string $type): Response
    {
        abort_unless($request->user()?->can('incentive.view'), 403);
        abort_unless(isset(Incentive::TYPES[$type]), 404);

        $base = Incentive::query()->where('type', $type)
            ->with('salesPerson:id,name', 'agent:id,name', 'booking:id,booking_number', 'scheme:id,name');
        $rows = (clone $base)
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->latest('id')->paginate(25)->withQueryString();
        $rows->getCollection()->transform(fn ($i) => [
            'id' => $i->id, 'incentive_no' => $i->incentive_no, 'status' => $i->status,
            'beneficiary_type' => $i->beneficiary_type, 'beneficiary' => $i->beneficiary_name,
            'booking' => $i->booking?->booking_number, 'scheme' => $i->scheme?->name,
            'description' => $i->description, 'amount' => (float) $i->amount,
            'reject_reason' => $i->reject_reason, 'submitted_at' => $i->submitted_at?->toDateString(),
        ]);

        // Query ringan untuk statistik — tanpa eager-load relasi (hindari strict-mode
        // saat kolom FK tidak diselect).
        $all = Incentive::query()->where('type', $type)->get(['id', 'status', 'amount']);

        return Inertia::render('Komisi/Incentive/Index', [
            'type'      => $type,
            'typeLabel' => Incentive::TYPES[$type],
            'rows'      => $rows,
            'filters'   => $request->only('status'),
            'statuses'  => Incentive::STATUSES,
            'stats'     => [
                'diajukan'    => $all->where('status', 'diajukan')->count(),
                'disetujui'   => $all->where('status', 'disetujui')->count(),
                'direalisasi' => $all->where('status', 'direalisasi')->count(),
                'nilai_realisasi' => round((float) $all->where('status', 'direalisasi')->sum('amount'), 2),
            ],
            'schemes'   => IncentiveScheme::where('type', $type)->orderBy('code')->get(['id', 'code', 'name', 'basis', 'value', 'is_active']),
            'masters'   => [
                'sales'     => SalesPerson::where('is_active', true)->orderBy('name')->get(['id', 'name']),
                'agents'    => Agent::where('is_active', true)->orderBy('name')->get(['id', 'name']),
                'bookings'  => Booking::query()->latest('id')->limit(500)->get(['id', 'booking_number'])->map(fn ($b) => ['id' => $b->id, 'label' => $b->booking_number]),
                'unitTypes' => UnitType::orderBy('name')->get(['id', 'name']),
            ],
        ]);
    }

    public function store(Request $request, string $type): RedirectResponse
    {
        abort_unless($request->user()?->can('incentive.manage'), 403);
        abort_unless(isset(Incentive::TYPES[$type]), 404);
        $v = $request->validate([
            'beneficiary_type' => ['required', 'in:sales,agent'],
            'sales_person_id'  => ['nullable', 'integer', 'exists:sales_persons,id', 'required_if:beneficiary_type,sales'],
            'agent_id'         => ['nullable', 'integer', 'exists:agents,id', 'required_if:beneficiary_type,agent'],
            'booking_id'       => ['nullable', 'integer', 'exists:bookings,id'],
            'scheme_id'        => ['nullable', 'integer', 'exists:incentive_schemes,id'],
            'description'      => ['nullable', 'string', 'max:200'],
            'amount'           => ['required', 'numeric', 'min:1'],
        ]);
        $this->service->submit([...$v, 'type' => $type], $request->user()->id);

        return back()->with('success', 'Pengajuan insentif dibuat.');
    }

    public function approve(Request $request, Incentive $incentive): RedirectResponse
    {
        abort_unless($request->user()?->can('incentive.approve'), 403);
        $this->service->approve($incentive, $request->user()->id);

        return back()->with('success', 'Insentif disetujui.');
    }

    public function reject(Request $request, Incentive $incentive): RedirectResponse
    {
        abort_unless($request->user()?->can('incentive.approve'), 403);
        $v = $request->validate(['reject_reason' => ['required', 'string', 'max:255']]);
        $this->service->reject($incentive, $v['reject_reason'], $request->user()->id);

        return back()->with('success', 'Insentif ditolak.');
    }

    public function realize(Request $request, Incentive $incentive): RedirectResponse
    {
        abort_unless($request->user()?->can('incentive.approve'), 403);
        $this->service->realize($incentive, $request->user()->id);

        return back()->with('success', 'Insentif direalisasi (dibayarkan).');
    }

    // ── Setup Skema ──
    public function schemeStore(Request $request, string $type): RedirectResponse
    {
        abort_unless($request->user()?->can('incentive.manage'), 403);
        abort_unless(isset(Incentive::TYPES[$type]), 404);
        $v = $this->validateScheme($request);
        $dup = IncentiveScheme::where('type', $type)->where('code', $v['code'])->exists();
        abort_if($dup, 422, "Kode skema \"{$v['code']}\" sudah dipakai pada {$type}.");
        IncentiveScheme::create([...$v, 'type' => $type]);

        return back()->with('success', 'Skema insentif ditambahkan.');
    }

    public function schemeUpdate(Request $request, IncentiveScheme $scheme): RedirectResponse
    {
        abort_unless($request->user()?->can('incentive.manage'), 403);
        $scheme->update($this->validateScheme($request));

        return back()->with('success', 'Skema diperbarui.');
    }

    public function schemeDestroy(Request $request, IncentiveScheme $scheme): RedirectResponse
    {
        abort_unless($request->user()?->can('incentive.manage'), 403);
        $scheme->delete();

        return back()->with('success', 'Skema dihapus.');
    }

    /** @return array<string,mixed> */
    private function validateScheme(Request $request): array
    {
        return $request->validate([
            'code'         => ['required', 'string', 'max:32'],
            'name'         => ['required', 'string', 'max:150'],
            'basis'        => ['required', 'in:amount,percent'],
            'value'        => ['required', 'numeric', 'min:0'],
            'unit_type_id' => ['nullable', 'integer', 'exists:unit_types,id'],
            'is_active'    => ['boolean'],
            'notes'        => ['nullable', 'string', 'max:255'],
        ]);
    }
}
