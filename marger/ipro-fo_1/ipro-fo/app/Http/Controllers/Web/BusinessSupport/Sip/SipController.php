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
        abort_unless($request->user()?->canModule('bs'), 403);

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
    private function approvalBarcodes(BsSip $sip, int $moduleWidth = 2, int $height = 34): array
    {
        $approvals = $sip->approvals ?? [];
        $byUser = \App\Domain\BusinessSupport\Models\BsSignatureBarcode::svgMapFor(
            array_map(fn ($a) => (int) ($a['approved_by'] ?? 0), array_values($approvals)),
            $moduleWidth,
            $height,
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
        abort_unless($request->user()?->canModule('bs'), 403);

        $src = $this->selectableSources();

        return Inertia::render('BusinessSupport/Sip/Form', [
            'invoices'     => $src['invoices'],
            'advances'     => $src['advances'],
            'cashAccounts' => $this->cashAccounts(),
        ]);
    }

    public function store(Request $request, SipService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('bs'), 403);

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
        abort_unless($request->user()?->canModule('bs'), 403);
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
        abort_unless($request->user()?->canModule('bs'), 403);
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
        abort_unless($request->user()?->canModule('bs'), 403);

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
        abort_unless($request->user()?->canModule('bs'), 403);
        $sip->load(['cashAccount:id,code,name', 'lines.cashAccount:id,code,name']);

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
     * Print out SIP mengikuti format legacy "Standing Instruction Payment":
     * logo + judul terpusat (Periode + No), blok Kepada/Dari/Tembusan/Tanggal Pengajuan,
     * kalimat permohonan TRANSFER + rekening sumber, tabel rincian (Nama Rekening, Bank,
     * Nomor Rekening, Amount, kolom Y/N, Peruntukan, Status), catatan kaki, dan 4 blok tanda tangan
     * (Dibuat/Diperiksa/Diketahui/Disetujui — memetakan 7 SIGNERS).
     * Routing Kepada/Dari/Tembusan bisa dioverride via config('business_support.sip').
     */
    private function renderPrint(BsSip $sip): string
    {
        $e  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $n  = fn ($x) => number_format((float) $x, 0, '.', ',');
        $co = (string) config('business_support.company.name', 'PT. Mitra Usaha Propertindo');
        $cfg = (array) config('business_support.sip', []);
        $kepada   = (string) ($cfg['kepada'] ?? 'BA, MW');
        $dari     = (string) ($cfg['dari'] ?? 'PS');
        $tembusan = (string) ($cfg['tembusan'] ?? 'RS');

        // Nomor rekening sumber = deret digit terpanjang pada nama akun (mis. "BCA REK. 6155428914");
        // nama rekening = nama akun tanpa deret digit + nama perusahaan.
        $accNo = function ($acc): string {
            preg_match_all('/\d{6,}/', (string) ($acc?->name), $m);
            return $m[0] ? (string) end($m[0]) : '-';
        };
        $accLabel = function ($acc) use ($co): string {
            $nm = trim((string) preg_replace('/\s*(REK\.?|NO\.?)?\s*\d{6,}\s*/i', ' ', (string) ($acc?->name)));
            return trim(($nm !== '' ? $nm : (string) ($acc?->name)).' '.$co);
        };

        // #114/#123: bank sumber bisa lebih dari satu — satu seksi (rekening + tabel + total) per bank.
        $sections = '';
        foreach ($sip->lines->groupBy('cash_account_id') as $glines) {
            $acc = $glines->first()->cashAccount ?: $sip->cashAccount;
            $rows = '';
            $sub = 0.0;
            foreach ($glines as $l) {
                $sub += (float) $l->amount;
                $rows .= '<tr>'
                    .'<td>'.$e($l->dest_account_name ?: $l->payee_name).'</td>'
                    .'<td class="c">'.$e($l->dest_bank_name ?: '-').'</td>'
                    .'<td class="c">'.$e($l->dest_account_no ?: '-').'</td>'
                    .'<td class="r">'.$n($l->amount).'</td>'
                    .'<td class="c yn"></td>'
                    .'<td>'.$e($l->description).'</td>'
                    .'<td class="c st"></td>'
                    .'</tr>';
            }
            $sections .= '<div class="sec">'
                .'<table class="plain"><tr><td class="lbl">dari Rekening</td><td>'.$e($accLabel($acc)).'</td></tr>'
                .'<tr><td class="lbl">Nomor Rekening</td><td>'.$e($accNo($acc)).'</td></tr></table>'
                .'<div style="margin:2px 0 4px">dengan rincian pembayaran sebagai berikut :</div>'
                .'<table class="grid"><thead><tr>'
                .'<th style="width:17%">Nama Rekening</th><th style="width:8%">Bank</th><th style="width:12%">Nomor Rekening</th>'
                .'<th style="width:10%">Amount</th><th style="width:5%">Y/N*</th><th>Peruntukan/Keterangan</th><th style="width:6%">Status</th>'
                .'</tr></thead><tbody>'.$rows
                .'<tr class="tot"><td colspan="3" style="border:none"></td><td class="r">'.$n($sub).'</td><td colspan="3" style="border:none"></td></tr>'
                .'</tbody></table></div>';
        }

        // 4 blok tanda tangan ← 7 SIGNERS: Dibuat(1) / Diperiksa(3) / Diketahui(2) / Disetujui(1).
        // Slot yang sudah disetujui dicetak dengan BARCODE tanda tangan penyetujunya; slot yang
        // belum (atau penyetujunya belum punya barcode) ruangnya dibiarkan kosong utk tanda
        // tangan basah — nama tetap tercetak di baris bawah seperti sebelumnya.
        $approvals = $sip->approvals ?? [];
        $barcodes = $this->approvalBarcodes($sip, 1, 26);
        $signGroups = [
            'Dibuat'    => ['staf_finance'],
            'Diperiksa' => ['spv_finance', 'manager_finance', 'gm'],
            'Diketahui' => ['direksi', 'moch_winarto'],
            'Disetujui' => ['boy_arifin'],
        ];
        $headCells = '';
        $signCells = '';
        $nameCells = '';
        foreach ($signGroups as $title => $slots) {
            $headCells .= '<td colspan="'.count($slots).'" class="sg-title">'.$e($title).'</td>';
            foreach ($slots as $slot) {
                $ap = $approvals[$slot] ?? null;
                $signCells .= '<td class="sg-bc">'.((string) ($barcodes[$slot] ?? '')).'</td>';
                $nameCells .= '<td class="sg-name">'.$e($ap['name'] ?? BsSip::SIGNERS[$slot]).'</td>';
            }
        }

        $periode = $sip->title ?: optional($sip->sip_date)->translatedFormat('d F Y');

        return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>'.$e($sip->sip_number).'</title><style>
            @page{size:A4 landscape;margin:10mm 12mm}*{box-sizing:border-box}
            body{font-family:Arial,"Segoe UI",sans-serif;font-size:11px;color:#111;margin:0}
            .frame{border:1.5px solid #111;padding:10px 14px;min-height:186mm}
            .tophead{display:flex;align-items:flex-start;gap:10px}
            .logo{width:56px;height:46px;background:#f97316;color:#fff;font-weight:800;font-size:28px;display:flex;align-items:center;justify-content:center}
            .title{flex:1;text-align:center;line-height:1.55;padding-right:56px}
            .title b{font-size:12.5px;letter-spacing:.2px}
            hr.dbl{border:0;border-top:4px double #111;margin:6px 0}
            table.plain{border-collapse:collapse;font-size:11px;margin:2px 0}table.plain td{padding:1px 6px 1px 0;vertical-align:top}
            table.plain td.lbl{width:130px}
            table.grid{border-collapse:collapse;width:100%;font-size:10.5px;margin:2px 0 6px}
            table.grid th,table.grid td{border:1px solid #111;padding:3px 5px;text-align:left;vertical-align:top}
            table.grid thead th{text-align:center;font-weight:bold}
            .r{text-align:right;font-variant-numeric:tabular-nums}.c{text-align:center}
            tr.tot td{font-weight:bold;border:none;border-top:1.5px solid #111;padding:3px 5px}
            .foot{font-size:10px;margin-top:4px}
            table.signs{border-collapse:collapse;width:100%;margin-top:14px;font-size:11px}
            table.signs td{border:none;text-align:center;padding:2px 6px}
            .sg-title{font-weight:normal;text-align:left !important}
            .sg-space td{height:60px}
            .sg-bc{vertical-align:bottom;text-align:center;overflow:hidden}
            .sg-bc svg{max-width:100%;height:auto}
            .sg-name{vertical-align:bottom}
            @media print{.noprint{display:none}}</style></head><body><div class="frame">
            <div class="tophead">
                <div class="logo">M</div>
                <div class="title"><b>STANDING INSTRUCTION PAYMENT</b><br>Periode : '.$e($periode).'<br>No :&nbsp;&nbsp;'.$e($sip->sip_number).'</div>
            </div>
            <hr class="dbl">
            <table class="plain">
                <tr><td class="lbl">Kepada Yth</td><td>'.$e($kepada).'</td></tr>
                <tr><td class="lbl">Dari</td><td>'.$e($dari).'</td></tr>
                <tr><td class="lbl">Tembusan</td><td>'.$e($tembusan).'</td></tr>
                <tr><td class="lbl">Tanggal Pengajuan</td><td>'.$e(optional($sip->sip_date)->format('d/m/Y')).'</td></tr>
            </table>
            <hr class="dbl">
            <div style="margin:6px 0 2px">Dengan Hormat,</div>
            <div>Bersama ini kami sampaikan list permohonan pembayaran <b>TRANSFER</b></div>
            '.$sections.'
            <div class="foot">*) Mohon untuk diberikan keterangan Y (Untuk payment yang diapprove untuk dibayar) atau N (Untuk payment yang ditunda/didiscuss lebih lanjut), jika ada.<br>
            Demikian permohonan pembayaran ini kami sampaikan, Terima Kasih</div>
            <table class="signs">
                <tr>'.$headCells.'</tr>
                <tr class="sg-space">'.$signCells.'</tr>
                <tr>'.$nameCells.'</tr>
            </table>
            </div>
            <button class="noprint" onclick="window.print()" style="margin-top:10px;padding:6px 14px">Cetak / Simpan PDF</button>
            <script>window.onload=function(){setTimeout(function(){window.print()},300)}</script></body></html>';
    }
}
