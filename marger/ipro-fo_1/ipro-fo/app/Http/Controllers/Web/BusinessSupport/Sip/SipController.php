<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Sip;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsCashAdvance;
use App\Domain\BusinessSupport\Models\BsSip;
use App\Domain\BusinessSupport\Models\BsSipLine;
use App\Domain\BusinessSupport\Services\SipService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * #98 — SIP (Standing Instruction Payment): instruksi + persetujuan pembayaran AP & Bon Sementara
 * per bank sumber. Tidak memposting jurnal (pembayaran riil lewat Kuitansi Hutang / Bayar Bon).
 */
class SipController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('bs.sip.view'), 403);

        // Filter PERIODE atas Tanggal SIP (sip_date). Tanggal tak berformat diabaikan agar
        // ketikan setengah jadi tidak mengosongkan daftar. Bila periode awal melewati periode
        // akhir, keduanya ditukar — lebih menolong daripada memulangkan daftar kosong.
        [$from, $to] = $this->periodeFilter($request->input('date_from'), $request->input('date_to'));

        $rows = BsSip::query()
            ->with('cashAccount:id,code,name')
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($from, fn ($q, $v) => $q->whereDate('sip_date', '>=', $v))
            ->when($to, fn ($q, $v) => $q->whereDate('sip_date', '<=', $v))
            ->latest('sip_date')->latest('id')
            ->paginate(50)->withQueryString();

        return Inertia::render('BusinessSupport/Sip/Index', [
            'sips'     => $rows,
            'statuses' => BsSip::STATUSES,
            'filters'  => ['status' => $request->input('status'), 'date_from' => $from, 'date_to' => $to],
            'summary'  => [
                // #111: belum disetujui semua pihak (Diajukan/Dicetak) vs sudah Disetujui.
                'pending'  => BsSip::whereIn('status', ['submitted', 'printed'])->count(),
                'approved' => BsSip::where('status', 'approved')->count(),
                'total'    => (float) BsSip::whereIn('status', ['submitted', 'printed', 'approved'])->sum('total'),
            ],
        ]);
    }

    /**
     * Barcode tanda tangan per SLOT persetujuan yang sudah ditandatangani.
     *
     * @return array<string,string>  slot → SVG (hanya slot yang penyetujunya punya barcode aktif)
     */
    private function approvalBarcodes(BsSip $sip, int $moduleSize = 3): array
    {
        $approvals = $sip->approvals ?? [];
        $byUser = \App\Domain\BusinessSupport\Models\BsSignatureBarcode::svgMapFor(
            array_map(fn ($a) => (int) ($a['approved_by'] ?? 0), array_values($approvals)),
            $moduleSize,
        );

        $out = [];
        foreach ($approvals as $slot => $ap) {
            $svg = $byUser[(int) ($ap['approved_by'] ?? 0)] ?? null;
            if ($svg) {
                $out[$slot] = $svg;
            }
        }

        return $out;
    }

    /**
     * Normalisasi periode awal & akhir.
     *
     * Hanya menerima tanggal berformat Y-m-d yang benar-benar ada di kalender; selain itu
     * dianggap tidak diisi. Periode awal yang melewati periode akhir ditukar posisinya.
     *
     * @return array{0:?string,1:?string}
     */
    private function periodeFilter(mixed $from, mixed $to): array
    {
        $bersih = function (mixed $v): ?string {
            $v = is_string($v) ? trim($v) : '';
            if ($v === '') {
                return null;
            }
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);

            return ($d && $d->format('Y-m-d') === $v) ? $v : null;
        };

        $from = $bersih($from);
        $to = $bersih($to);
        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('bs.sip.create'), 403);

        $src = $this->selectableSources();

        return Inertia::render('BusinessSupport/Sip/Form', [
            'invoices'     => $src['invoices'],
            'advances'     => $src['advances'],
            'cashAccounts' => $this->cashAccounts(),
        ]);
    }

    public function store(Request $request, SipService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('bs.sip.create'), 403);

        $v = $this->validateData($request);

        try {
            $sip = $svc->create($v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.sip.show', $sip)->with('success', "SIP {$sip->sip_number} dibuat.");
    }

    /** #124 — Edit SIP: hanya staf finance pembuat, selama belum disetujui penuh / dibatalkan. */
    public function edit(Request $request, BsSip $sip): Response
    {
        abort_unless($request->user()?->can('bs.sip.update'), 403);
        abort_if((int) $sip->created_by !== (int) $request->user()->id, 403);
        // #174: SIP yang DITOLAK tidak diedit — finance memulai dari awal (buat SIP baru); item sudah kembali ke daftar.
        abort_if(in_array($sip->status, ['approved', 'cancelled', 'rejected'], true), 403);

        // Sertakan AP/bon milik SIP ini sendiri agar tetap bisa dipilih saat edit.
        $src = $this->selectableSources($sip->id);
        $sip->load('lines');

        return Inertia::render('BusinessSupport/Sip/Form', [
            'invoices'     => $src['invoices'],
            'advances'     => $src['advances'],
            'cashAccounts' => $this->cashAccounts(),
            'sip'          => [
                'id'          => $sip->id,
                'sip_number'  => $sip->sip_number,
                'sip_date'    => optional($sip->sip_date)->toDateString(),
                'title'       => $sip->title,
            ],
            'editing'      => $sip->lines->map(fn ($l) => [
                'cash_account_id'   => $l->cash_account_id,
                'source_type'       => $l->sourceable_type === BsApInvoice::class ? 'ap' : 'advance',
                'source_id'         => $l->sourceable_id,
                'amount'            => (float) $l->amount,
                'dest_bank_name'    => $l->dest_bank_name,
                'dest_account_no'   => $l->dest_account_no,
                'dest_account_name' => $l->dest_account_name,
                'description'       => $l->description,
            ])->values(),
        ]);
    }

    public function update(Request $request, BsSip $sip, SipService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('bs.sip.update'), 403);
        abort_if((int) $sip->created_by !== (int) $request->user()->id, 403);
        // #174: SIP yang DITOLAK tidak diedit — finance memulai dari awal (buat SIP baru); item sudah kembali ke daftar.
        abort_if(in_array($sip->status, ['approved', 'cancelled', 'rejected'], true), 403);

        $v = $this->validateData($request);

        try {
            $svc->update($sip, $v);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.sip.show', $sip)->with('success', "SIP {$sip->sip_number} diperbarui.");
    }

    public function show(Request $request, BsSip $sip): Response
    {
        abort_unless($request->user()?->can('bs.sip.view'), 403);

        return Inertia::render('BusinessSupport/Sip/Show', [
            'sip'      => $sip->load(['cashAccount:id,code,name', 'lines.cashAccount:id,code,name']),
            'statuses' => BsSip::STATUSES,
            'signers'  => BsSip::SIGNERS,
            // Slot yang sudah disetujui ditampilkan sebagai BARCODE tanda tangan penyetujunya,
            // bukan namanya. Peta ini: slot → SVG barcode (kosong bila penyetuju belum punya
            // barcode di Cash Book → Setup → Set Up Barcode; layar akan jatuh kembali ke nama).
            'barcodes' => $this->approvalBarcodes($sip),
        ]);
    }

    public function approve(Request $request, BsSip $sip, SipService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $v = $request->validate(['slot' => ['required', 'string', 'in:'.implode(',', array_keys(BsSip::SIGNERS))]]);

        try {
            $svc->approve($sip, $v['slot'], $request->user()->id, (string) $request->user()->name);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Persetujuan SIP dicatat.');
    }

    /** #122 — Tolak SIP dengan alasan; semua TTD dibuka & finance dapat merevisi. */
    public function reject(Request $request, BsSip $sip, SipService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $v = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $svc->reject($sip, $request->user()->id, $v['reason']);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'SIP ditolak — tagihan hutang & bon sementara kembali ke daftar. Finance membuat SIP baru untuk mengajukan ulang.');
    }

    public function cancel(Request $request, BsSip $sip): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        // #111: boleh dibatalkan selama Diajukan/Dicetak; dilarang setelah Disetujui semua pihak.
        if ($sip->status === 'approved') {
            return back()->with('error', 'SIP sudah disetujui semua pihak, tidak dapat dibatalkan.');
        }
        $sip->update(['status' => 'cancelled']);

        return back()->with('success', 'SIP dibatalkan.');
    }

    public function print(Request $request, BsSip $sip): HttpResponse
    {
        abort_unless($request->user()?->can('bs.sip.print'), 403);
        // sourceable ikut dimuat: departemen pengaju dibaca dari dokumen asalnya
        // (bon sementara punya department_id; invoice hutang lewat pembuatnya).
        $sip->load(['cashAccount:id,code,name', 'lines.cashAccount:id,code,name', 'lines.sourceable']);

        $this->tetapkanNomorCetak($sip);

        // #111: cetak menandai 'Dicetak' selama belum disetujui semua pihak; jika sudah lengkap tetap 'Disetujui'.
        if (! $sip->isFullyApproved() && ! in_array($sip->status, ['cancelled', 'rejected'], true)) {
            $sip->update(['status' => 'printed']);
        }

        return response($this->renderPrint($sip))->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** @return array{slot...} validated payload */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'sip_date'                  => ['required', 'date'],
            'cash_account_id'           => ['required', 'integer', 'exists:bs_accounts,id'],
            'title'                     => ['nullable', 'string', 'max:150'],
            'lines'                     => ['required', 'array', 'min:1'],
            'lines.*.source_type'       => ['required', 'in:ap,advance'],
            'lines.*.source_id'         => ['required', 'integer'],
            'lines.*.cash_account_id'   => ['required', 'integer', 'exists:bs_accounts,id'], // #114 bank sumber per baris
            'lines.*.amount'            => ['required', 'numeric', 'min:0.01'],
            'lines.*.dest_bank_name'    => ['nullable', 'string', 'max:150'],
            'lines.*.dest_account_no'   => ['nullable', 'string', 'max:50'],
            'lines.*.dest_account_name' => ['nullable', 'string', 'max:150'],
            'lines.*.description'       => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * AP & bon yang boleh dipilih. NO110: yang sudah masuk SIP lain (belum dibatalkan) disembunyikan.
     * Saat edit, $exceptSipId mengecualikan SIP yang sedang diedit agar barisnya tetap muncul.
     */
    private function selectableSources(?int $exceptSipId = null): array
    {
        // #174: AP/bon yang ada di SIP DITOLAK (rejected) ATAU dibatalkan (cancelled) TIDAK dikunci —
        // dikembalikan ke daftar tagihan hutang & bon sementara agar finance bisa mengajukan SIP baru.
        $used = fn (string $type) => BsSipLine::where('sourceable_type', $type)
            ->whereHas('sip', function ($q) use ($exceptSipId) {
                $q->whereNotIn('status', ['cancelled', 'rejected']);
                if ($exceptSipId) {
                    $q->where('id', '!=', $exceptSipId);
                }
            })->pluck('sourceable_id')->all();

        $usedAp  = $used(BsApInvoice::class);
        $usedAdv = $used(BsCashAdvance::class);

        $invoices = BsApInvoice::where('status', 'open')
            ->whereNotIn('id', $usedAp)
            ->with('supplier:id,code,name,bank_name,bank_account_no,bank_account_name')
            ->latest('invoice_date')->get()
            ->map(fn ($i) => [
                'id'                => $i->id,
                'invoice_number'    => $i->invoice_number,
                'supplier_name'     => $i->supplier?->name,
                'description'       => $i->description,
                'outstanding'       => (float) $i->outstanding,
                'dest_bank_name'    => $i->supplier?->bank_name,
                'dest_account_no'   => $i->supplier?->bank_account_no,
                'dest_account_name' => $i->supplier?->bank_account_name,
            ])
            ->filter(fn ($i) => $i['outstanding'] > 0.009)->values();

        $advances = BsCashAdvance::where('status', 'requested')
            ->whereNotIn('id', $usedAdv)
            ->latest('advance_date')->get(['id', 'advance_number', 'holder', 'purpose', 'amount'])
            ->map(fn ($a) => [
                'id'             => $a->id,
                'advance_number' => $a->advance_number,
                'holder'         => $a->holder,
                'purpose'        => $a->purpose,
                'amount'         => (float) $a->amount,
            ])->values();

        return ['invoices' => $invoices, 'advances' => $advances];
    }

    private function cashAccounts()
    {
        return BsAccount::where('is_active', true)->where('is_postable', true)
            ->where('code', 'like', '101010%')->orderBy('code')->get(['id', 'code', 'name']);
    }

    /**
     * Tetapkan nomor cetak SIP sekali seumur dokumen: NNN/FIN/MITRA-SI/{romawi}/{tahun}.
     *
     * Urutannya per TAHUN SIP DIAJUKAN (sip_date), bukan per tahun berjalan — mencetak ulang
     * SIP tahun lalu tidak boleh menyerobot nomor tahun ini.
     *
     * Dikunci lockForUpdate: dua orang menekan Cetak bersamaan pada dokumen berbeda akan
     * membaca nomor terakhir yang sama dan menerbitkan nomor kembar. Unique index pada
     * (tenant_id, print_year, print_seq) menjadi penjaga terakhirnya.
     */
    private function tetapkanNomorCetak(BsSip $sip): void
    {
        if ($sip->print_seq && $sip->print_year) {
            return;   // sudah bernomor — tidak pernah diganti
        }
        if (! Schema::hasColumn('bs_sips', 'print_seq')) {
            return;   // instalasi belum bermigrasi: cetak tetap jalan, nomornya kosong
        }

        $tahun = (int) (optional($sip->sip_date)->year ?: now()->year);

        DB::transaction(function () use ($sip, $tahun) {
            $terakhir = (int) BsSip::where('print_year', $tahun)
                ->lockForUpdate()->max('print_seq');
            $sip->forceFill(['print_seq' => $terakhir + 1, 'print_year' => $tahun])->save();
        });
    }

    /** Label untuk baris yang departemen pengajunya tidak diketahui. */
    private const TANPA_DEPT = 'Tanpa Departemen';

    /**
     * Departemen pengaju tiap baris SIP → [sip_line_id => nama departemen].
     *
     * Tiga jalur, dicoba berurutan sampai ada yang menjawab:
     *  · Bon Sementara  — punya department_id sendiri (dipilih saat pengajuan bon).
     *  · Invoice Hutang — TIDAK punya kolom departemen. Ditelusuri lewat pembuatnya:
     *    users.employee_nip → bs_employees.nik → bs_hr_departments. Rantai yang sama
     *    dipakai Bukti Permohonan Pembayaran untuk mengisi barisnya "Departemen", jadi
     *    kedua dokumen menyebut departemen yang sama untuk invoice yang sama.
     *  · Cadangan — departemen PEMBUAT SIP-nya sendiri (bs_sips.created_by), lewat rantai
     *    yang sama. Dipakai untuk baris yang sumbernya tidak menyimpan departemen sama
     *    sekali (mis. baris manual seperti "bunga bank") atau yang pembuat invoice-nya
     *    belum terhubung ke data karyawan. Tanpa cadangan ini seluruh dokumen bisa
     *    tercetak "Tanpa Departemen" padahal pengajunya jelas.
     *
     * Semua dibaca sekali (tiga query lookup), bukan per baris — SIP bisa memuat puluhan baris.
     *
     * @return array<int, string>
     */
    private function departemenPerBaris(BsSip $sip): array
    {
        $lines = $sip->lines;

        // 1) Bon sementara → department_id langsung.
        $advIds = $lines->where('sourceable_type', BsCashAdvance::class)->pluck('sourceable_id')->filter()->unique();
        $deptBon = [];
        if ($advIds->isNotEmpty() && Schema::hasColumn('bs_cash_advances', 'department_id')) {
            $deptBon = DB::table('bs_cash_advances as a')
                ->leftJoin('bs_hr_departments as d', 'd.id', '=', 'a.department_id')
                ->whereIn('a.id', $advIds)
                ->pluck('d.name', 'a.id')->all();
        }

        // 2) Invoice hutang → lewat pembuatnya.
        $invIds = $lines->where('sourceable_type', BsApInvoice::class)->pluck('sourceable_id')->filter()->unique();
        $deptInv = [];
        if ($invIds->isNotEmpty() && Schema::hasColumn('bs_ap_invoices', 'created_by')) {
            $deptInv = DB::table('bs_ap_invoices as i')
                ->leftJoin('users as u', 'u.id', '=', 'i.created_by')
                ->leftJoin('bs_employees as e', 'e.nik', '=', 'u.employee_nip')
                ->leftJoin('bs_hr_departments as d', 'd.id', '=', 'e.department_id')
                ->whereIn('i.id', $invIds)
                ->pluck('d.name', 'i.id')->all();
        }

        // 3) Cadangan: departemen pembuat SIP-nya sendiri.
        $deptPembuat = null;
        if ($sip->created_by) {
            $deptPembuat = DB::table('users as u')
                ->leftJoin('bs_employees as e', 'e.nik', '=', 'u.employee_nip')
                ->leftJoin('bs_hr_departments as d', 'd.id', '=', 'e.department_id')
                ->where('u.id', $sip->created_by)
                ->value('d.name');
        }

        $out = [];
        foreach ($lines as $l) {
            $nama = match ($l->sourceable_type) {
                BsCashAdvance::class => $deptBon[$l->sourceable_id] ?? null,
                BsApInvoice::class   => $deptInv[$l->sourceable_id] ?? null,
                default              => null,
            };
            if (trim((string) $nama) === '') {
                $nama = $deptPembuat;
            }
            $out[$l->id] = trim((string) $nama) !== '' ? (string) $nama : self::TANPA_DEPT;
        }

        return $out;
    }

    private function renderPrint(BsSip $sip): string
    {
        $e  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
        $co = (string) config('business_support.company.name', 'PT. Mitra Usaha Propertindo');
        $now = now()->translatedFormat('d F Y H:i');
        // #114: bank sumber bisa lebih dari satu — daftar bank distinct di header + grup per bank di tabel.
        $bankList = $sip->lines->map(fn ($l) => $l->cashAccount ? $l->cashAccount->code.' — '.$l->cashAccount->name : null)->filter()->unique()->values();
        $bank = $bankList->isNotEmpty() ? $e($bankList->implode('   |   ')) : ($e($sip->cashAccount?->code).' — '.$e($sip->cashAccount?->name));

        // Pengelompokan DUA TINGKAT: departemen pengaju (luar) → bank sumber (dalam).
        //
        // Departemen di luar karena itulah yang diminta pembaca dokumen: siapa yang mengajukan
        // tagihan/bon ini. Bank sumber DIPERTAHANKAN di dalam berikut subtotalnya (#123) —
        // bank yang mengeksekusi instruksi ini butuh total per rekening sumber, dan membuangnya
        // demi pengelompokan baru akan merusak kegunaan dokumennya sebagai instruksi bayar.
        $departemen = $this->departemenPerBaris($sip);

        // Nomor resmi dokumen ini.
        //
        // Sejak penomorannya disatukan, sip_number DAN nomor cetak adalah satu angka yang
        // sama, jadi barisnya cukup satu. Baris "No. Dokumen" hanya dimunculkan untuk SIP
        // LAMA yang nomornya masih berformat SIP/MUP/… — di situ keduanya memang berbeda dan
        // pembacanya perlu melihat kedua-duanya.
        $nomorCetak = $sip->printNumber()
            ? '<div style="font-size:12px;font-weight:700;color:#1f2937">'.$e($sip->printNumber()).'</div>'
            : '';
        $noDokumen = $sip->printNumber() === $sip->sip_number
            ? ''
            : 'No. Dokumen: '.$e($sip->sip_number).'<br>';

        $rows = '';
        $no = 0;
        $perDept = $sip->lines->groupBy(fn ($l) => $departemen[$l->id] ?? self::TANPA_DEPT);
        // Departemen bernama lebih dulu, "Tanpa Departemen" selalu di ekor.
        $perDept = $perDept->sortKeys()->sortBy(fn ($v, $k) => $k === self::TANPA_DEPT ? 1 : 0);

        // Tabelnya 7 kolom sejak kolom Departemen ditambahkan; colspan di bawah mengikutinya.
        $kolom = 7;
        foreach ($perDept as $namaDept => $dlines) {
            $totalDept = 0.0;
            $rows .= '<tr class="dept"><td colspan="'.$kolom.'">DEPARTEMEN: '.$e($namaDept).'</td></tr>';

            foreach ($dlines->groupBy('cash_account_id') as $glines) {
                $acc = $glines->first()->cashAccount;
                $bankLabel = $acc ? $e($acc->code).' — '.$e($acc->name) : 'Bank Sumber';
                $sub = 0.0;
                $rows .= '<tr class="grp"><td colspan="'.$kolom.'">Bank Sumber: '.$bankLabel.'</td></tr>';
                foreach ($glines as $l) {
                    $no++;
                    $sub += (float) $l->amount;
                    $rows .= '<tr><td class="c">'.$no.'</td><td>'.$e($l->payee_name).'</td>'
                        .'<td>'.$e($departemen[$l->id] ?? self::TANPA_DEPT).'</td>'
                        .'<td>'.$e($l->dest_bank_name).'</td><td>'.$e($l->dest_account_no).'</td>'
                        .'<td>'.$e($l->description).'</td><td class="r">'.$rp($l->amount).'</td></tr>';
                }
                $rows .= '<tr class="sub"><td colspan="'.($kolom - 1).'" class="r">Total — '.$bankLabel.'</td><td class="r">'.$rp($sub).'</td></tr>';
                $totalDept += $sub;
            }

            $rows .= '<tr class="deptsub"><td colspan="'.($kolom - 1).'" class="r">Total Departemen — '.$e($namaDept).'</td><td class="r">'.$rp($totalDept).'</td></tr>';
        }

        // Slot yang sudah disetujui dicetak dengan BARCODE tanda tangan penyetujunya. Bila
        // penyetuju belum punya barcode, namanya dipakai kembali agar dokumen tetap sah dibaca.
        $approvals = $sip->approvals ?? [];
        // Modul kecil (2 px) agar QR yang berbentuk kotak muat di kotak TTD yang sempit.
        $barcodes = $this->approvalBarcodes($sip, 2);
        $signs = '';
        foreach (BsSip::SIGNERS as $key => $label) {
            $ap = $approvals[$key] ?? null;
            $bc = $barcodes[$key] ?? null;
            $name = $ap ? $e($ap['name']) : '(__________)';
            $space = $bc
                ? '<div class="sign-space sign-bc">'.$bc.'</div>'
                : '<div class="sign-space"></div>';
            $date = $ap ? '<div class="muted" style="font-size:8px">'.$e(substr((string) $ap['approved_at'], 0, 10)).'</div>' : '';
            $signs .= '<div class="sign-box"><div class="sign-label">'.$e($label).'</div>'.$space
                .'<div class="sign-name">'.($bc ? '' : $name).'</div>'.$date.'</div>';
        }

        return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>'.$e($sip->sip_number).'</title><style>
            @page{size:A4 landscape;margin:12mm}*{box-sizing:border-box}body{font-family:"Segoe UI",Arial,sans-serif;font-size:11px;color:#1f2937;margin:0}
            h1{font-size:16px;margin:0 0 2px}.muted{color:#6b7280;font-size:10px}
            .head{border-bottom:2px solid #1f2937;padding-bottom:6px;margin-bottom:8px;display:flex;justify-content:space-between}
            dl{display:grid;grid-template-columns:120px 1fr;gap:1px 8px;margin:6px 0}dt{color:#6b7280}
            table{border-collapse:collapse;width:100%;margin:6px 0;font-size:10px}th,td{border:1px solid #cbd5e1;padding:4px 6px;text-align:left}
            .r{text-align:right;font-variant-numeric:tabular-nums}.c{text-align:center}.tot{font-weight:bold;background:#f1f5f9}
            .grp{background:#e2e8f0;font-weight:600}.sub{background:#f8fafc;font-weight:600}
            /* Departemen = pengelompokan terluar, dibuat paling menonjol supaya terbaca lebih dulu daripada bank sumber. */
            .dept{background:#1f2937;color:#fff;font-weight:700;letter-spacing:.03em}
            .deptsub{background:#e2e8f0;font-weight:700;border-top:2px solid #1f2937}
            .signs{display:grid;grid-template-columns:repeat(7,1fr);gap:6px;margin-top:24px}
            .sign-box{text-align:center;font-size:9px}.sign-label{font-weight:600;margin-bottom:2px}.sign-space{height:52px}.sign-name{border-top:1px solid #1f2937;padding-top:2px;min-height:12px}
            /* QR berbentuk KOTAK — ruang TTD ditinggikan & gambarnya dibatasi tinggi kotak. */
            .sign-bc{display:flex;align-items:flex-end;justify-content:center;overflow:hidden}
            .sign-bc svg{max-width:100%;max-height:52px;height:auto;width:auto}
            @media print{.noprint{display:none}}</style></head><body>
            <div class="head"><div><h1>STANDING INSTRUCTION PAYMENT (SIP)</h1><div class="muted">'.$e($co).'</div></div>
            <div class="muted" style="text-align:right">'.$nomorCetak.$noDokumen.'Cetak: '.$e($now).'</div></div>
            <dl><dt>Tanggal</dt><dd>'.$e(optional($sip->sip_date)->translatedFormat('d F Y')).'</dd>
            <dt>Bank Sumber</dt><dd>'.$bank.'</dd>
            <dt>Judul / Ket.</dt><dd>'.$e($sip->title ?: $sip->description ?: '-').'</dd></dl>
            <table><thead><tr><th class="c">No</th><th>Nama Penerima</th><th>Departemen</th><th>Bank Tujuan</th><th>No. Rekening</th><th>Keterangan</th><th class="r">Nominal</th></tr></thead><tbody>'.$rows.'</tbody></table>
            <div class="signs">'.$signs.'</div>
            <button class="noprint" onclick="window.print()" style="margin-top:12px;padding:6px 14px">Cetak</button>
            <script>window.onload=function(){setTimeout(function(){window.print()},300)}</script></body></html>';
    }
}
