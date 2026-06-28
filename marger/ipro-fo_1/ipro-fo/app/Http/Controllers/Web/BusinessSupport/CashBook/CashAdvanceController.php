<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\CashBook;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCashAdvance;
use App\Domain\BusinessSupport\Models\BsEmployee;
use App\Domain\BusinessSupport\Models\BsHrDepartment;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Services\CashAdvanceService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class CashAdvanceController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $rows = BsCashAdvance::query()
            ->with('cashAccount:id,code,name')
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            // #87: filter per pemegang/department (holder = "Nama — Departemen") / keperluan / no.
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('holder', 'like', "%{$s}%")
                ->orWhere('purpose', 'like', "%{$s}%")
                ->orWhere('advance_number', 'like', "%{$s}%")))
            ->latest('advance_date')->latest('id')
            ->paginate(50)->withQueryString();

        return Inertia::render('BusinessSupport/CashBook/Advances/Index', [
            'advances' => $rows,
            'filters'  => $request->only(['status', 'search']),
            'summary'  => [
                'outstanding' => (float) BsCashAdvance::where('status', 'outstanding')->sum('amount'),
                'count'       => BsCashAdvance::where('status', 'outstanding')->count(),
            ],
            'buckets'        => $this->indexBuckets(),                                                  // #134
            'canEditDocDate' => (bool) $request->user()?->canModule('cashbook'), // #125
        ]);
    }

    /**
     * #134 — 3 bucket realisasi untuk tab di halaman bon-sementara.
     * Memakai logika settlement yang sama dengan reportBuckets():
     *   settlement = amount − realized_amount
     *     = 0 → realisasi sama dengan pengajuan (Realisasi)
     *     > 0 → realisasi lebih kecil (ada sisa → Refund)
     *     < 0 → realisasi lebih besar (kurang → Reimburse)
     */
    private function indexBuckets(): array
    {
        $map = fn ($a) => [
            'id'                     => $a->id,
            'advance_number'         => $a->advance_number,
            'advance_date'           => $a->advance_date?->toDateString(),
            'realized_date'          => $a->realized_date?->toDateString(),
            'received_document_date' => $a->received_document_date?->toDateString(),
            'holder'                 => $a->holder,
            'purpose'                => $a->purpose,
            'amount'                 => (float) $a->amount,
            'realized_amount'        => (float) $a->realized_amount,
            'settlement'             => (float) $a->settlement,
            'status'                 => $a->status,
        ];

        $realisasi = BsCashAdvance::where('status', 'realized')->where('settlement', '=', 0)
            ->latest('realized_date')->latest('id')->get();
        $refund    = BsCashAdvance::where('status', 'realized')->where('settlement', '>', 0)
            ->latest('realized_date')->latest('id')->get();
        $reimburse = BsCashAdvance::where('status', 'realized')->where('settlement', '<', 0)
            ->latest('realized_date')->latest('id')->get();

        $bucket = fn ($rows, string $totalMode = 'amount') => [
            'rows'  => $rows->map($map)->values(),
            'count' => $rows->count(),
            'total' => $totalMode === 'amount'
                ? (float) $rows->sum('amount')
                : (float) $rows->sum(fn ($a) => abs((float) $a->settlement)),
        ];

        return [
            'realisasi' => $bucket($realisasi),
            'refund'    => $bucket($refund, 'settlement'),
            'reimburse' => $bucket($reimburse, 'settlement'),
        ];
    }

    /** #125 — set Tanggal Terima Dokumen (hanya finance: businesssupport.transaction.create). */
    public function setReceivedDate(Request $request, BsCashAdvance $advance): RedirectResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);
        $v = $request->validate(['received_document_date' => ['nullable', 'date']]);
        $advance->update(['received_document_date' => $v['received_document_date'] ?? null]);

        return back()->with('success', 'Tanggal terima dokumen disimpan.');
    }

    /** #127 — persetujuan bon sementara (berjenjang). */
    public function approve(Request $request, BsCashAdvance $advance, CashAdvanceService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $v = $request->validate(['slot' => ['required', 'string', 'in:'.implode(',', array_keys(BsCashAdvance::SIGNERS))]]);
        try {
            $svc->approve($advance, $v['slot'], $request->user()->id, (string) $request->user()->name);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Persetujuan bon dicatat.');
    }

    /** Kartu Bon Sementara — kartu per pemegang bon (mutasi + outstanding). */
    public function card(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        // Semua baris dikirim → filter per-kolom & ringkasan dihitung client-side (reaktif terhadap filter).
        $rows = BsCashAdvance::query()
            ->with('department:id,name')
            ->orderByDesc('advance_date')->orderByDesc('id')->get()
            ->map(fn ($a) => [
                'no'          => $a->advance_number,
                'date'        => optional($a->advance_date)->toDateString(),
                'holder'      => $a->holder,
                'department'  => $a->department?->name,
                'purpose'     => $a->purpose,
                'amount'      => (float) $a->amount,
                'realized'    => (float) $a->realized_amount,
                'outstanding' => round((float) $a->amount - (float) $a->realized_amount, 2),
                'status'      => $a->status,
            ]);

        return Inertia::render('BusinessSupport/CashBook/Advances/Card', [
            'rows'     => $rows->values(),
            'statuses' => BsCashAdvance::STATUSES,
        ]);
    }

    /** #88: laporan bon sementara — 4 bucket (transfer & settlement). #150: filter departemen. */
    public function report(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $department = $request->input('department') ?: null; // #150

        return Inertia::render('BusinessSupport/CashBook/Advances/Report', [
            'buckets'     => $this->reportBuckets($department ? (int) $department : null),
            'departments' => $this->departments(),
            'filters'     => ['department' => $department ? (int) $department : null],
        ]);
    }

    /** #175 — filter unduhan dari query (mengikuti filter layar: nama pemegang, keperluan, rentang tgl). */
    private function reportFilters(Request $request): array
    {
        $arr = function ($v): array {
            if (is_array($v)) {
                return $v;
            }

            return ($v === null || $v === '') ? [] : [$v];
        };

        return [
            'holders'  => $arr($request->input('holders')),
            'purposes' => $arr($request->input('purposes')),
            'from'     => $request->input('from') ?: null,
            'to'       => $request->input('to') ?: null,
        ];
    }

    /** #126 — unduh laporan bon sementara (Excel .xls). */
    public function reportExcel(Request $request): HttpResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $department = $request->input('department') ?: null; // #150
        $f = $this->reportFilters($request);                 // #175 ikut filter layar
        $e  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
        $h = '';
        foreach ($this->reportBuckets($department ? (int) $department : null, $f) as $b) {
            $h .= '<table border="1"><tr><th colspan="7" style="background:#0f766e;color:#fff">'.$e($b['label']).' — '.$e($rp($b['total'])).' ('.$b['count'].' bon)</th></tr>'
                .'<tr style="background:#e2e8f0"><th>No.</th><th>Tgl Bon</th><th>Tgl Realisasi</th><th>Pemegang</th><th>Departemen</th><th>Keperluan</th><th>Nilai</th></tr>';
            foreach ($b['rows'] as $r) {
                $h .= '<tr><td>'.$e($r['advance_number']).'</td><td>'.$e($r['advance_date']).'</td><td>'.$e($r['realized_date']).'</td><td>'.$e($r['holder']).'</td><td>'.$e($r['department']).'</td><td>'.$e($r['purpose']).'</td><td>'.$rp($r['amount']).'</td></tr>';
            }
            $h .= '</table><br>';
        }
        $file = 'Rekap-Bon-Sementara-'.now()->format('Ymd-His').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body><h3>REKAP BON SEMENTARA</h3>'.$h.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /** #126 — laporan bon sementara siap-cetak (PDF via dialog cetak browser). */
    public function reportPdf(Request $request): HttpResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $e  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
        $co = (string) config('business_support.company.name', 'PT. MITRA USAHA PROPERTINDO');
        $now = now()->translatedFormat('d F Y H:i');
        $department = $request->input('department') ?: null; // #150
        $deptName   = $department ? BsHrDepartment::whereKey($department)->value('name') : null;
        $f = $this->reportFilters($request);                 // #175 ikut filter layar
        // #175: tampilkan ringkas filter nama/keperluan pada header bila ada.
        $holderHint = ! empty($f['holders']) ? ' · Pemegang: '.implode(', ', $f['holders']) : '';

        $sections = '';
        foreach ($this->reportBuckets($department ? (int) $department : null, $f) as $b) {
            $rows = '';
            foreach ($b['rows'] as $r) {
                $rows .= '<tr><td>'.$e($r['advance_number']).'</td><td>'.$e($r['advance_date']).'</td><td>'.$e($r['holder']).'</td><td>'.$e($r['department']).'</td><td>'.$e($r['purpose']).'</td><td class="r">'.$rp($r['amount']).'</td></tr>';
            }
            if ($rows === '') $rows = '<tr><td colspan="6" class="c muted">Tidak ada data.</td></tr>';
            $sections .= '<h3>'.$e($b['label']).' <span class="muted">('.$b['count'].' bon · '.$rp($b['total']).')</span></h3>'
                .'<table><thead><tr><th>No.</th><th>Tanggal</th><th>Pemegang</th><th>Departemen</th><th>Keperluan</th><th class="r">Nilai</th></tr></thead><tbody>'.$rows.'</tbody></table>';
        }

        $html = '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Rekap Bon Sementara</title><style>
            @page{size:A4;margin:14mm}*{box-sizing:border-box}body{font-family:"Segoe UI",Arial,sans-serif;font-size:11px;color:#1f2937;margin:0}
            h1{font-size:16px;margin:0 0 2px;color:#b45309}h3{font-size:12px;margin:14px 0 4px;color:#92400e}.muted{color:#6b7280;font-weight:normal;font-size:10px}
            .head{border-bottom:2px solid #b45309;padding-bottom:6px;margin-bottom:10px;display:flex;justify-content:space-between}
            table{border-collapse:collapse;width:100%;margin:4px 0;font-size:10px}th,td{border:1px solid #cbd5e1;padding:4px 6px;text-align:left}
            .r{text-align:right;font-variant-numeric:tabular-nums}.c{text-align:center}@media print{.noprint{display:none}}</style></head><body>
            <div class="head"><div><h1>REKAP BON SEMENTARA</h1><div class="muted">'.$e($co).($deptName ? ' · Departemen: '.$e($deptName) : '').$e($holderHint).'</div></div><div class="muted" style="text-align:right">Cetak: '.$e($now).'</div></div>'
            .$sections.
            '<button class="noprint" onclick="window.print()" style="margin-top:12px;padding:6px 14px">Cetak / Simpan PDF</button>
            <script>window.onload=function(){setTimeout(function(){window.print()},300)}</script></body></html>';

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Bangun 4 bucket laporan bon sementara (dipakai report/reportExcel/reportPdf).
     *  #150: bila $departmentId diberikan, batasi ke departemen pemegang bon tsb.
     *  #175: $f = filter tambahan agar Excel/PDF MENGIKUTI filter layar (nama pemegang, keperluan,
     *        rentang tanggal bon) — sehingga unduhan "cukup data" sesuai yang difilter di layar. */
    private function reportBuckets(?int $departmentId = null, array $f = []): array
    {
        $holders  = array_values(array_filter((array) ($f['holders']  ?? []), fn ($v) => $v !== '' && $v !== null));
        $purposes = array_values(array_filter((array) ($f['purposes'] ?? []), fn ($v) => $v !== '' && $v !== null));
        $from     = $f['from'] ?? null;
        $to       = $f['to']   ?? null;

        $map = fn ($a) => [
            'id'             => $a->id,
            'advance_number' => $a->advance_number,
            'advance_date'   => $a->advance_date?->toDateString(),
            'paid_date'      => $a->paid_date?->toDateString(),
            'realized_date'  => $a->realized_date?->toDateString(),
            'holder'         => $a->holder,
            'department_id'  => $a->department_id,
            'department'     => $a->department?->name,
            'purpose'        => $a->purpose,
            'amount'         => (float) $a->amount,
            'settlement'     => (float) $a->settlement,
        ];

        // #150: query dasar yang men-eager-load department + filter departemen bila ada.
        // #175: + filter nama pemegang (exact, mengikuti pilihan dropdown), keperluan, & rentang tgl bon.
        $base = fn () => BsCashAdvance::query()->with('department:id,name')
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->when($holders, fn ($q) => $q->whereIn('holder', $holders))
            ->when($purposes, fn ($q) => $q->whereIn('purpose', $purposes))
            ->when($from, fn ($q) => $q->whereDate('advance_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('advance_date', '<=', $to));

        $sudahTransfer  = $base()->where('status', 'outstanding')->latest('advance_date')->get();
        $belumTransfer  = $base()->where('status', 'requested')->latest('advance_date')->get();
        $belumRefund    = $base()->where('status', 'realized')->where('settlement_status', 'pending')
            ->where('settlement', '>', 0)->latest('realized_date')->get();
        $belumReimburse = $base()->where('status', 'realized')->where('settlement_status', 'pending')
            ->where('settlement', '<', 0)->latest('realized_date')->get();

        $bucket = fn (string $label, string $desc, $rows, string $amountKey = 'amount') => [
            'label' => $label,
            'desc'  => $desc,
            'rows'  => $rows->map($map)->values(),
            'count' => $rows->count(),
            'total' => $amountKey === 'amount'
                ? (float) $rows->sum('amount')
                : (float) $rows->sum(fn ($a) => abs((float) $a->settlement)),
        ];

        return [
            'sudah_transfer'  => $bucket('Outstanding (Sudah Ditransfer)', 'Bon sudah dibayar Finance, belum direalisasi.', $sudahTransfer),
            'belum_transfer'  => $bucket('Outstanding (Belum Ditransfer)', 'Pengajuan bon menunggu pembayaran Finance.', $belumTransfer),
            'belum_refund'    => $bucket('Realisasi — Belum Direfund', 'Sisa panjar berlebih belum dikembalikan ke kas.', $belumRefund, 'settlement'),
            'belum_reimburse' => $bucket('Realisasi — Belum Direimburse', 'Kekurangan panjar belum diganti dari kas.', $belumReimburse, 'settlement'),
        ];
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        return Inertia::render('BusinessSupport/CashBook/Advances/Form', [
            // #150: employees ikut bawa department_id agar form bisa auto-isi departemen.
            'employees'   => BsEmployee::orderBy('name')->get(['id', 'name', 'position', 'department_id'])
                ->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'position' => $e->position, 'department_id' => $e->department_id]),
            'departments' => $this->departments(),
        ]);
    }

    public function store(Request $request, CashAdvanceService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        // #49: pengajuan saja — tanpa sumber kas/bank & tanpa jurnal (Finance yang membayar).
        // #150: department_id (opsional) + employee_id (opsional) untuk fallback departemen.
        $v = $request->validate([
            'advance_date'  => ['required', 'date'],
            'holder'        => ['required', 'string', 'max:150'],
            'purpose'       => ['required', 'string', 'max:255'],
            'amount'        => ['required', 'numeric', 'min:0.01'],
            'department_id' => ['nullable', 'integer', 'exists:bs_hr_departments,id'],
            'employee_id'   => ['nullable', 'integer', 'exists:bs_employees,id'],
        ]);

        try {
            $adv = $svc->issue($v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.advance.show', $adv)->with('success', "Pengajuan Bon Sementara {$adv->advance_number} tercatat — menunggu pembayaran Finance (jurnal belum terbentuk).");
    }

    /** #49: daftar pengajuan bon sementara yang menunggu pembayaran Finance. */
    public function payments(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $rows = BsCashAdvance::query()
            ->where('status', 'requested')
            ->latest('advance_date')->latest('id')
            ->paginate(50)->withQueryString();

        // Bayar hanya boleh bila approval lengkap → kirim status approval per baris.
        $rows->getCollection()->transform(function (BsCashAdvance $a) {
            $req = $a->requiredSigners();
            $a->setAttribute('is_fully_approved', $a->isFullyApproved());
            $a->setAttribute('required_count', count($req));
            $a->setAttribute('approved_count', count(array_intersect(array_keys($a->approvals ?? []), $req)));

            return $a;
        });

        return Inertia::render('BusinessSupport/CashBook/Advances/Payments', [
            'advances'     => $rows,
            'cashAccounts' => $this->cashAccounts(),
            'summary'      => [
                'count' => BsCashAdvance::where('status', 'requested')->count(),
                'total' => (float) BsCashAdvance::where('status', 'requested')->sum('amount'),
            ],
        ]);
    }

    /** #49: Finance mencatat pembayaran (tanggal transfer + sumber bank/kas) → jurnal terbentuk. */
    public function pay(Request $request, BsCashAdvance $advance, CashAdvanceService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $v = $request->validate([
            'paid_date'       => ['required', 'date'],
            'cash_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
        ]);

        // Catat Pembayaran & Buat Jurnal hanya boleh setelah SELURUH persetujuan (pengaju → Manager Finance) lengkap.
        if (! $advance->isFullyApproved()) {
            return back()->with('error', 'Pembayaran ditolak — seluruh persetujuan bon (pengaju s.d. Manager Finance) harus lengkap terlebih dahulu.');
        }

        try {
            $svc->pay($advance, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.advance.show', $advance)->with('success', 'Bon sementara dibayar — jurnal Uang Muka Operasional terbentuk.');
    }

    public function show(Request $request, BsCashAdvance $advance): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        return Inertia::render('BusinessSupport/CashBook/Advances/Show', [
            'advance'         => $advance->load(['cashAccount:id,code,name', 'lines.expenseAccount:id,code,name']),
            'expenseAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)->whereIn('type', ['expense', 'asset'])->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'cashAccounts'    => $this->cashAccounts(),
            'signers'         => BsCashAdvance::SIGNERS,                                       // #127
            'requiredSigners' => $advance->requiredSigners(),                                  // #127
            'fullyApproved'   => $advance->isFullyApproved(),                                  // gate Catat Pembayaran
            'canApprove'      => (bool) $request->user()?->can('businesssupport.approval.act'), // #127
            'canEditDocDate'  => (bool) $request->user()?->canModule('cashbook'), // #125
        ]);
    }

    public function realize(Request $request, BsCashAdvance $advance, CashAdvanceService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $v = $request->validate([
            'realized_date'            => ['required', 'date'],
            'defer_settlement'         => ['nullable', 'boolean'],
            'lines'                    => ['required', 'array', 'min:1'],
            'lines.*.expense_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            'lines.*.description'      => ['nullable', 'string', 'max:200'],
            'lines.*.amount'           => ['required', 'numeric', 'min:0.01'],
        ]);

        // Tanggal realisasi dikunci hari ini (UI read-only); penyelesaian sisa/kekurangan
        // selalu ditunda → ditangani dept Finance lewat Refund/Reimburse.
        $v['realized_date']    = now()->toDateString();
        $v['defer_settlement'] = true;

        try {
            $svc->realize($advance, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.advance.show', $advance)->with('success', 'Realisasi bon sementara tercatat — jurnal otomatis terbentuk.');
    }

    public function refund(Request $request, BsCashAdvance $advance, CashAdvanceService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);
        // #89: pilih bank penerima dana refund.
        $v = $request->validate([
            'settle_date'     => ['required', 'date'],
            'cash_account_id' => ['nullable', 'integer', 'exists:bs_accounts,id'],
        ]);

        try {
            $svc->refund($advance, $v['settle_date'], $request->user()->id, $v['cash_account_id'] ?? null);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.advance.show', $advance)->with('success', 'Refund sisa panjar tercatat — jurnal otomatis terbentuk.');
    }

    public function reimburse(Request $request, BsCashAdvance $advance, CashAdvanceService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);
        // #93: pilih bank sumber dana reimburse.
        $v = $request->validate([
            'settle_date'     => ['required', 'date'],
            'cash_account_id' => ['nullable', 'integer', 'exists:bs_accounts,id'],
        ]);

        try {
            $svc->reimburse($advance, $v['settle_date'], $request->user()->id, $v['cash_account_id'] ?? null);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.advance.show', $advance)->with('success', 'Reimburse kekurangan panjar tercatat — jurnal otomatis terbentuk.');
    }

    /** Cetak bukti PENGELUARAN bon sementara (auto-print). */
    public function print(Request $request, BsCashAdvance $advance): HttpResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);
        $advance->load('cashAccount:id,code,name');

        return response($this->renderPrint($advance, 'pengeluaran'))->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Cetak bukti REALISASI bon sementara (auto-print) — format eProperty (logo + jurnal rincian + ttd). */
    public function printRealisasi(Request $request, BsCashAdvance $advance): HttpResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);
        abort_unless($advance->status === 'realized', 404, 'Bon belum direalisasi.');
        $advance->load(['cashAccount:id,code,name', 'department:id,code,name', 'lines.expenseAccount:id,code,name']);

        return response($this->renderRealisasiPrint($advance))->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Cetak REALISASI BON SEMENTARA — layout mengikuti lampiran eProperty:
     * logo + judul, Tanggal/Departemen, Dibayarkan Kepada, Dibayar Via / Cara Pembayaran,
     * tabel JURNAL rincian (Pengajuan = Uang Muka Operasional, Realisasi = akun beban) +
     * No. Voucher + Total (selisih/settlement), Terbilang, dan blok tanda tangan berjenjang.
     */
    private function renderRealisasiPrint(BsCashAdvance $advance): string
    {
        $e   = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        // Jumlah mengikuti lampiran: "1,298,000.00" (ribuan koma, 2 desimal, tanpa "Rp").
        $amt = fn ($n) => number_format((float) $n, 2, '.', ',');

        // ── Cara pembayaran: parse nama akun kas/bank "{BANK} REK. {NO}" → bank + rekening; deteksi KAS. ──
        $cashName = (string) ($advance->cashAccount?->name ?? '');
        $isKas    = $cashName !== '' && stripos($cashName, 'rek') === false && stripos($cashName, 'kas') !== false;
        $bankName = $cashName;
        $rekening = '';
        if (preg_match('/^(.*?)\s*REK\.?\s*(.+)$/i', $cashName, $m)) {
            $bankName = trim($m[1]);
            $rekening = trim($m[2]);
        } elseif ($isKas) {
            $bankName = '';
        }
        // Kotak centang (■ = dipilih). Bon dibayar Finance via transfer bank kecuali sumber = KAS.
        $bx = fn (bool $on) => '<span class="bx">'.($on ? '&#10003;' : '&nbsp;&nbsp;').'</span>';

        // ── No. Voucher = nomor REFERENSI JURNAL realisasi (format JKB/yymm/seq — konsisten dgn referensi
        //    jurnal di modul Account Payable). Fallback ke nomor bon bila jurnal belum ada. ──
        $voucher = BsJournal::whereKey($advance->realize_journal_id)->value('journal_number') ?: $advance->advance_number;

        // ── Baris jurnal: Pengajuan (Dr Uang Muka Operasional) + Realisasi (Dr akun beban per baris). ──
        $advAccount = BsAccount::find($advance->advance_account_id);
        $pengajuanCode = $advAccount?->code ?? (string) config('business_support.gl_accounts.advance_op');
        $rows  = '<tr><td class="ac">'.$e($pengajuanCode).'</td><td>'.$e($advance->purpose).'</td><td class="r">'.$amt($advance->amount).'</td></tr>';
        foreach ($advance->lines as $l) {
            $rows .= '<tr><td class="ac">'.$e($l->expenseAccount?->code).'</td><td>'.$e($l->description).'</td><td class="r">'.$amt($l->amount).'</td></tr>';
        }

        $settlement = (float) $advance->settlement;       // + = sisa dikembalikan (refund) / − = kekurangan (reimburse)
        $total      = abs($settlement);
        $terbilang  = strtoupper(trim(preg_replace('/\s+/', ' ', terbilang((int) round($total)))).' rupiah');
        $tgl        = optional($advance->realized_date)->format('d-m-Y') ?: '-';
        $dept       = $advance->department?->name ?: '-';
        $holder     = (string) $advance->holder;
        $printDate  = now()->format('d-m-Y H.i.s');
        $logo       = '/images/mup-logo.jpeg';

        $css = '@page{size:A4;margin:12mm}*{box-sizing:border-box}'
            .'body{font-family:Arial,"Segoe UI",sans-serif;font-size:12px;color:#111;margin:0}'
            .'.cb{text-align:right;font-style:italic;font-size:11px;margin-bottom:2px}'
            .'.hd{width:100%;border:0;border-collapse:collapse}.hd td{vertical-align:top;border:0}'
            .'.logo{width:62px;height:62px;object-fit:contain}'
            .'.title{font-size:20px;font-weight:bold;text-decoration:underline;letter-spacing:.5px}'
            .'.meta{width:100%;border:0;border-collapse:collapse;margin-top:6px;font-size:12px}.meta td{border:0;padding:1px 0;vertical-align:top}'
            .'hr.sep{border:0;border-top:1.5px solid #555;margin:8px 0 8px}'
            .'.pay{width:100%;border:0;border-collapse:collapse;margin:4px 0 8px;font-size:12px}.pay td{border:0;vertical-align:top;padding:1px 0}'
            .'.bx{display:inline-block;width:13px;height:13px;border:1px solid #333;text-align:center;line-height:12px;font-size:11px;margin-right:6px;vertical-align:middle}'
            .'table.grid{width:100%;border-collapse:collapse;margin-top:2px;font-size:12px}'
            .'table.grid th,table.grid td{border:1px solid #333;padding:4px 7px;vertical-align:top}'
            .'table.grid th{background:#fff;font-weight:bold;text-align:center}'
            .'table.grid td.ac{white-space:nowrap;width:110px}table.grid td.r,table.grid th.r{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;width:120px}'
            .'.vc{font-weight:bold}.tlbl{float:right;font-weight:bold}'
            .'.terbilang{margin:7px 0 12px}'
            .'table.sign{width:100%;border:1px solid #333;border-collapse:collapse;font-size:11px}'
            .'table.sign td{border-left:1px solid #333;padding:4px 6px}table.sign td:first-child{border-left:0}'
            .'tr.sl td{vertical-align:top;height:14px}tr.ss td{height:70px}tr.sr td{vertical-align:bottom;text-align:center;font-weight:600}';

        $body = '<div class="cb">Created By eProperty ('.$e($printDate).')</div>'
            .'<table class="hd"><tr>'
            .'<td style="width:72px"><img class="logo" src="'.$logo.'" alt="logo"></td>'
            .'<td style="text-align:center"><span class="title">REALISASI BON SEMENTARA</span>'
            .'<table class="meta"><tr><td style="width:50%;text-align:left">Tanggal&nbsp;&nbsp;&nbsp;&nbsp;: '.$e($tgl).'</td>'
            .'<td style="width:50%;text-align:left">Departemen&nbsp;&nbsp;: '.$e($dept).'</td></tr></table></td>'
            .'<td style="width:72px"></td></tr></table>'
            .'<hr class="sep">'
            .'<div style="margin-bottom:4px">Dibayarkan Kepada&nbsp;&nbsp;&nbsp;:&nbsp; <b>'.$e($holder).'</b></div>'
            .'<table class="pay"><tr>'
            .'<td style="width:55%">Dibayar Via :<br>'
            .$bx($isKas).'KAS<br>'
            .$bx(! $isKas).'Bank ( '.$e($bankName).' )<br>'
            .'<span style="margin-left:1px">No. Rekening : '.$e($rekening).'</span></td>'
            .'<td style="width:45%">Cara Pembayaran Bank :<br>'
            .$bx(! $isKas).'Transfer / Check<br>'
            .$bx($isKas).'Tunai</td>'
            .'</tr></table>'
            .'<table class="grid"><thead><tr><th>No.Account</th><th>Uraian Pembayaran</th><th class="r">Jumlah</th></tr></thead>'
            .'<tbody>'.$rows.'</tbody>'
            .'<tfoot><tr><td class="vc">No. Voucher</td><td><span class="vc">'.$e($voucher).'</span><span class="tlbl">Total</span></td>'
            .'<td class="r"><b>'.$amt($total).'</b></td></tr></tfoot></table>'
            .'<div class="terbilang">Terbilang : '.$e($terbilang).'</div>'
            .'<table class="sign">'
            .'<tr class="sl"><td>Diajukan Oleh,</td><td>Di Verifikasi Oleh,</td><td colspan="3" style="text-align:center">Di Setujui Oleh,</td><td>Di Terima Oleh,</td></tr>'
            .'<tr class="ss"><td></td><td></td><td></td><td></td><td></td><td></td></tr>'
            .'<tr class="sr"><td>SPV/Manager Dept</td><td>Manager Accounting</td><td>Manager Finance</td><td>General Manager</td><td>Direksi</td><td>'.$e($holder).'</td></tr>'
            .'</table>';

        return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>'.$e($advance->advance_number).' — Realisasi</title>'
            .'<style>'.$css.'</style></head><body>'.$body
            .'<button class="noprint" onclick="window.print()" style="margin-top:14px;padding:6px 14px">Cetak</button>'
            .'<style>@media print{.noprint{display:none}}</style>'
            .'<script>window.onload=function(){setTimeout(function(){window.print()},350)}</script></body></html>';
    }

    private function renderPrint(BsCashAdvance $advance, string $mode): string
    {
        $e   = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp  = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
        $co  = (string) config('business_support.company.name', 'PT. Mitra Usaha Propertindo');
        $now = now()->translatedFormat('d F Y H:i');
        $isReal = $mode === 'realisasi';
        $isReq  = ! $isReal && $advance->status === 'requested'; // #61: cetak bukti pengajuan
        $title  = $isReal ? 'BUKTI REALISASI BON SEMENTARA' : ($isReq ? 'BUKTI PENGAJUAN BON SEMENTARA' : 'BUKTI PENGELUARAN BON SEMENTARA');

        $rows = '';
        if ($isReal) {
            foreach ($advance->lines as $l) {
                $rows .= '<tr><td>'.$e($l->expenseAccount?->code).' '.$e($l->expenseAccount?->name).'</td><td>'.$e($l->description).'</td><td class="r">'.$rp($l->amount).'</td></tr>';
            }
            $rows .= '<tr class="tot"><td colspan="2" class="r">Total Realisasi</td><td class="r">'.$rp($advance->realized_amount).'</td></tr>';
            $settle = (float) $advance->settlement;
            $rows .= '<tr><td colspan="2" class="r">Nominal Bon</td><td class="r">'.$rp($advance->amount).'</td></tr>';
            $rows .= '<tr><td colspan="2" class="r">'.($settle >= 0 ? 'Sisa dikembalikan ke kas (Refund)' : 'Kekurangan (Reimburse)').'</td><td class="r">'.$rp(abs($settle)).'</td></tr>';
        }

        $body = $isReal
            ? '<table><thead><tr><th>Akun Beban</th><th>Keterangan</th><th class="r">Jumlah</th></tr></thead><tbody>'.$rows.'</tbody></table>'
            : '<table><tr><th>Nominal</th><td class="r">'.$rp($advance->amount).'</td></tr><tr><th>'.($isReq ? 'Status' : 'Dari Kas/Bank').'</th><td>'.($isReq ? 'Menunggu pembayaran Dept Finance' : $e($advance->cashAccount?->code).' '.$e($advance->cashAccount?->name)).'</td></tr></table>';

        return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>'.$e($advance->advance_number).'</title><style>
            @page{size:A5 landscape;margin:12mm}*{box-sizing:border-box}body{font-family:"Segoe UI",Arial,sans-serif;font-size:12px;color:#1f2937;margin:0}
            h1{font-size:15px;margin:0 0 2px;color:#b45309}h2{font-size:12px;margin:0;color:#92400e}
            .head{border-bottom:2px solid #b45309;padding-bottom:6px;margin-bottom:10px;display:flex;justify-content:space-between}
            .muted{color:#6b7280;font-size:10px}table{border-collapse:collapse;width:100%;margin:6px 0;font-size:11px}
            th,td{border:1px solid #cbd5e1;padding:5px 8px;text-align:left}.r{text-align:right;font-variant-numeric:tabular-nums}
            .tot{font-weight:bold;background:#fef3c7}.sign{margin-top:28px;display:flex;justify-content:space-between;font-size:11px;text-align:center}
            dl{display:grid;grid-template-columns:120px 1fr;gap:2px 8px;margin:6px 0}dt{color:#6b7280}
            @media print{.noprint{display:none}}</style></head><body>
            <div class="head"><div><h1>'.$e($title).'</h1><div class="muted">'.$e($co).'</div></div>
            <div class="muted" style="text-align:right">No: '.$e($advance->advance_number).'<br>Cetak: '.$e($now).'</div></div>
            <dl><dt>Tanggal</dt><dd>'.$e(($isReal ? $advance->realized_date : $advance->advance_date)?->translatedFormat('d F Y')).'</dd>
            <dt>Pemegang Bon</dt><dd>'.$e($advance->holder).'</dd>
            <dt>Keperluan</dt><dd>'.$e($advance->purpose).'</dd></dl>'
            .$body.
            '<div class="sign"><div>Dibuat,<br><br><br>(__________)</div><div>Mengetahui,<br><br><br>(__________)</div><div>Finance,<br><br><br>(__________)</div></div>
            <button class="noprint" onclick="window.print()" style="margin-top:10px;padding:6px 14px">Cetak</button>
            <script>window.onload=function(){setTimeout(function(){window.print()},300)}</script></body></html>';
    }

    private function cashAccounts()
    {
        return BsAccount::where('is_active', true)->where('is_postable', true)
            ->where('code', 'like', '101010%')->orderBy('code')->get(['id', 'code', 'name']);
    }

    /** #150 — daftar departemen (HR) untuk filter laporan & auto-isi form. */
    private function departments()
    {
        return BsHrDepartment::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);
    }
}
