<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Tools;

use App\Domain\Sales\Pricing\Models\PaymentScheme;
use App\Domain\Tax\Services\BphtbCalculator;
use App\Domain\Tax\Services\KprCalculator;
use App\Domain\Tax\Services\SkemaCalculator;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CalculatorController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tools/Calculator/Index', [
            'schemes' => PaymentScheme::query()
                ->where('is_active', true)
                ->select(['id', 'code', 'name', 'type', 'down_payment_pct', 'installment_months', 'discount_pct'])
                ->orderBy('name')
                ->get(),
            'default_npotkp'  => BphtbCalculator::DEFAULT_NPOTKP,
            'default_ppn_pct' => SkemaCalculator::DEFAULT_PPN_PCT,
        ]);
    }

    public function bphtb(Request $request, BphtbCalculator $calc): JsonResponse
    {
        $data = $request->validate([
            'land_area'            => ['required', 'numeric', 'min:0'],
            'building_area'        => ['required', 'numeric', 'min:0'],
            'njop_per_m2_land'     => ['required', 'numeric', 'min:0'],
            'njop_per_m2_building' => ['required', 'numeric', 'min:0'],
            'ajb_value'            => ['required', 'numeric', 'min:0'],
            'npotkp'               => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json($calc->calculate($data));
    }

    public function kpr(Request $request, KprCalculator $calc): JsonResponse
    {
        $data = $request->validate([
            'loan'         => ['required', 'numeric', 'min:1'],
            'tenor_months' => ['required', 'integer', 'min:1', 'max:360'],
            'annual_rate'  => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        return response()->json($calc->calculate($data));
    }

    public function skema(Request $request, SkemaCalculator $calc): JsonResponse
    {
        $data = $request->validate([
            'payment_scheme_id'   => ['required', 'integer', 'exists:payment_schemes,id'],
            'price_list'          => ['required', 'numeric', 'min:0'],
            'discount_pct'        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'additional_discount' => ['nullable', 'numeric', 'min:0'],
            'ppn_pct'             => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $scheme = PaymentScheme::findOrFail($data['payment_scheme_id']);

        return response()->json($calc->calculate($scheme, $data));
    }
}
