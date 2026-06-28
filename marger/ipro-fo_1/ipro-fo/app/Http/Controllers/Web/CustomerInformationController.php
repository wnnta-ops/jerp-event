<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Collection\Denda\Models\Denda;
use App\Domain\Collection\Followup\Models\Followup;
use App\Domain\Collection\SuratPeringatan\Models\SuratPeringatan;
use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Master\Customer\Services\CustomerActivityService;
use App\Domain\Sales\Contract\Models\Contract;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer Information File (customer-360 read-only) + output Cetak (print) & Excel.
 */
class CustomerInformationController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('master.customer.view'), 403);

        $rows = Customer::query()
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('full_name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->withCount('bookings')
            ->orderBy('full_name')->paginate(25)->withQueryString();

        $rows->getCollection()->transform(fn ($c) => [
            'id' => $c->id, 'code' => $c->code, 'full_name' => $c->full_name,
            'phone' => $c->phone, 'city' => $c->city, 'bookings_count' => $c->bookings_count,
        ]);

        return Inertia::render('CustomerInfo/Index', [
            'rows'    => $rows,
            'filters' => $request->only('search'),
        ]);
    }

    public function show(Request $request, Customer $customer, CustomerActivityService $activity): Response
    {
        abort_unless($request->user()?->can('master.customer.view'), 403);

        return Inertia::render('CustomerInfo/Show', $this->gather($customer, $activity));
    }

    /** Print-out rapi (HTML siap cetak, otomatis membuka dialog cetak). */
    public function print(Request $request, Customer $customer, CustomerActivityService $activity): HttpResponse
    {
        abort_unless($request->user()?->can('master.customer.view'), 403);

        return response($this->renderHtml($this->gather($customer, $activity)))
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Unduh Excel (.xls berbasis HTML-table, dibuka langsung di Microsoft Excel). */
    public function excel(Request $request, Customer $customer, CustomerActivityService $activity): HttpResponse
    {
        abort_unless($request->user()?->can('master.customer.view'), 403);

        $d    = $this->gather($customer, $activity);
        $file = 'CIF-'.preg_replace('/[^A-Za-z0-9]+/', '-', (string) $d['customer']['code']).'.xls';

        return response($this->renderExcel($d))
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /** Kumpulkan seluruh data CIF (dipakai show/print/excel). @return array<string,mixed> */
    private function gather(Customer $customer, CustomerActivityService $activity): array
    {
        $timeline = $activity->timeline($customer);

        $customer->load(['bookings.unit:id,unit_code', 'bookings.paymentScheme:id,name', 'bookings.paymentSchedules']);

        $bookingIds  = $customer->bookings->pluck('id');
        $scheduleIds = $customer->bookings->flatMap(fn ($b) => $b->paymentSchedules->pluck('id'));
        $contracts   = Contract::whereIn('booking_id', $bookingIds)->get(['id', 'booking_id', 'contract_number', 'status'])->keyBy('booking_id');

        $contractsView = $customer->bookings->map(function ($b) use ($contracts) {
            $c = $contracts->get($b->id);

            return [
                'booking_number'  => $b->booking_number,
                'contract_number' => $c?->contract_number,
                'unit'            => $b->unit?->unit_code,
                'scheme'          => $b->paymentScheme?->name,
                'status'          => $b->status instanceof \BackedEnum ? $b->status->value : $b->status,
                'total_tagihan'   => (float) $b->total_tagihan,
                'total_terbayar'  => (float) $b->total_terbayar,
                'sisa_tagihan'    => (float) $b->sisa_tagihan,
                'persen_lunas'    => (float) $b->persen_lunas,
            ];
        })->values();

        $schedules = $customer->bookings->flatMap(fn ($b) => $b->paymentSchedules->map(fn ($s) => [
            'booking'  => $b->booking_number,
            'label'    => $s->label ?? ('Termin '.$s->installment_no),
            'due_date' => $s->due_date?->toDateString() ?? (string) $s->due_date,
            'amount'   => (float) $s->amount,
            'sisa'     => round((float) $s->amount - (float) $s->paid_amount, 2),
            'status'   => $s->status,
        ]))->sortBy('due_date')->values();

        $dendas = Denda::whereIn('payment_schedule_id', $scheduleIds)->get(['id', 'payment_schedule_id', 'amount', 'amount_waived', 'amount_paid', 'status'])
            ->map(fn ($d) => [
                'amount'      => (float) $d->amount,
                'outstanding' => round((float) $d->amount - (float) $d->amount_waived - (float) $d->amount_paid, 2),
                'status'      => $d->status,
            ]);

        $sp = SuratPeringatan::where('customer_id', $customer->id)->latest('id')->limit(50)->get(['id', 'level', 'status', 'created_at'])
            ->map(fn ($x) => ['level' => $x->level, 'status' => $x->status, 'at' => $x->created_at?->toDateString()]);

        $followups = Followup::where('customer_id', $customer->id)->latest('id')->limit(50)->get(['id', 'notes', 'status', 'created_at'])
            ->map(fn ($x) => ['notes' => $x->notes, 'status' => $x->status, 'at' => $x->created_at?->toDateString()]);

        return [
            'customer' => [
                'id' => $customer->id, 'code' => $customer->code, 'full_name' => $customer->full_name,
                'phone' => $customer->phone, 'email' => $customer->email,
                'id_type' => $customer->id_type instanceof \BackedEnum ? $customer->id_type->value : $customer->id_type,
                'id_number' => $customer->id_number,
                'address_line' => $customer->address_line, 'city' => $customer->city,
                'npwp' => $customer->npwp,
                'status' => $customer->status instanceof \BackedEnum ? $customer->status->value : $customer->status,
            ],
            'summary' => [
                'contracts'  => $contractsView->count(),
                'tagihan'    => round((float) $contractsView->sum('total_tagihan'), 2),
                'terbayar'   => round((float) $contractsView->sum('total_terbayar'), 2),
                'sisa'       => round((float) $contractsView->sum('sisa_tagihan'), 2),
                'denda_open' => round((float) $dendas->sum('outstanding'), 2),
                'sp_count'   => $sp->count(),
            ],
            'contracts'      => $contractsView,
            'schedules'      => $schedules,
            'dendas'         => $dendas->values(),
            'sp'             => $sp,
            'followups'      => $followups,
            'timeline'       => $timeline['events'],
            'activityCounts' => $timeline['counts'],
        ];
    }

    private function rp(float $n): string
    {
        return 'Rp '.number_format($n, 0, ',', '.');
    }

    private function e($s): string
    {
        if ($s instanceof \BackedEnum) {
            $s = $s->value;
        }

        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /** HTML siap cetak (A4) — auto print. */
    private function renderHtml(array $d): string
    {
        $c       = $d['customer'];
        $s       = $d['summary'];
        $company = (string) config('business_support.company.name', 'PT. Mitra Usaha Propertindo');
        $now     = now()->translatedFormat('d F Y H:i');

        $rows = '';
        foreach ($d['contracts'] as $x) {
            $rows .= '<tr><td>'.$this->e($x['booking_number']).'</td><td>'.$this->e($x['contract_number'] ?? '—').'</td><td>'.$this->e($x['unit'] ?? '—').'</td><td>'.$this->e($x['scheme'] ?? '—').'</td><td>'.$this->e($x['status']).'</td><td class="r">'.$this->rp((float) $x['total_tagihan']).'</td><td class="r">'.$this->rp((float) $x['total_terbayar']).'</td><td class="r">'.$this->rp((float) $x['sisa_tagihan']).'</td><td class="r">'.(float) $x['persen_lunas'].'%</td></tr>';
        }
        $sched = '';
        foreach ($d['schedules'] as $x) {
            $sched .= '<tr><td>'.$this->e($x['label']).'</td><td>'.$this->e($x['due_date']).'</td><td class="r">'.$this->rp((float) $x['amount']).'</td><td class="r">'.$this->rp((float) $x['sisa']).'</td><td>'.$this->e($x['status']).'</td></tr>';
        }
        $tl = '';
        foreach (array_slice($d['timeline'], 0, 80) as $x) {
            $tl .= '<tr><td>'.$this->e($x['date']).'</td><td>'.$this->e($x['module']).'</td><td>'.$this->e($x['title']).'</td><td>'.$this->e($x['detail'] ?? '').'</td><td class="r">'.($x['amount'] !== null ? $this->rp((float) $x['amount']) : '').'</td><td>'.$this->e($x['status'] ?? '').'</td></tr>';
        }
        $tlNote = count($d['timeline']) > 80 ? '<p class="muted">Menampilkan 80 aktivitas terbaru dari '.count($d['timeline']).'. Unduh Excel untuk daftar lengkap.</p>' : '';

        return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>CIF '.$this->e($c['code']).'</title><style>
            @page{size:A4;margin:14mm}*{box-sizing:border-box}body{font-family:"Segoe UI",Arial,sans-serif;font-size:11px;color:#1f2937;margin:0}
            h1{font-size:18px;margin:0 0 2px;color:#0f766e}h2{font-size:13px;margin:16px 0 6px;border-bottom:1px solid #ccc;padding-bottom:3px;color:#115e59}
            .head{display:flex;justify-content:space-between;border-bottom:2px solid #0f766e;padding-bottom:8px;margin-bottom:10px}
            .muted{color:#6b7280;font-size:10px}table{border-collapse:collapse;width:100%;margin:4px 0;font-size:10px}
            th,td{border:1px solid #cbd5e1;padding:4px 6px;text-align:left;vertical-align:top}th{background:#f1f5f9}.r{text-align:right;font-variant-numeric:tabular-nums}
            .cards{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0}.card{border:1px solid #cbd5e1;border-radius:6px;padding:6px 10px;min-width:130px}
            .card .lbl{font-size:9px;text-transform:uppercase;color:#6b7280}.card .val{font-size:13px;font-weight:bold}
            dl{display:grid;grid-template-columns:120px 1fr;gap:2px 8px;margin:0}dt{color:#6b7280}
            @media print{.noprint{display:none}}
            </style></head><body>
            <div class="head"><div><h1>Customer Information File</h1><div class="muted">'.$this->e($company).'</div></div>
            <div class="muted" style="text-align:right">Dicetak: '.$this->e($now).'<br>'.$this->e($c['code']).'</div></div>
            <h2>Profil Customer</h2>
            <dl><dt>Nama</dt><dd>'.$this->e($c['full_name']).'</dd>
            <dt>Identitas</dt><dd>'.$this->e($c['id_type']).' '.$this->e($c['id_number']).'</dd>
            <dt>NPWP</dt><dd>'.$this->e($c['npwp'] ?? '—').'</dd>
            <dt>Telepon</dt><dd>'.$this->e($c['phone'] ?? '—').'</dd>
            <dt>Email</dt><dd>'.$this->e($c['email'] ?? '—').'</dd>
            <dt>Alamat</dt><dd>'.$this->e($c['address_line'] ?? '—').', '.$this->e($c['city'] ?? '').'</dd>
            <dt>Status</dt><dd>'.$this->e($c['status']).'</dd></dl>
            <div class="cards">
            <div class="card"><div class="lbl">Kontrak</div><div class="val">'.$s['contracts'].'</div></div>
            <div class="card"><div class="lbl">Total Tagihan</div><div class="val">'.$this->rp((float) $s['tagihan']).'</div></div>
            <div class="card"><div class="lbl">Terbayar</div><div class="val">'.$this->rp((float) $s['terbayar']).'</div></div>
            <div class="card"><div class="lbl">Sisa Tagihan</div><div class="val">'.$this->rp((float) $s['sisa']).'</div></div>
            <div class="card"><div class="lbl">Denda Outstanding</div><div class="val">'.$this->rp((float) $s['denda_open']).'</div></div>
            <div class="card"><div class="lbl">Surat Peringatan</div><div class="val">'.$s['sp_count'].'</div></div>
            </div>
            <h2>Kontrak &amp; Unit</h2>
            <table><thead><tr><th>Booking</th><th>Kontrak</th><th>Unit</th><th>Skema</th><th>Status</th><th class="r">Tagihan</th><th class="r">Terbayar</th><th class="r">Sisa</th><th class="r">% Lunas</th></tr></thead><tbody>'.($rows ?: '<tr><td colspan="9">Belum ada kontrak.</td></tr>').'</tbody></table>
            <h2>Jadwal Pembayaran</h2>
            <table><thead><tr><th>Termin</th><th>Jatuh Tempo</th><th class="r">Tagihan</th><th class="r">Sisa</th><th>Status</th></tr></thead><tbody>'.($sched ?: '<tr><td colspan="5">Tidak ada jadwal.</td></tr>').'</tbody></table>
            <h2>Riwayat Aktivitas (Semua Modul)</h2>
            <table><thead><tr><th>Tanggal</th><th>Modul</th><th>Aktivitas</th><th>Detail</th><th class="r">Nominal</th><th>Status</th></tr></thead><tbody>'.($tl ?: '<tr><td colspan="6">Belum ada aktivitas.</td></tr>').'</tbody></table>'.$tlNote.'
            <p class="muted" style="margin-top:16px">Dokumen ini dihasilkan otomatis oleh Java Core System.</p>
            <button class="noprint" onclick="window.print()" style="margin-top:8px;padding:6px 14px">Cetak</button>
            <script>window.onload=function(){setTimeout(function(){window.print()},300)}</script>
            </body></html>';
    }

    /** Excel (.xls) — beberapa tabel dalam satu lembar. */
    private function renderExcel(array $d): string
    {
        $c = $d['customer'];
        $s = $d['summary'];
        $h = '<table border="1"><tr><th colspan="2" style="background:#0f766e;color:#fff">CUSTOMER INFORMATION FILE — '.$this->e($c['code']).'</th></tr>';
        $h .= '<tr><td>Nama</td><td>'.$this->e($c['full_name']).'</td></tr>';
        $h .= '<tr><td>Identitas</td><td>'.$this->e($c['id_type']).' '.$this->e($c['id_number']).'</td></tr>';
        $h .= '<tr><td>NPWP</td><td>'.$this->e($c['npwp'] ?? '').'</td></tr>';
        $h .= '<tr><td>Telepon</td><td>'.$this->e($c['phone'] ?? '').'</td></tr>';
        $h .= '<tr><td>Email</td><td>'.$this->e($c['email'] ?? '').'</td></tr>';
        $h .= '<tr><td>Alamat</td><td>'.$this->e($c['address_line'] ?? '').', '.$this->e($c['city'] ?? '').'</td></tr>';
        $h .= '<tr><td>Status</td><td>'.$this->e($c['status']).'</td></tr></table><br>';

        $h .= '<table border="1"><tr><th colspan="2" style="background:#115e59;color:#fff">RINGKASAN</th></tr>';
        $h .= '<tr><td>Jumlah Kontrak</td><td>'.$s['contracts'].'</td></tr>';
        $h .= '<tr><td>Total Tagihan</td><td>'.$this->rp((float) $s['tagihan']).'</td></tr>';
        $h .= '<tr><td>Terbayar</td><td>'.$this->rp((float) $s['terbayar']).'</td></tr>';
        $h .= '<tr><td>Sisa Tagihan</td><td>'.$this->rp((float) $s['sisa']).'</td></tr>';
        $h .= '<tr><td>Denda Outstanding</td><td>'.$this->rp((float) $s['denda_open']).'</td></tr>';
        $h .= '<tr><td>Surat Peringatan</td><td>'.$s['sp_count'].'</td></tr></table><br>';

        $h .= '<table border="1"><tr><th colspan="9" style="background:#1e40af;color:#fff">KONTRAK &amp; UNIT</th></tr><tr><th>Booking</th><th>Kontrak</th><th>Unit</th><th>Skema</th><th>Status</th><th>Tagihan</th><th>Terbayar</th><th>Sisa</th><th>% Lunas</th></tr>';
        foreach ($d['contracts'] as $x) {
            $h .= '<tr><td>'.$this->e($x['booking_number']).'</td><td>'.$this->e($x['contract_number'] ?? '').'</td><td>'.$this->e($x['unit'] ?? '').'</td><td>'.$this->e($x['scheme'] ?? '').'</td><td>'.$this->e($x['status']).'</td><td>'.$this->rp((float) $x['total_tagihan']).'</td><td>'.$this->rp((float) $x['total_terbayar']).'</td><td>'.$this->rp((float) $x['sisa_tagihan']).'</td><td>'.(float) $x['persen_lunas'].'%</td></tr>';
        }
        $h .= '</table><br>';

        $h .= '<table border="1"><tr><th colspan="5" style="background:#1e40af;color:#fff">JADWAL PEMBAYARAN</th></tr><tr><th>Termin</th><th>Jatuh Tempo</th><th>Tagihan</th><th>Sisa</th><th>Status</th></tr>';
        foreach ($d['schedules'] as $x) {
            $h .= '<tr><td>'.$this->e($x['label']).'</td><td>'.$this->e($x['due_date']).'</td><td>'.$this->rp((float) $x['amount']).'</td><td>'.$this->rp((float) $x['sisa']).'</td><td>'.$this->e($x['status']).'</td></tr>';
        }
        $h .= '</table><br>';

        $h .= '<table border="1"><tr><th colspan="6" style="background:#0f766e;color:#fff">RIWAYAT AKTIVITAS (SEMUA MODUL)</th></tr><tr><th>Tanggal</th><th>Modul</th><th>Aktivitas</th><th>Detail</th><th>Nominal</th><th>Status</th></tr>';
        foreach ($d['timeline'] as $x) {
            $h .= '<tr><td>'.$this->e($x['date']).'</td><td>'.$this->e($x['module']).'</td><td>'.$this->e($x['title']).'</td><td>'.$this->e($x['detail'] ?? '').'</td><td>'.($x['amount'] !== null ? $this->rp((float) $x['amount']) : '').'</td><td>'.$this->e($x['status'] ?? '').'</td></tr>';
        }
        $h .= '</table>';

        return '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'.$h.'</body></html>';
    }
}
