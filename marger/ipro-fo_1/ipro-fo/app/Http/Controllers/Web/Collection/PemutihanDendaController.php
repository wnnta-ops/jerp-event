<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Collection;

use App\Domain\Collection\Denda\Models\Denda;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pemutihan Denda (eProperty Collection → Pemutihan Denda).
 * Penghapusan (waive) sisa denda secara massal.
 */
class PemutihanDendaController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('collection.denda.view'), 403);

        $rows = Denda::query()
            ->whereIn('status', ['open', 'partial'])
            ->whereRaw('amount > amount_waived + amount_paid')
            ->with([
                'paymentSchedule:id,booking_id,installment_no,label,due_date',
                'paymentSchedule.booking:id,booking_number,customer_id,unit_id',
                'paymentSchedule.booking.customer:id,code,full_name',
                'paymentSchedule.booking.unit:id,unit_code',
            ])
            ->when($request->input('search'), fn ($q, $s) => $q->whereHas('paymentSchedule.booking.customer', fn ($w) => $w->where('full_name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->orderByDesc('computed_date')->paginate(40)->withQueryString();

        $rows->getCollection()->transform(function ($d) {
            $b = $d->paymentSchedule?->booking;

            return [
                'id'           => $d->id,
                'customer'     => $b?->customer?->full_name,
                'unit'         => $b?->unit?->unit_code,
                'termin'       => $d->paymentSchedule?->label ?? ('Termin '.$d->paymentSchedule?->installment_no),
                'due_date'     => $d->paymentSchedule?->due_date?->toDateString() ?? (string) $d->paymentSchedule?->due_date,
                'amount'       => (float) $d->amount,
                'amount_paid'  => (float) $d->amount_paid,
                'outstanding'  => $d->outstanding,
                'overdue_days' => (int) $d->overdue_days,
            ];
        });

        return Inertia::render('Collection/Denda/Pemutihan', [
            'rows'    => $rows,
            'filters' => $request->only('search'),
        ]);
    }

    public function waive(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('collection.denda.waive'), 403);
        $v = $request->validate([
            'denda_ids'   => ['required', 'array', 'min:1'],
            'denda_ids.*' => ['integer', 'exists:dendas,id'],
            'reason'      => ['nullable', 'string', 'max:255'],
        ]);

        $total = 0.0;
        DB::transaction(function () use ($v, &$total) {
            foreach (Denda::whereIn('id', $v['denda_ids'])->get() as $d) {
                $out = $d->outstanding;
                if ($out <= 0) {
                    continue;
                }
                $d->update([
                    'amount_waived' => round((float) $d->amount_waived + $out, 2),
                    'status'        => 'waived',
                ]);
                $total += $out;
            }
        });

        return back()->with('success', 'Pemutihan denda berhasil. Total diputihkan Rp '.number_format($total, 0, ',', '.').'.');
    }
}
