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

        $rows = BsSip::query()
            ->with('cashAccount:id,code,name')
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('sip_number', 'like', "%{$s}%")->orWhere('title', 'like', "%{$s}%")))
            ->latest('sip_date')->latest('id')
            ->paginate(50)->withQueryString();

        return Inertia::render('BusinessSupport/Sip/Index', [
            'sips'     => $rows,
            'statuses' => BsSip::STATUSES,
            'filters'  => $request->only(['status', 'search']),
            'summary'  => [
                // #111: belum disetujui semua pihak (Diajukan/Dicetak) vs sudah Disetujui.
                'pending'  => BsSip::whereIn('status', ['submitted', 'printed'])->count(),
                'approved' => BsSip::where('status', 'approved')->count(),
                'total'    => (float) BsSip::whereIn('status', ['submitted', 'printed', 'approved'])->sum('total'),
            ],
        ]);
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

    private function renderPrint(BsSip $sip): string
    {
        $e  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
        $co = (string) config('business_support.company.name', 'PT. Mitra Usaha Propertindo');
        $now = now()->translatedFormat('d F Y H:i');
        // #114: bank sumber bisa lebih dari satu — daftar bank distinct di header + grup per bank di tabel.
        $bankList = $sip->lines->map(fn ($l) => $l->cashAccount ? $l->cashAccount->code.' — '.$l->cashAccount->name : null)->filter()->unique()->values();
        $bank = $bankList->isNotEmpty() ? $e($bankList->implode('   |   ')) : ($e($sip->cashAccount?->code).' — '.$e($sip->cashAccount?->name));

        // #123: total per bank sumber, TANPA grand total lintas bank.
        $rows = '';
        $no = 0;
        foreach ($sip->lines->groupBy('cash_account_id') as $glines) {
            $acc = $glines->first()->cashAccount;
            $bankLabel = $acc ? $e($acc->code).' — '.$e($acc->name) : 'Bank Sumber';
            $sub = 0.0;
            $rows .= '<tr class="grp"><td colspan="6">Bank Sumber: '.$bankLabel.'</td></tr>';
            foreach ($glines as $l) {
                $no++;
                $sub += (float) $l->amount;
                $rows .= '<tr><td class="c">'.$no.'</td><td>'.$e($l->payee_name).'</td><td>'.$e($l->dest_bank_name).'</td><td>'.$e($l->dest_account_no).'</td><td>'.$e($l->description).'</td><td class="r">'.$rp($l->amount).'</td></tr>';
            }
            $rows .= '<tr class="sub"><td colspan="5" class="r">Total — '.$bankLabel.'</td><td class="r">'.$rp($sub).'</td></tr>';
        }

        $approvals = $sip->approvals ?? [];
        $signs = '';
        foreach (BsSip::SIGNERS as $key => $label) {
            $ap = $approvals[$key] ?? null;
            $name = $ap ? $e($ap['name']) : '(__________)';
            $date = $ap ? '<div class="muted" style="font-size:8px">'.$e(substr((string) $ap['approved_at'], 0, 10)).'</div>' : '';
            $signs .= '<div class="sign-box"><div class="sign-label">'.$e($label).'</div><div class="sign-space"></div><div class="sign-name">'.$name.'</div>'.$date.'</div>';
        }

        return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>'.$e($sip->sip_number).'</title><style>
            @page{size:A4 landscape;margin:12mm}*{box-sizing:border-box}body{font-family:"Segoe UI",Arial,sans-serif;font-size:11px;color:#1f2937;margin:0}
            h1{font-size:16px;margin:0 0 2px}.muted{color:#6b7280;font-size:10px}
            .head{border-bottom:2px solid #1f2937;padding-bottom:6px;margin-bottom:8px;display:flex;justify-content:space-between}
            dl{display:grid;grid-template-columns:120px 1fr;gap:1px 8px;margin:6px 0}dt{color:#6b7280}
            table{border-collapse:collapse;width:100%;margin:6px 0;font-size:10px}th,td{border:1px solid #cbd5e1;padding:4px 6px;text-align:left}
            .r{text-align:right;font-variant-numeric:tabular-nums}.c{text-align:center}.tot{font-weight:bold;background:#f1f5f9}
            .grp{background:#e2e8f0;font-weight:600}.sub{background:#f8fafc;font-weight:600}
            .signs{display:grid;grid-template-columns:repeat(7,1fr);gap:6px;margin-top:24px}
            .sign-box{text-align:center;font-size:9px}.sign-label{font-weight:600;margin-bottom:2px}.sign-space{height:38px}.sign-name{border-top:1px solid #1f2937;padding-top:2px}
            @media print{.noprint{display:none}}</style></head><body>
            <div class="head"><div><h1>STANDING INSTRUCTION PAYMENT (SIP)</h1><div class="muted">'.$e($co).'</div></div>
            <div class="muted" style="text-align:right">No: '.$e($sip->sip_number).'<br>Cetak: '.$e($now).'</div></div>
            <dl><dt>Tanggal</dt><dd>'.$e(optional($sip->sip_date)->translatedFormat('d F Y')).'</dd>
            <dt>Bank Sumber</dt><dd>'.$bank.'</dd>
            <dt>Judul / Ket.</dt><dd>'.$e($sip->title ?: $sip->description ?: '-').'</dd></dl>
            <table><thead><tr><th class="c">No</th><th>Nama Penerima</th><th>Bank Tujuan</th><th>No. Rekening</th><th>Keterangan</th><th class="r">Nominal</th></tr></thead><tbody>'.$rows.'</tbody></table>
            <div class="signs">'.$signs.'</div>
            <button class="noprint" onclick="window.print()" style="margin-top:12px;padding:6px 14px">Cetak</button>
            <script>window.onload=function(){setTimeout(function(){window.print()},300)}</script></body></html>';
    }
}
