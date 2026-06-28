<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Collection;

use App\Domain\Collection\SuratPeringatan\Actions\IssueSp;
use App\Domain\Collection\SuratPeringatan\Models\SuratPeringatan;
use App\Domain\Collection\Tunggakan\Models\TunggakanHeader;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SpController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('collection.sp.view'), 403);

        $rows = SuratPeringatan::query()->with('customer:id,code,full_name')->latest('issued_at')->paginate(50);

        // Tunggakan aktif untuk penerbitan SP baru — #118: tampilkan unit (rumah/ruha) yang menunggak.
        $tunggakanRaw = TunggakanHeader::query()
            ->whereNotIn('status', ['lunas', 'selesai', 'closed'])
            ->with('customer:id,code,full_name')
            ->latest('id')->limit(300)
            ->get(['id', 'customer_id', 'total_amount', 'status']);

        $units = $this->unitsByCustomer($tunggakanRaw->pluck('customer_id')->unique()->all());

        $tunggakan = $tunggakanRaw->map(fn ($t) => [
            'id'     => $t->id,
            'label'  => trim(($t->customer?->code ?? '').' — '.($t->customer?->full_name ?? 'Customer #'.$t->customer_id)),
            'units'  => $units[$t->customer_id] ?? '—',
            'amount' => (float) $t->total_amount,
            'status' => $t->status,
        ]);

        return Inertia::render('Collection/SuratPeringatan/Index', [
            'rows'      => $rows,
            'tunggakan' => $tunggakan,
        ]);
    }

    public function show(Request $request, SuratPeringatan $sp): Response
    {
        abort_unless($request->user()?->can('collection.sp.view'), 403);

        $sp->load([
            'customer:id,code,full_name,phone',
            'tunggakan:id,transaction_type,category,term_overdue_count,total_amount,first_overdue_at',
            'signer:id,name',
            'approvals' => fn ($q) => $q->latest('id')->with([
                'steps' => fn ($s) => $s->orderBy('level'),
            ]),
        ]);

        // #118: SP harus disetujui finance sebelum dicetak.
        $approval = $sp->approvals->first();

        return Inertia::render('Collection/SuratPeringatan/Show', [
            'sp'       => $sp,
            'units'    => $this->unitsByCustomer([$sp->customer_id])[$sp->customer_id] ?? '—',
            'approval' => $approval ? [
                'id'         => $approval->id,
                'status'     => $approval->status,
                'steps'      => $approval->steps->map(fn ($s) => [
                    'id'       => $s->id,
                    'level'    => $s->level,
                    'status'   => $s->status,
                    'notes'    => $s->notes,
                    'acted_at' => $s->acted_at,
                ]),
                'is_approved' => $approval->status === 'approved',
            ] : null,
            'isApproved' => $sp->status === 'approved' && (! $approval || $approval->status === 'approved'),
        ]);
    }

    public function issue(Request $request, TunggakanHeader $tunggakan, IssueSp $action): RedirectResponse
    {
        abort_unless($request->user()?->can('collection.sp.issue'), 403);

        $data = $request->validate([
            'level'         => 'required|in:SP1,SP2,SP3',
            'deadline_days' => 'nullable|integer|min:1|max:90',
        ]);

        $sp = $action->execute($tunggakan, $data['level'], $data['deadline_days'] ?? 14);

        // #118: SP menunggu persetujuan finance; tombol cetak aktif setelah disetujui.
        return redirect()->route('sp.show', $sp->id)
            ->with('success', "{$data['level']} {$sp->sp_number} diterbitkan — menunggu persetujuan finance sebelum dapat dicetak.");
    }

    /** #118: cetak Surat Peringatan (auto-print) dengan tanda tangan finance. */
    public function print(Request $request, SuratPeringatan $sp): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('collection.sp.view'), 403);

        // #118: hanya boleh dicetak setelah disetujui finance.
        abort_unless($sp->status === 'approved', 403, 'Surat Peringatan belum disetujui finance.');

        $sp->load([
            'customer:id,code,full_name,phone',
            'tunggakan:id,transaction_type,category,term_overdue_count,total_amount,first_overdue_at',
            'signer:id,name',
        ]);
        $units = $this->unitsByCustomer([$sp->customer_id])[$sp->customer_id] ?? '—';

        return response($this->renderSpPrint($sp, (string) $units))->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Kode unit (rumah/ruha) per customer, diambil dari booking. */
    private function unitsByCustomer(array $customerIds): Collection
    {
        if (! $customerIds) {
            return collect();
        }

        return DB::table('bookings')
            ->join('units', 'units.id', '=', 'bookings.unit_id')
            ->whereIn('bookings.customer_id', $customerIds)
            ->whereNull('bookings.deleted_at')
            ->get(['bookings.customer_id', 'units.unit_code'])
            ->groupBy('customer_id')
            ->map(fn ($g) => $g->pluck('unit_code')->unique()->implode(', '));
    }

    private function renderSpPrint(SuratPeringatan $sp, string $units): string
    {
        $e  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
        $co = (string) config('business_support.company.name', 'PT. MITRA USAHA PROPERTINDO');
        $now = now()->translatedFormat('d F Y');
        $levelTitle = [
            'SP1' => 'SURAT PERINGATAN PERTAMA (SP-1)',
            'SP2' => 'SURAT PERINGATAN KEDUA (SP-2)',
            'SP3' => 'SURAT PERINGATAN KETIGA / TERAKHIR (SP-3)',
        ][$sp->level] ?? 'SURAT PERINGATAN';
        $tung   = $sp->tunggakan;
        $amount = $tung?->total_amount ?? 0;

        return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>'.$e($sp->sp_number).'</title><style>
            @page{size:A4;margin:20mm}*{box-sizing:border-box}body{font-family:"Times New Roman",Georgia,serif;font-size:12px;color:#111;margin:0;line-height:1.6}
            .co{text-align:center;border-bottom:2px solid #111;padding-bottom:8px;margin-bottom:14px}.co h2{margin:0;font-size:16px}
            .title{text-align:center;font-weight:bold;text-decoration:underline;font-size:14px;margin:8px 0}
            .no{text-align:center;font-size:12px;margin-bottom:14px}
            .meta{margin:4px 0}.meta b{display:inline-block;min-width:130px}
            table.t{border-collapse:collapse;margin:8px 0}table.t td{padding:2px 8px}
            p{text-align:justify;margin:8px 0}.sign{margin-top:36px;display:flex;justify-content:flex-end;text-align:center}
            @media print{.noprint{display:none}}</style></head><body>
            <div class="co"><h2>'.$e($co).'</h2><div style="font-size:10px">Surat Peringatan Pembayaran Angsuran</div></div>
            <div class="title">'.$e($levelTitle).'</div>
            <div class="no">No: '.$e($sp->sp_number).'</div>
            <div class="meta"><b>Kepada Yth.</b>: '.$e($sp->customer?->full_name).'</div>
            <div class="meta"><b>Unit</b>: '.$e($units).'</div>
            <div class="meta"><b>No. Telepon</b>: '.$e($sp->customer?->phone ?: '—').'</div>
            <p>Dengan hormat,</p>
            <p>Berdasarkan catatan kami, Bapak/Ibu memiliki kewajiban pembayaran angsuran yang telah jatuh tempo dan belum diselesaikan, dengan rincian sebagai berikut:</p>
            <table class="t">
              <tr><td>Tipe Tunggakan</td><td>:</td><td>'.$e($tung?->transaction_type ?: '—').'</td></tr>
              <tr><td>Jumlah Termin Tunggak</td><td>:</td><td>'.$e($tung?->term_overdue_count ?? '—').'</td></tr>
              <tr><td>Total Tunggakan</td><td>:</td><td><b>'.$rp($amount).'</b></td></tr>
              <tr><td>Batas Waktu Pelunasan</td><td>:</td><td><b>'.$e(optional($sp->deadline_at)->translatedFormat('d F Y')).'</b></td></tr>
            </table>
            <p>Sehubungan dengan hal tersebut, kami mohon Bapak/Ibu segera menyelesaikan kewajiban pembayaran selambat-lambatnya pada tanggal tersebut di atas. Apabila hingga batas waktu yang ditentukan kewajiban belum diselesaikan, maka akan ditindaklanjuti sesuai ketentuan yang berlaku.</p>
            <p>Demikian surat peringatan ini kami sampaikan. Atas perhatian dan kerja samanya kami ucapkan terima kasih.</p>
            <div class="sign"><div>'.$e($now).'<br>Hormat kami,<br>Dept. Finance<br><br><br><br><b>'.$e($sp->signer?->name ?: '(__________)').'</b></div></div>
            <button class="noprint" onclick="window.print()" style="margin-top:16px;padding:6px 14px">Cetak</button>
            <script>window.onload=function(){setTimeout(function(){window.print()},300)}</script></body></html>';
    }
}
