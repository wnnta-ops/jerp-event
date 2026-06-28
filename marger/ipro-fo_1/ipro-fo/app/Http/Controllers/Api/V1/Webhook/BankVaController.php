<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessVaPayment;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankVaController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bank_code'      => 'required|string|max:16',
            'va_number'      => 'required|string|max:64',
            'amount'         => 'required|numeric|min:1',
            'external_ref'   => 'required|string|max:128',
            'received_at'    => 'required|date',
        ]);

        ProcessVaPayment::dispatch(
            $data['bank_code'],
            $data['va_number'],
            (float) $data['amount'],
            $data['external_ref'],
            Carbon::parse($data['received_at']),
        );

        return response()->json(['status' => 'accepted'], 202);
    }
}
