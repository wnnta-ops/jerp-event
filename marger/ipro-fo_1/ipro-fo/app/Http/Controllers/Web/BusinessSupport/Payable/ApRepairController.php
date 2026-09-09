<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Payable;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AP → Repair Data: hitung ulang paid_amount & status invoice hutang dari penyelesaian =
 * pembayaran tunai (BsApPayment) + penghapusan hutang (BsApMemorial trx_type='penghapusan').
 * Penghapusan menaikkan paid_amount lewat memorial TANPA baris pembayaran, jadi harus ikut dihitung
 * agar Repair tidak menganggapnya "drift" lalu menghapus penyelesaian write-off.
 */
class ApRepairController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('ap.repair.view'), 403);

        return Inertia::render('BusinessSupport/Payable/Repair', [
            'diagnostics' => $this->diagnose(),
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        $res = DB::transaction(fn () => $this->repair());

        return back()->with('success', "Repair AP selesai — {$res['paid']} paid_amount dikoreksi, {$res['status']} status diselaraskan.");
    }

    /** @return \Illuminate\Support\Collection<int,object> Invoice open/paid + paid terhitung dari pembayaran + penghapusan. */
    private function invoiceRows()
    {
        $tid = tenant_id();

        // cp = pembayaran tunai + nilai penghapusan (write-off) yang masih aktif (deleted_at IS NULL).
        $cp = 'COALESCE((SELECT SUM(p.amount) FROM bs_ap_payments p WHERE p.ap_invoice_id = i.id),0)'
            .' + COALESCE((SELECT SUM(m.amount) FROM bs_ap_memorials m WHERE m.ap_invoice_id = i.id AND m.trx_type = \'penghapusan\' AND m.deleted_at IS NULL),0) cp';

        return DB::table('bs_ap_invoices as i')
            ->whereNull('i.deleted_at')->whereIn('i.status', ['open', 'paid'])
            ->when($tid, fn ($q) => $q->where('i.tenant_id', $tid))
            ->selectRaw('i.id, i.paid_amount, i.status, i.net_payable, i.total, '.$cp)
            ->get();
    }

    private function payableOf(object $r): float
    {
        return (float) $r->net_payable > 0 ? (float) $r->net_payable : (float) $r->total;
    }

    /** @return array<string,int> */
    private function diagnose(): array
    {
        $paidDrift = 0;
        $statusMismatch = 0;
        foreach ($this->invoiceRows() as $r) {
            $cp = round((float) $r->cp, 2);
            if (abs((float) $r->paid_amount - $cp) > 0.01) {
                $paidDrift++;
            }
            $newStatus = $cp >= $this->payableOf($r) - 0.01 ? 'paid' : 'open';
            if ($r->status !== $newStatus) {
                $statusMismatch++;
            }
        }

        $orphan = DB::table('bs_ap_payments as p')
            ->leftJoin('bs_ap_invoices as i', 'i.id', '=', 'p.ap_invoice_id')
            ->where(fn ($q) => $q->whereNull('i.id')->orWhereNotNull('i.deleted_at'))
            ->count();

        return ['paid_drift' => $paidDrift, 'status_mismatch' => $statusMismatch, 'orphan_payments' => $orphan];
    }

    /** @return array<string,int> */
    private function repair(): array
    {
        $paid = 0;
        $status = 0;
        foreach ($this->invoiceRows() as $r) {
            $cp = round((float) $r->cp, 2);
            $newStatus = $cp >= $this->payableOf($r) - 0.01 ? 'paid' : 'open';
            $upd = [];
            if (abs((float) $r->paid_amount - $cp) > 0.01) {
                $upd['paid_amount'] = $cp;
                $paid++;
            }
            if ($r->status !== $newStatus) {
                $upd['status'] = $newStatus;
                $status++;
            }
            if ($upd) {
                DB::table('bs_ap_invoices')->where('id', $r->id)->update($upd);
            }
        }

        return ['paid' => $paid, 'status' => $status];
    }
}
