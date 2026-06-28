<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Collection;

use App\Domain\Collection\Pjt\Models\PjtHeader;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PjtController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('collection.pjt.view'), 403);

        // #120: tampilkan unit rumah/ruha, keterangan transaksi jatuh tempo (angsuran/DP), tanggal jatuh tempo.
        $headers = PjtHeader::query()
            ->with([
                'customer:id,code,full_name',
                'details.schedule:id,booking_id,label,payment_type,due_date',
                'details.schedule.booking:id,booking_number,unit_id',
                'details.schedule.booking.unit:id,unit_code',
            ])
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->latest('period_at')->limit(500)->get();

        $rows = [];
        foreach ($headers as $h) {
            if ($h->details->isEmpty()) {
                $rows[] = [
                    'pjt_number' => $h->pjt_number, 'customer' => $h->customer?->full_name,
                    'unit' => '—', 'keterangan' => '—', 'due_date' => $h->period_at?->toDateString(),
                    'amount' => (float) $h->total_overdue, 'status' => $h->status,
                ];
                continue;
            }
            foreach ($h->details as $d) {
                $s  = $d->schedule;
                $kt = $s?->label;
                if (! $kt && $s) {
                    $pt = $s->payment_type;
                    $kt = is_object($pt) && method_exists($pt, 'label') ? $pt->label() : (is_string($pt) ? $pt : null);
                }
                $rows[] = [
                    'pjt_number' => $h->pjt_number,
                    'customer'   => $h->customer?->full_name,
                    'unit'       => $s?->booking?->unit?->unit_code ?? '—',
                    'keterangan' => $kt ?: '—',
                    'due_date'   => $s?->due_date?->toDateString(),         // tanggal saja (tanpa waktu)
                    'amount'     => (float) $d->amount,
                    'status'     => $h->status,
                ];
            }
        }

        return Inertia::render('Collection/Pjt/Index', [
            'rows'    => $rows,
            'filters' => $request->only(['status']),
        ]);
    }

    public function regenerate(): \Illuminate\Http\RedirectResponse
    {
        abort_unless(auth()->user()?->can('collection.pjt.regenerate'), 403);

        \Artisan::call('pjt:generate');
        return back()->with('success', 'PJT regenerated.');
    }
}
