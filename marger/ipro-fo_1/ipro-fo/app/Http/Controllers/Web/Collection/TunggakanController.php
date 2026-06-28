<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Collection;

use App\Domain\Sales\Booking\Models\PaymentSchedule;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TunggakanController extends Controller
{
    /**
     * #117 — Dashboard tunggakan per angsuran:
     * unit, angsuran ke-berapa, aging (hari telat dari jatuh tempo → hari ini),
     * dengan warna kategori ringan/sedang/berat berdasarkan aging.
     */
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('collection.tunggakan.view'), 403);

        $today = now()->startOfDay();

        // Aging (hari telat) → kategori: ringan ≤30, sedang 31–90, berat >90.
        $bucket = static fn (int $days): string => $days <= 30 ? 'ringan' : ($days <= 90 ? 'sedang' : 'berat');

        // Sumber: jadwal pembayaran yang sudah jatuh tempo & belum lunas.
        $rows = PaymentSchedule::query()
            ->with([
                'booking:id,booking_number,unit_id,customer_id',
                'booking.unit:id,unit_code',
                'booking.customer:id,code,full_name',
            ])
            ->whereDate('due_date', '<', $today)
            ->whereColumn('paid_amount', '<', 'amount')
            ->orderBy('due_date')
            ->get()
            ->map(function ($s) use ($today, $bucket) {
                $aging = $s->due_date ? (int) $s->due_date->startOfDay()->diffInDays($today) : 0;

                return [
                    'id'            => $s->id,
                    'unit_code'     => $s->booking?->unit?->unit_code ?? '—',
                    'booking_no'    => $s->booking?->booking_number,
                    'customer'      => $s->booking?->customer?->full_name ?? '—',
                    'customer_code' => $s->booking?->customer?->code,
                    'installment'   => $s->label ?: ('Angsuran ke-'.($s->sequence ?? $s->installment_no ?? '?')),
                    'installment_no'=> $s->sequence ?? $s->installment_no,
                    'payment_type'  => $s->payment_type,
                    'due_date'      => $s->due_date?->toDateString(),
                    'aging_days'    => $aging,
                    'category'      => $bucket($aging),
                    'outstanding'   => round((float) $s->amount - (float) $s->paid_amount, 2),
                ];
            })
            ->values();

        // Filter kategori aging (opsional).
        if ($cat = $request->input('category')) {
            $rows = $rows->where('category', $cat)->values();
        }

        $summary = [
            'ringan' => $rows->where('category', 'ringan')->count(),
            'sedang' => $rows->where('category', 'sedang')->count(),
            'berat'  => $rows->where('category', 'berat')->count(),
            'count'  => $rows->count(),
            'total'  => (float) $rows->sum('outstanding'),
        ];

        return Inertia::render('Collection/Tunggakan/Index', [
            'rows'    => $rows,
            'summary' => $summary,
            'filters' => $request->only(['category']),
        ]);
    }
}
