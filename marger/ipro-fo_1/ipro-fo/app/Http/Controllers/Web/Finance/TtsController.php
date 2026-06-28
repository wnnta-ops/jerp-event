<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Finance;

use App\Domain\Finance\Tts\Actions\ClearGiro;
use App\Domain\Finance\Tts\Actions\CreateTts;
use App\Domain\Finance\Tts\Actions\PostTts;
use App\Domain\Finance\Tts\Actions\RejectGiro;
use App\Domain\Finance\Tts\DTOs\TtsDto;
use App\Domain\Finance\Tts\Enums\PaymentMethod;
use App\Domain\Finance\Tts\Enums\TtsStatus;
use App\Domain\Finance\Tts\Models\Tts;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\Tts\StoreTtsRequest;
use App\Http\Resources\TtsResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TtsController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('finance.tts.view'), 403);

        $query = Tts::query()
            ->with(['customer:id,code,full_name', 'booking:id,booking_number'])
            ->when($request->input('status'),
                fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('payment_method'),
                fn ($q, $v) => $q->where('payment_method', $v))
            ->when($request->input('search'),
                fn ($q, $term) => $q->where('tts_number', 'like', "%{$term}%"))
            ->latest('received_date');

        return Inertia::render('Finance/Tts/Index', [
            'tts'        => TtsResource::collection($query->paginate(50)->withQueryString()),
            'filters'    => $request->only(['status', 'payment_method', 'search']),
            'statuses'   => collect(TtsStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label(), 'color' => $s->color()]),
            'methods'    => collect(PaymentMethod::cases())->map(fn ($m) => ['value' => $m->value, 'label' => $m->label()]),
        ]);
    }

    public function create(): Response
    {
        abort_unless(auth()->user()?->can('finance.tts.create'), 403);

        return Inertia::render('Finance/Tts/Create', [
            'methods'   => collect(PaymentMethod::cases())->map(fn ($m) => ['value' => $m->value, 'label' => $m->label()]),
            'bank_accounts' => \DB::table('bank_accounts')->where('is_active', true)->get(['id', 'account_name', 'account_number', 'bank_name']),
        ]);
    }

    public function store(StoreTtsRequest $request, CreateTts $action): RedirectResponse
    {
        $tts = $action->execute(TtsDto::fromArray($request->validated()));

        return redirect()->route('tts.show', $tts)
            ->with('success', "TTS {$tts->tts_number} berhasil dibuat.");
    }

    public function show(Tts $tt): Response
    {
        abort_unless(auth()->user()?->can('finance.tts.view'), 403);

        return Inertia::render('Finance/Tts/Show', [
            'tts' => TtsResource::make($tt->load(['customer', 'booking.paymentSchedules', 'allocations', 'giro'])),
        ]);
    }

    public function post(Tts $tt, PostTts $action): RedirectResponse
    {
        abort_unless(auth()->user()?->can('finance.tts.post'), 403);

        $action->execute($tt);
        return back()->with('success', "TTS {$tt->tts_number} berhasil di-posting.");
    }

    public function rejectGiro(Request $request, Tts $tt, RejectGiro $action): RedirectResponse
    {
        abort_unless(auth()->user()?->can('finance.tts.giro.reject'), 403);

        $data = $request->validate(['reason' => 'required|string|min:5']);
        $action->execute($tt, $data['reason']);
        return back()->with('success', "Giro {$tt->tts_number} di-reject.");
    }

    public function clearGiro(Tts $tt, ClearGiro $action): RedirectResponse
    {
        abort_unless(auth()->user()?->can('finance.tts.giro.manage'), 403);

        $action->execute($tt);
        return back()->with('success', "Giro {$tt->tts_number} berhasil cair.");
    }
}
