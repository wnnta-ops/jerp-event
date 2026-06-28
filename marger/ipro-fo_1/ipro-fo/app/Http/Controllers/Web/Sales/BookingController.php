<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Sales;

use App\Domain\Foundation\Tenant\Models\Project;
use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Sales\Booking\Actions\CancelBooking;
use App\Domain\Sales\Booking\Actions\CreateBooking;
use App\Domain\Sales\Booking\Actions\PromoteToContract;
use App\Domain\Sales\Booking\DTOs\BookingDto;
use App\Domain\Sales\Booking\Enums\BookingStatus;
use App\Domain\Sales\Booking\Models\Booking;
use App\Domain\Sales\Booking\Repositories\BookingRepository;
use App\Domain\Sales\Booking\Services\PricingCalculator;
use App\Domain\Sales\Pricing\Models\Gimmick;
use App\Domain\Sales\Pricing\Models\PaymentScheme;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\Booking\CancelBookingRequest;
use App\Http\Requests\Sales\Booking\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    public function __construct(private readonly BookingRepository $repo) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Booking::class);

        $filters   = $request->only(['search', 'status', 'project_id', 'customer_id', 'date_from', 'date_to']);
        $paginator = $this->repo->paginate($filters, (int) $request->integer('per_page', 50));

        return Inertia::render('Sales/Booking/Index', [
            'bookings' => BookingResource::collection($paginator),
            'filters'  => $filters,
            'statuses' => collect(BookingStatus::cases())->map(
                fn ($s) => ['value' => $s->value, 'label' => $s->label(), 'color' => $s->color()]
            ),
            'projects' => Project::select('id', 'code', 'name')->get(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Booking::class);

        $projectId = (int) $request->query('project_id', Project::value('id'));

        return Inertia::render('Sales/Booking/Wizard', [
            'project_id'      => $projectId,
            'projects'        => Project::select('id', 'code', 'name')->get(),
            'units_available' => Unit::query()
                ->where('project_id', $projectId)
                ->whereIn('status', ['available', 'hold'])
                ->with(['cluster:id,code,name', 'unitType:id,code,name,lt,lb'])
                ->get(),
            'payment_schemes' => PaymentScheme::query()
                ->where(function ($q) use ($projectId): void {
                    $q->where('project_id', $projectId)->orWhereNull('project_id');
                })
                ->where('is_active', true)
                ->with('terms')
                ->get(),
            'gimmicks'        => Gimmick::query()
                ->where('is_active', true)
                ->where(function ($q): void {
                    $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now());
                })
                ->get(),
            'ppn_pct'         => config('domain.tax.ppn_default_pct'),
        ]);
    }

    public function preview(Request $request, PricingCalculator $calc): JsonResponse
    {
        $data = $request->validate([
            'unit_id'         => ['required', 'integer', 'exists:units,id'],
            'discount_pct'    => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'gimmick_ids'     => ['nullable', 'array'],
            'gimmick_ids.*'   => ['integer'],
        ]);

        $unit         = Unit::findOrFail($data['unit_id']);
        $gimmickValue = (float) Gimmick::whereIn('id', $data['gimmick_ids'] ?? [])->sum('value');

        $pricing = $calc->calculate(
            basePrice:      (float) $unit->current_price,
            discountPct:    (float) ($data['discount_pct'] ?? 0),
            discountAmount: (float) ($data['discount_amount'] ?? 0),
            gimmickValue:   $gimmickValue,
            ppnPct:         (float) config('domain.tax.ppn_default_pct'),
        );

        return response()->json(['data' => $pricing]);
    }

    public function store(StoreBookingRequest $request, CreateBooking $action): RedirectResponse
    {
        $booking = $action->execute(BookingDto::fromArray($request->validated()));

        return redirect()->route('bookings.show', $booking)
            ->with('success', "Booking {$booking->booking_number} berhasil dibuat.");
    }

    public function show(Booking $booking): Response
    {
        $this->authorize('view', $booking);

        $booking->load([
            'customer', 'unit', 'paymentScheme',
            'paymentSchedules.allocations.source',
            'statusHistories',
            'dpMemos.createdBy',
        ]);

        // Prev / Next navigation
        $prev = Booking::query()->where('id', '<', $booking->id)->orderByDesc('id')->select(['id', 'booking_number'])->first();
        $next = Booking::query()->where('id', '>', $booking->id)->orderBy('id')->select(['id', 'booking_number'])->first();

        return Inertia::render('Sales/Booking/Show', [
            'booking' => BookingResource::make($booking),
            'summary' => [
                'total_tagihan'    => $booking->total_tagihan,
                'total_terbayar'   => $booking->total_terbayar,
                'total_pembayaran' => $booking->total_pembayaran,
                'total_diskon'     => $booking->total_diskon,
                'sisa_tagihan'     => $booking->sisa_tagihan,
                'persen_lunas'     => $booking->persen_lunas,
            ],
            'meta' => [
                'created_at' => $booking->created_at?->toIso8601String(),
                'updated_at' => $booking->updated_at?->toIso8601String(),
                'prev_id'    => $prev?->id,
                'prev_label' => $prev?->booking_number,
                'next_id'    => $next?->id,
                'next_label' => $next?->booking_number,
            ],
            'dp_memos' => $booking->dpMemos->map(fn ($m) => [
                'id'           => $m->id,
                'memo_number'  => $m->memo_number,
                'type'         => $m->type->value,
                'type_label'   => $m->type->label(),
                'status'       => $m->status->value,
                'status_label' => $m->status->label(),
                'status_color' => $m->status->color(),
                'amount'       => (float) $m->amount,
                'reason'       => $m->reason,
                'memo_date'    => $m->memo_date?->format('Y-m-d'),
                'created_by'   => $m->createdBy?->name,
                'can_cancel'   => $m->canBeCancelled(),
            ]),
        ]);
    }

    public function cancel(CancelBookingRequest $request, Booking $booking, CancelBooking $action): RedirectResponse
    {
        $this->authorize('cancel', $booking);

        $action->execute($booking, $request->string('reason'), (float) $request->input('penalty_amount', 0));

        return redirect()->route('bookings.show', $booking)
            ->with('success', "Booking {$booking->booking_number} dibatalkan.");
    }

    public function promote(Booking $booking, PromoteToContract $action): RedirectResponse
    {
        $this->authorize('promote', $booking);

        $contract = $action->execute($booking);

        return redirect()->route('bookings.show', $booking)
            ->with('success', "Booking dipromosikan ke kontrak {$contract->contract_number}.");
    }
}
