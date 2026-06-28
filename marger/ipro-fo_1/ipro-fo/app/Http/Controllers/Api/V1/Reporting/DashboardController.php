<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reporting;

use App\Domain\Collection\Tunggakan\Models\TunggakanHeader;
use App\Domain\Finance\Tts\Models\Tts;
use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Sales\Booking\Models\Booking;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function executive(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('reporting.dashboard.executive'), 403);

        return response()->json([
            'data' => [
                'summary' => [
                    'total_bookings_this_month' => Booking::whereMonth('booking_date', now()->month)->count(),
                    'cash_in_this_month'         => (float) Tts::whereMonth('received_date', now()->month)->where('status', 'posted')->sum('amount'),
                    'outstanding_total'          => (float) \DB::table('payment_schedules')->whereIn('status', ['outstanding', 'partial', 'overdue'])->sum(\DB::raw('amount - paid_amount')),
                    'pipeline_potential'         => (float) Booking::whereIn('status', ['draft', 'pilih_unit'])->sum('total_price'),
                ],
                'units' => [
                    'available' => Unit::where('status', 'available')->count(),
                    'hold'      => Unit::where('status', 'hold')->count(),
                    'booked'    => Unit::where('status', 'booked')->count(),
                    'sold'      => Unit::where('status', 'sold')->count(),
                ],
                'alerts' => [
                    'tunggakan_berat'  => TunggakanHeader::where('category', 'berat')->where('status', 'open')->count(),
                    'tts_unposted'     => Tts::whereIn('status', ['pending', 'verified'])->count(),
                ],
            ],
        ]);
    }
}
