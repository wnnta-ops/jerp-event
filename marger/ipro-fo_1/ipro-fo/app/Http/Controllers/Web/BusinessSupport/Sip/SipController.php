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
use DateTimeImmutable;
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
            ->paginate(25)->withQueryString();

        return Inertia::render('BusinessSupport/Sip/Index', [
            'sips' => $rows,
            'statuses' => BsSip::STATUSES,
            'filters' => ['status' => $request->input('status'), 'date_from' => $from, 'date_to' => $to],
            'summary' => [
                // #111: belum disetujui semua pihak (Diajukan/Dicetak) vs sudah Disetujui.
                'pending' => BsSip::whereIn('status', ['submitted', 'printed'])->count(),
                'approved' => BsSip::where('status', 'approved')->count(),
                'total' => (float) BsSip::whereIn('status', ['submitted', 'printed', 'approved'])->sum('total'),
            ],
            'ttd' => BsSip::ttdNames(),
            'signers' => BsSip::SIGNERS,
            'canSetupTtd' => (bool) $request->user()?->can('bs.sip.ttd-setup'),
        ]);
    }

    /** Setup TTD SIP — nama di bawah tanda tangan cetak SIP; berlaku untuk cetakan berikutnya. */
    public function simpanTtd(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('bs.sip.ttd-setup'), 403);

        $aturan = [];
        foreach (array_keys(BsSip::TTD_DEFAULT) as $slot) {
            $aturan["ttd.$slot"] = ['required', 'string', 'max:60'];
        }
        $v = $request->validate($aturan, [], ['ttd.*' => 'nama TTD']);

        foreach ($v['ttd'] as $slot => $nama) {
            DB::table('parameters')->updateOrInsert(
                ['tenant_id' => tenant_id() ?? default_tenant_id(), 'group' => BsSip::TTD_GROUP, 'key' => $slot],
                ['value' => trim($nama), 'data_type' => 'string', 'label' => 'TTD SIP — '.BsSip::SIGNERS[$slot],
                    'updated_at' => now(), 'created_at' => now()],
            );
        }

        return back()->with('success', 'Setup TTD SIP disimpan. Berlaku pada cetakan SIP berikutnya.');
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
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);

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
            'invoices' => $src['invoices'],
            'advances' => $src['advances'],
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
            'invoices' => $src['invoices'],
            'advances' => $src['advances'],
            'cashAccounts' => $this->cashAccounts(),
            'sip' => [
                'id' => $sip->id,
                'sip_number' => $sip->sip_number,
                'sip_date' => optional($sip->sip_date)->toDateString(),
                'title' => $sip->title,
            ],
            'editing' => $sip->lines->map(fn ($l) => [
                'cash_account_id' => $l->cash_account_id,
                'source_type' => $l->sourceable_type === BsApInvoice::class ? 'ap' : 'advance',
                'source_id' => $l->sourceable_id,
                'amount' => (float) $l->amount,
                'dest_bank_name' => $l->dest_bank_name,
                'dest_account_no' => $l->dest_account_no,
                'dest_account_name' => $l->dest_account_name,
                'description' => $l->description,
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
            'sip' => $sip->load(['cashAccount:id,code,name', 'lines.cashAccount:id,code,name']),
            'statuses' => BsSip::STATUSES,
            'signers' => BsSip::SIGNERS,
            // Slot yang sudah disetujui ditampilkan sebagai BARCODE tanda tangan penyetujunya,
            // bukan namanya. Peta ini: slot → SVG barcode (kosong bila penyetuju belum punya
            // barcode di Cash Book → Setup → Set Up Barcode; layar akan jatuh kembali ke nama).
            'barcodes' => $this->approvalBarcodes($sip),
        ]);
    }

    public function approve(Request $request, BsSip $sip, SipService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['bs.sip.approve', 'businesssupport.approval.act']), 403);
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
        abort_unless($request->user()?->canAny(['bs.sip.reject', 'businesssupport.approval.act']), 403);
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
        abort_unless($request->user()?->canAny(['bs.sip.cancel', 'businesssupport.approval.act']), 403);

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
            'sip_date' => ['required', 'date'],
            'cash_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            'title' => ['nullable', 'string', 'max:150'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.source_type' => ['required', 'in:ap,advance'],
            'lines.*.source_id' => ['required', 'integer'],
            'lines.*.cash_account_id' => ['required', 'integer', 'exists:bs_accounts,id'], // #114 bank sumber per baris
            'lines.*.amount' => ['required', 'numeric', 'min:0.01'],
            'lines.*.dest_bank_name' => ['nullable', 'string', 'max:150'],
            'lines.*.dest_account_no' => ['nullable', 'string', 'max:50'],
            'lines.*.dest_account_name' => ['nullable', 'string', 'max:150'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
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

        $usedAp = $used(BsApInvoice::class);
        $usedAdv = $used(BsCashAdvance::class);

        $invoices = BsApInvoice::where('status', 'open')
            ->whereNotIn('id', $usedAp)
            ->with('supplier:id,code,name,bank_name,bank_account_no,bank_account_name')
            ->latest('invoice_date')->get()
            ->map(fn ($i) => [
                'id' => $i->id,
                'invoice_number' => $i->invoice_number,
                'supplier_name' => $i->supplier?->name,
                'description' => $i->description,
                'outstanding' => (float) $i->outstanding,
                'dest_bank_name' => $i->supplier?->bank_name,
                'dest_account_no' => $i->supplier?->bank_account_no,
                'dest_account_name' => $i->supplier?->bank_account_name,
            ])
            ->filter(fn ($i) => $i['outstanding'] > 0.009)->values();

        $advances = BsCashAdvance::where('status', 'requested')
            ->whereNotIn('id', $usedAdv)
            ->latest('advance_date')->get(['id', 'advance_number', 'holder', 'purpose', 'amount'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'advance_number' => $a->advance_number,
                'holder' => $a->holder,
                'purpose' => $a->purpose,
                'amount' => (float) $a->amount,
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
     *  · Invoice Hutang — TIDAK punya kolom departemen. Ditelusuri lewat pembuatnya.
     *  · Cadangan — pembuat SIP-nya sendiri (bs_sips.created_by). Dipakai untuk baris yang
     *    sumbernya tidak menyimpan departemen sama sekali (mis. baris manual seperti "bunga
     *    bank") atau yang pembuat invoice-nya belum terhubung ke data karyawan. Tanpa
     *    cadangan ini seluruh dokumen tercetak "Tanpa Departemen" padahal pengajunya jelas.
     *
     * Penelusuran lewat orang ada di unitPerUser(); alasan Cost Center dipakai ada di sana.
     *
     * Semua dibaca sekali, bukan per baris — SIP bisa memuat puluhan baris.
     *
     * @return array<int, string>
     */
    private function departemenPerBaris(BsSip $sip): array
    {
        $lines = $sip->lines;

        // 1) Bon sementara → cost center yang dipilih saat pengajuan.
        //    Kolomnya baru ada sejak migrasi 2026_09_08_000010, jadi keberadaannya diperiksa
        //    dulu — basis data yang belum migrasi tetap jalan.
        $advIds = $lines->where('sourceable_type', BsCashAdvance::class)->pluck('sourceable_id')->filter()->unique();
        $deptBon = [];
        if ($advIds->isNotEmpty() && Schema::hasColumn('bs_cash_advances', 'cost_center_id')) {
            $deptBon = DB::table('bs_cash_advances as a')
                ->leftJoin('bs_cost_centers as c', 'c.id', '=', 'a.cost_center_id')
                ->whereIn('a.id', $advIds)
                ->pluck('c.name', 'a.id')->all();
        }

        // 2) Invoice hutang → lewat pembuatnya.
        $invIds = $lines->where('sourceable_type', BsApInvoice::class)->pluck('sourceable_id')->filter()->unique();
        $pembuatInv = [];
        if ($invIds->isNotEmpty() && Schema::hasColumn('bs_ap_invoices', 'created_by')) {
            $pembuatInv = DB::table('bs_ap_invoices')->whereIn('id', $invIds)
                ->pluck('created_by', 'id')->all();
        }

        // Satu lookup untuk semua orang yang terlibat: pembuat tiap invoice + pembuat SIP.
        $unit = $this->unitPerUser(array_filter(array_merge(array_values($pembuatInv), [$sip->created_by])));

        $deptInv = [];
        foreach ($pembuatInv as $invId => $userId) {
            $deptInv[$invId] = $userId ? ($unit[$userId] ?? null) : null;
        }

        // 3) Cadangan: unit pembuat SIP-nya sendiri.
        $deptPembuat = $sip->created_by ? ($unit[$sip->created_by] ?? null) : null;

        $out = [];
        foreach ($lines as $l) {
            $nama = match ($l->sourceable_type) {
                BsCashAdvance::class => $deptBon[$l->sourceable_id] ?? null,
                BsApInvoice::class => $deptInv[$l->sourceable_id] ?? null,
                default => null,
            };
            if (trim((string) $nama) === '') {
                $nama = $deptPembuat;
            }
            $out[$l->id] = trim((string) $nama) !== '' ? (string) $nama : self::TANPA_DEPT;
        }

        return $out;
    }

    /**
     * id user => nama unit kerjanya: Departemen bila ada, kalau tidak Cost Center.
     *
     * "Departemen" yang tercetak di dokumen ini BERSUMBER cost center. Dulu ada master
     * Departemen tersendiri (bs_hr_departments), tapi tidak pernah diisi — 0 baris, dan
     * department_id NULL di seluruh karyawan — sehingga setiap dokumen tercetak "Tanpa
     * Departemen". Master itu sudah dicabut; cost center yang isinya memang nama divisi
     * (Marketing, Finance & Accounting, Operasional, Proyek/Konstruksi, HRD & GA) menjadi
     * satu-satunya sumber. Namanya tetap "Departemen" di dokumen dan layar HR.
     *
     * Karyawan dicocokkan lewat users.employee_nip = bs_employees.nik — kolom itu kini diisi
     * otomatis oleh IproMasterSync saat akun dibuat di J-ERP. Bila kosong, dicoba lewat
     * kesamaan nama; cadangan nama itu menyamakan perilaku dengan Bukti Permohonan Pembayaran
     * yang sudah memakainya, supaya kedua dokumen tidak menyebut unit berbeda untuk orang
     * yang sama.
     *
     * bs_employees dimuat utuh (tabel kecil) supaya pencocokan nik DAN nama bisa dilakukan di
     * memori — join bercabang dengan orOn menggandakan baris ketika keduanya cocok ke
     * karyawan yang berbeda.
     *
     * @param  array<int, int|null>  $userIds
     * @return array<int, string|null>
     */
    private function unitPerUser(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds)));
        if ($userIds === []) {
            return [];
        }

        $users = DB::table('users')->whereIn('id', $userIds)->get(['id', 'name', 'employee_nip']);

        $baris = DB::table('bs_employees as e')
            ->leftJoin('bs_cost_centers as c', 'c.id', '=', 'e.cost_center_id')
            ->get(['e.nik', 'e.name', 'c.name as cc']);

        $perNik = [];
        $perNama = [];
        foreach ($baris as $e) {
            $unit = trim((string) $e->cc);
            $unit = $unit !== '' ? $unit : null;

            if (trim((string) $e->nik) !== '') {
                $perNik[trim((string) $e->nik)] = $unit;
            }

            // Nama kembar: yang pertama menang. Menebak di antara dua karyawan senama berarti
            // mencetak divisi yang salah pada dokumen pembayaran.
            $kunci = mb_strtolower(trim((string) $e->name));
            if ($kunci !== '' && ! array_key_exists($kunci, $perNama)) {
                $perNama[$kunci] = $unit;
            }
        }

        $out = [];
        foreach ($users as $u) {
            $nip = trim((string) $u->employee_nip);
            $out[(int) $u->id] = ($nip !== '' ? ($perNik[$nip] ?? null) : null)
                ?? ($perNama[mb_strtolower(trim((string) $u->name))] ?? null);
        }

        return $out;
    }

    /**
     * Cetak SIP — format surat "STANDING INSTRUCTION PAYMENT" Finance (SIP 413): logo + periode
     * + nomor, Kepada/Dari/Tembusan, lalu satu blok per REKENING SUMBER ("dari Rekening …")
     * yang di dalamnya dikelompokkan per departemen pengaju (baris tebal di kolom Keterangan),
     * subtotal per rekening, dan tanda tangan Dibuat / Diperiksa / Diketahui / Disetujui.
     */
    private function renderPrint(BsSip $sip): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 2, ',', '.');
        $co = mb_strtoupper((string) config('business_support.company.name', 'PT. Mitra Usaha Propertindo'));
        $logo = '/images/mup-logo.jpeg';

        // Nomor resmi; SIP LAMA (nomor SIP/MUP/…) ikut menampilkan nomor dokumennya.
        $nomor = $sip->printNumber() ?: $sip->sip_number;
        $noDokumen = $sip->printNumber() && $sip->printNumber() !== $sip->sip_number
            ? '<div class="muted">No. Dokumen: '.$e($sip->sip_number).'</div>'
            : '';

        // Periode = Senin–Jumat pada minggu Tanggal SIP (format Finance: "26 - 30 Oktober 2026").
        $periode = '';
        if ($sip->sip_date) {
            $a = $sip->sip_date->copy()->startOfWeek(\Carbon\Carbon::MONDAY);
            $b = $a->copy()->addDays(4);
            $periode = match (true) {
                $a->year !== $b->year => $a->translatedFormat('d F Y').' - '.$b->translatedFormat('d F Y'),
                $a->month !== $b->month => $a->translatedFormat('d F').' - '.$b->translatedFormat('d F Y'),
                default => $a->format('d').' - '.$b->translatedFormat('d F Y'),
            };
        }

        // Nama di bawah TTD dari Setup TTD SIP (bukan akun penyetuju). Inisial: nama satu kata
        // (mis. "BA") dipakai apa adanya; nama lengkap diambil huruf depannya (Ainur Rofiq → AR).
        $namaTtd = BsSip::ttdNames();
        $orang = fn (string $slot) => $namaTtd[$slot] ?? '';
        $inisial = function (string $slot) use ($orang): string {
            $kata = preg_split('/\s+/', trim($orang($slot)), -1, PREG_SPLIT_NO_EMPTY);

            return count($kata) === 1
                ? mb_strtoupper($kata[0])
                : implode('', array_map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)), $kata));
        };

        $departemen = $this->departemenPerBaris($sip);

        // Status "Paid" bila dokumen asalnya sudah dibayar (invoice lunas / bon sudah dicairkan).
        $lunas = fn (BsSipLine $l): bool => match (true) {
            $l->sourceable instanceof BsApInvoice => $l->sourceable->status === 'paid' || $l->sourceable->outstanding <= 0.009,
            $l->sourceable instanceof BsCashAdvance => in_array($l->sourceable->status, ['outstanding', 'realized'], true),
            default => false,
        };

        $blok = '';
        $akun = fn ($l) => $l->cashAccount ?? $sip->cashAccount;
        $perBank = $sip->lines->groupBy(fn ($l) => $l->cash_account_id ?: $sip->cash_account_id)
            ->sortBy(fn ($g) => (string) $akun($g->first())?->code);
        foreach ($perBank as $glines) {
            // Nama akun kas berpola "BCA REK. 7205529888" → bank + nomor rekening.
            $nama = (string) $akun($glines->first())?->name;
            [$bank, $rek] = preg_match('/^(.*?)\s+REK\.?\s*(.+)$/i', $nama, $m)
                ? [trim($m[1]), preg_replace('/[\s-]+/', '', $m[2])]
                : [$nama, '-'];

            $rows = '';
            $sub = 0.0;
            $perDept = $glines->groupBy(fn ($l) => $departemen[$l->id] ?? self::TANPA_DEPT)
                ->sortKeys()->sortBy(fn ($v, $k) => $k === self::TANPA_DEPT ? 1 : 0);
            foreach ($perDept as $namaDept => $dlines) {
                $rows .= '<tr><td></td><td></td><td></td><td></td><td></td><td class="b">'.$e(mb_strtoupper((string) $namaDept)).'</td><td></td></tr>';
                foreach ($dlines as $l) {
                    $sub += (float) $l->amount;
                    $rows .= '<tr><td>'.$e($l->dest_account_name ?: $l->payee_name).'</td>'
                        .'<td class="c">'.$e($l->dest_bank_name).'</td><td class="c">'.$e($l->dest_account_no).'</td>'
                        .'<td class="r">'.$rp($l->amount).'</td><td></td>'
                        .'<td>'.$e($l->description).'</td><td class="c b">'.($lunas($l) ? 'Paid' : '').'</td></tr>';
                }
            }

            $blok .= '<table class="info"><tr><td>dari Rekening</td><td>'.$e(trim($bank.' '.$co)).'</td></tr>'
                .'<tr><td>Nomor Rekening</td><td>'.$e($rek).'</td></tr>'
                .'<tr><td colspan="2">dengan rincian pembayaran sebagai berikut :</td></tr></table>'
                .'<table class="grid"><colgroup><col style="width:16%"><col style="width:7%"><col style="width:10%"><col style="width:10%"><col style="width:5%"><col><col style="width:5%"></colgroup>'
                .'<thead><tr><th>Nama Rekening</th><th>Bank</th><th>Nomor Rekening</th><th>Amount</th><th>Y/N*</th><th>Peruntukan/Keterangan</th><th>Status</th></tr></thead>'
                .'<tbody>'.$rows.'<tr class="tot"><td colspan="3" class="nb"></td><td class="r b">'.$rp($sub).'</td><td colspan="3" class="nb"></td></tr></tbody></table>';
        }

        // Tanda tangan 7 kolom rata (format Finance): baris judul kelompok, ruang TTD, baris nama.
        // Judul "Diketahui" di atas kolom tengahnya (GM), "Disetujui" di atas Moch Winarto.
        // Slot yang sudah disetujui di sistem menampilkan barcode penyetujunya di ruang TTD.
        $barcodes = $this->approvalBarcodes($sip, 2);
        $judul = ['staf_finance' => 'Dibuat', 'spv_finance' => 'Diperiksa', 'gm' => 'Diketahui', 'moch_winarto' => 'Disetujui'];
        $baris = ['', '', ''];
        foreach (array_keys(BsSip::SIGNERS) as $slot) {
            $baris[0] .= '<td>'.($judul[$slot] ?? '').'</td>';
            $baris[1] .= '<td class="ttd-space">'.($barcodes[$slot] ?? '').'</td>';
            $baris[2] .= '<td>'.$e($orang($slot)).'</td>';
        }
        $signs = '<table class="signs"><tr>'.implode('</tr><tr>', $baris).'</tr></table>';

        $kepada = implode(', ', array_filter([$inisial('boy_arifin'), $inisial('moch_winarto')]));

        return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>'.$e($nomor).'</title><style>
            @page{size:A4 landscape;margin:10mm}*{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;font-size:10px;color:#000;margin:0}
            .muted{color:#555;font-size:8px;font-weight:400}.b{font-weight:700}.c{text-align:center}.r{text-align:right;font-variant-numeric:tabular-nums}
            .head{display:flex;align-items:center;padding-bottom:6px;border-bottom:3px double #000}
            .head img{width:64px;height:64px;object-fit:contain}.head .t{flex:1;text-align:center;font-weight:700;font-size:11px;line-height:1.4;margin-right:64px}
            .info{border-collapse:collapse;margin:6px 0}.info td{padding:0 8px 0 0;vertical-align:top}.info td:first-child{width:150px}
            .meta{border-bottom:2px solid #000;padding:8px 0 10px;margin-bottom:10px}
            .grid{border-collapse:collapse;width:100%;margin:4px 0 10px;font-size:9.5px}.grid th,.grid td{border:1px solid #000;padding:1px 4px}
            .grid th{text-align:center}.grid td.nb{border:none}.grid tr.tot td.r{border-bottom:2px solid #000}
            .signs{width:100%;table-layout:fixed;border-collapse:collapse;margin-top:18px;page-break-inside:avoid}.signs td{padding:0 4px 0 0;vertical-align:bottom}
            .signs td.ttd-space{height:56px}.ttd-space svg{max-height:54px;max-width:100%;width:auto;height:auto}
            @media print{.noprint{display:none}}</style></head><body>
            <div class="head"><img src="'.$logo.'" alt="MUP"><div class="t">STANDING INSTRUCTION PAYMENT<br>Periode : '.$e($periode).'<br>No : '.$e($nomor).$noDokumen.'</div></div>
            <div class="meta"><table class="info">
            <tr><td>Kepada Yth</td><td>'.$e($kepada).'</td></tr>
            <tr><td>Dari</td><td>'.$e($inisial('manager_finance')).'</td></tr>
            <tr><td>Tembusan</td><td>'.$e($inisial('gm')).'</td></tr>
            <tr><td>Tanggal Pengajuan</td><td>'.$e(optional($sip->sip_date)->format('d/m/Y')).'</td></tr></table></div>
            <div>Dengan Hormat,</div><br>
            <div>Bersama ini kami sampaikan list permohonan pembayaran <b>TRANSFER</b></div>
            '.$blok.'
            <div>*) Mohon untuk diberikan keterangan Y (Untuk payment yang diapprove untuk dibayar) atau N (Untuk payment yang ditunda/didiscuss lebih lanjut), jika ada.</div>
            <div>Demikian permohonan pembayaran ini kami sampaikan, Terima Kasih</div>
            '.$signs.'
            <button class="noprint" onclick="window.print()" style="margin-top:12px;padding:6px 14px">Cetak</button>
            <script>window.onload=function(){setTimeout(function(){window.print()},300)}</script></body></html>';
    }
}
