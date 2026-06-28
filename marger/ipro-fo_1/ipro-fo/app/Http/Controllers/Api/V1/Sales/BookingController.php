<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Domain\Sales\Booking\Actions\CancelBooking;
use App\Domain\Sales\Booking\Actions\CreateBooking;
use App\Domain\Sales\Booking\Actions\PromoteToContract;
use App\Domain\Sales\Booking\DTOs\BookingDto;
use App\Domain\Sales\Booking\Models\Booking;
use App\Domain\Sales\Booking\Repositories\BookingRepository;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\Booking\CancelBookingRequest;
use App\Http\Requests\Sales\Booking\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BookingController extends Controller
{
    public function __construct(private readonly BookingRepository $repo) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Booking::class);
        return BookingResource::collection($this->repo->paginate($request->only(['search', 'status', 'project_id', 'customer_id', 'date_from', 'date_to']), (int) $request->integer('per_page', 50)));
    }

    public function store(StoreBookingRequest $request, CreateBooking $action): BookingResource
    {
        return BookingResource::make($action->execute(BookingDto::fromArray($request->validated())));
    }

    public function show(Booking $booking): BookingResource
    {
        $this->authorize('view', $booking);
        return BookingResource::make($booking->load(['customer', 'unit', 'paymentScheme', 'paymentSchedules', 'statusHistories']));
    }

    public function cancel(CancelBookingRequest $request, Booking $booking, CancelBooking $action): BookingResource
    {
        $this->authorize('cancel', $booking);
        $action->execute($booking, $request->string('reason'), (float) $request->input('penalty_amount', 0));
        return BookingResource::make($booking);
    }

    public function promote(Booking $booking, PromoteToContract $action): JsonResponse
    {
        $this->authorize('promote', $booking);
        $contract = $action->execute($booking);
        return response()->json(['data' => ['contract_id' => $contract->id, 'contract_number' => $contract->contract_number]]);
    }
}
