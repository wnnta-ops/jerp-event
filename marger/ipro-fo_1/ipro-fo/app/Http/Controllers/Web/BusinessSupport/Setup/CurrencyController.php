<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Setup;

use App\Domain\BusinessSupport\Models\BsCurrency;
use App\Domain\BusinessSupport\Models\BsExchangeRate;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GL Setup → Multi Currency (master mata uang) + Kurs Sistem (nilai tukar per tanggal).
 */
class CurrencyController extends Controller
{
    // ── Multi Currency ────────────────────────────────────────────────────
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('gl.currency.view'), 403);

        return Inertia::render('BusinessSupport/Setup/Currency', [
            'currencies' => BsCurrency::orderByDesc('is_base')->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        BsCurrency::create($this->currencyData($request));

        return back()->with('success', 'Mata uang ditambahkan.');
    }

    public function update(Request $request, BsCurrency $currency): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $currency->update($this->currencyData($request, $currency->id));

        return back()->with('success', 'Mata uang diperbarui.');
    }

    public function destroy(Request $request, BsCurrency $currency): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $currency->delete();

        return back()->with('success', 'Mata uang dihapus.');
    }

    // ── Kurs Sistem ───────────────────────────────────────────────────────
    public function kurs(Request $request): Response
    {
        abort_unless($request->user()?->can('gl.kurs.view'), 403);

        $rates = BsExchangeRate::query()
            ->when($request->input('currency_code'), fn ($q, $c) => $q->where('currency_code', $c))
            ->orderByDesc('rate_date')->orderBy('currency_code')
            ->paginate(25)->withQueryString();

        return Inertia::render('BusinessSupport/Setup/Kurs', [
            'rates'      => $rates,
            'currencies' => BsCurrency::where('is_active', true)->orderBy('code')->get(['code', 'name']),
            'filters'    => $request->only(['currency_code']),
        ]);
    }

    public function storeKurs(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        BsExchangeRate::create($this->kursData($request));

        return back()->with('success', 'Kurs ditambahkan.');
    }

    public function updateKurs(Request $request, BsExchangeRate $rate): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $rate->update($this->kursData($request));

        return back()->with('success', 'Kurs diperbarui.');
    }

    public function destroyKurs(Request $request, BsExchangeRate $rate): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $rate->delete();

        return back()->with('success', 'Kurs dihapus.');
    }

    /** @return array<string,mixed> */
    private function currencyData(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'code'      => ['required', 'string', 'max:8', Rule::unique('bs_currencies', 'code')->ignore($id)->whereNull('deleted_at')],
            'name'      => ['required', 'string', 'max:80'],
            'symbol'    => ['nullable', 'string', 'max:8'],
            'is_base'   => ['boolean'],
            'is_active' => ['boolean'],
        ]);
        $data['code'] = strtoupper($data['code']);

        return $data;
    }

    /** @return array<string,mixed> */
    private function kursData(Request $request): array
    {
        $data = $request->validate([
            'currency_code' => ['required', 'string', 'max:8'],
            'rate_date'     => ['required', 'date'],
            'rate'          => ['required', 'numeric', 'min:0'],
            'note'          => ['nullable', 'string', 'max:255'],
        ]);
        $data['currency_code'] = strtoupper($data['currency_code']);

        return $data;
    }
}
