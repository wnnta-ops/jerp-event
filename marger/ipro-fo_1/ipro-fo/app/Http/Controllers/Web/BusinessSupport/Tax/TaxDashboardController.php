<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Tax;

use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsArInvoice;
use App\Domain\BusinessSupport\Models\BsPayrollRun;
use App\Domain\Finance\FakturPajak\Models\FakturPajak;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard Pajak — ringkasan PPN & PPh 12 bulan + pengingat jatuh tempo setor/lapor.
 */
class TaxDashboardController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('tax'), 403);
        $year = (int) ($request->integer('year') ?: now()->year);

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $arPpn   = (float) BsArInvoice::where('ppn_amount', '>', 0)->whereYear('invoice_date', $year)->whereMonth('invoice_date', $m)->sum('ppn_amount');
            $propPpn = (float) FakturPajak::where('ppn', '>', 0)->whereYear('issued_at', $year)->whereMonth('issued_at', $m)->sum('ppn');
            $apPpn   = (float) BsApInvoice::where('ppn_amount', '>', 0)->whereYear('invoice_date', $year)->whereMonth('invoice_date', $m)->sum('ppn_amount');
            $apPph   = (float) BsApInvoice::where('pph_amount', '>', 0)->whereYear('invoice_date', $year)->whereMonth('invoice_date', $m)->sum('pph_amount');
            $pph21   = (float) BsPayrollRun::where('period_year', $year)->where('period_month', $m)->withSum('lines as t', 'pph21')->get()->sum('t');

            $ppnOut = round($arPpn + $propPpn, 2);
            $months[] = [
                'month' => $m,
                'ppn_keluaran' => $ppnOut, 'ppn_masukan' => round($apPpn, 2),
                'ppn_kurang_bayar' => round($ppnOut - $apPpn, 2),
                'pph_terutang' => round($apPph + $pph21, 2),
                'has_data' => ($ppnOut + $apPpn + $apPph + $pph21) > 0.01,
            ];
        }

        $sum = fn (string $k) => round(array_sum(array_column($months, $k)), 2);

        // Pengingat jatuh tempo untuk masa terakhir yang ada datanya (atau bulan berjalan).
        $lastMasa = collect($months)->filter(fn ($m) => $m['has_data'])->last()['month'] ?? (int) now()->month;
        $reminders = $this->deadlines($year, $lastMasa, collect($months)->firstWhere('month', $lastMasa));

        return Inertia::render('BusinessSupport/Tax/Dashboard', [
            'year'   => $year,
            'months' => $months,
            'totals' => [
                'ppn_keluaran' => $sum('ppn_keluaran'), 'ppn_masukan' => $sum('ppn_masukan'),
                'ppn_kurang_bayar' => $sum('ppn_kurang_bayar'), 'pph_terutang' => $sum('pph_terutang'),
            ],
            'lastMasa'  => $lastMasa,
            'reminders' => $reminders,
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private function deadlines(int $year, int $masa, ?array $data): array
    {
        // Masa pajak M → jatuh tempo di bulan berikutnya.
        $next = Carbon::create($year, $masa, 1)->addMonthNoOverflow();
        $pphSetor = $next->copy()->day(15);
        $pphLapor = $next->copy()->day(20);
        $ppnDue   = $next->copy()->endOfMonth();
        $bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][$masa];
        $today = now()->startOfDay();
        $stat = fn (Carbon $d) => $d->lt($today) ? 'lewat' : ($d->diffInDays($today) <= 7 ? 'segera' : 'aman');

        return [
            ['label' => "PPh Masa {$bulan} — Setor", 'date' => $pphSetor->toDateString(), 'amount' => (float) ($data['pph_terutang'] ?? 0), 'status' => $stat($pphSetor), 'note' => 'Paling lambat tgl 15 bulan berikutnya'],
            ['label' => "PPh Masa {$bulan} — Lapor SPT", 'date' => $pphLapor->toDateString(), 'amount' => null, 'status' => $stat($pphLapor), 'note' => 'Paling lambat tgl 20 bulan berikutnya'],
            ['label' => "PPN Masa {$bulan} — Setor & Lapor", 'date' => $ppnDue->toDateString(), 'amount' => max(0, (float) ($data['ppn_kurang_bayar'] ?? 0)), 'status' => $stat($ppnDue), 'note' => 'Paling lambat akhir bulan berikutnya'],
        ];
    }
}
