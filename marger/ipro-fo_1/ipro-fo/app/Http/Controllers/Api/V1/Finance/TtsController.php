<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Finance;

use App\Domain\Finance\Tts\Actions\ClearGiro;
use App\Domain\Finance\Tts\Actions\CreateTts;
use App\Domain\Finance\Tts\Actions\PostTts;
use App\Domain\Finance\Tts\Actions\RejectGiro;
use App\Domain\Finance\Tts\DTOs\TtsDto;
use App\Domain\Finance\Tts\Models\Tts;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\Tts\StoreTtsRequest;
use App\Http\Resources\TtsResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TtsController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->can('finance.tts.view'), 403);

        $query = Tts::query()
            ->with(['customer:id,code,full_name', 'booking:id,booking_number'])
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('payment_method'), fn ($q, $v) => $q->where('payment_method', $v))
            ->latest('received_date');

        return TtsResource::collection($query->paginate((int) $request->integer('per_page', 50))->withQueryString());
    }

    public function store(StoreTtsRequest $request, CreateTts $action): TtsResource
    {
        return TtsResource::make($action->execute(TtsDto::fromArray($request->validated())));
    }

    public function show(Tts $tts): TtsResource
    {
        abort_unless(auth()->user()?->can('finance.tts.view'), 403);
        return TtsResource::make($tts->load(['customer', 'booking', 'allocations', 'giro']));
    }

    public function post(Tts $tts, PostTts $action): TtsResource
    {
        abort_unless(auth()->user()?->can('finance.tts.post'), 403);
        return TtsResource::make($action->execute($tts));
    }

    public function rejectGiro(Request $request, Tts $tts, RejectGiro $action): TtsResource
    {
        abort_unless(auth()->user()?->can('finance.tts.giro.reject'), 403);
        $data = $request->validate(['reason' => 'required|string|min:5']);
        return TtsResource::make($action->execute($tts, $data['reason']));
    }

    public function clearGiro(Tts $tts, ClearGiro $action): TtsResource
    {
        abort_unless(auth()->user()?->can('finance.tts.giro.manage'), 403);
        return TtsResource::make($action->execute($tts));
    }
}
