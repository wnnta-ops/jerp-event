<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Payable;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsApKuitansi;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Domain\BusinessSupport\Services\ApKuitansiService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApKuitansiController extends Controller
{
    /** #50: rekening "kas tunai" — sisanya dianggap bank/giro. */
    private const KAS_CODES = ['10101010101', '10101010102']; // Kas Operasional, Kas Kecil

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        // Semua kuitansi dikirim; filter per-kolom & ringkasan dihitung di sisi klien (mengikuti filter).
        $rows = BsApKuitansi::with('supplier:id,name')
            ->select(['id', 'kuitansi_number', 'kuitansi_date', 'supplier_id', 'alat_transaksi', 'total', 'status'])
            ->latest('kuitansi_date')->latest('id')->get()
            ->map(fn ($k) => [
                'id'              => $k->id,
                'kuitansi_number' => $k->kuitansi_number,
                'kuitansi_date'   => $k->kuitansi_date?->toDateString(),
                'supplier'        => $k->supplier ? ['name' => $k->supplier->name] : null,
                'alat_transaksi'  => $k->alat_transaksi,
                'total'           => (float) $k->total,
                'status'          => $k->status,
            ]);

        return Inertia::render('BusinessSupport/Payable/Kuitansi/Index', [
            'kuitansis' => $rows,
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        $open = BsApInvoice::where('status', 'open')->with('supplier:id,code,name')->get()
            ->map(fn ($i) => [
                'id' => $i->id, 'invoice_number' => $i->invoice_number, 'supplier_id' => $i->supplier_id,
                'description' => $i->description, 'outstanding' => (float) $i->outstanding,
                'due_date' => $i->due_date?->format('Y-m-d'),
            ])->filter(fn ($i) => $i['outstanding'] > 0.009)->values();

        return Inertia::render('BusinessSupport/Payable/Kuitansi/Form', [
            'suppliers'    => BsSupplier::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'openInvoices' => $open,
            'cashAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)->where('code', 'like', '101010%')->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'is_kas' => in_array($a->code, self::KAS_CODES, true)]),
            'defaultCash'  => BsAccount::where('code', config('business_support.gl_accounts.cash'))->value('id'),
            'alat'         => BsApKuitansi::ALAT,
            'kasir'        => $request->user()->name,
        ]);
    }

    public function store(Request $request, ApKuitansiService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        $v = $request->validate([
            'kuitansi_date'       => ['required', 'date'],
            'supplier_id'         => ['required', 'integer', 'exists:bs_suppliers,id'],
            'cash_account_id'     => ['required', 'integer', 'exists:bs_accounts,id'],
            'alat_transaksi'      => ['required', 'in:tunai,transfer,giro'],
            'kasir'               => ['nullable', 'string', 'max:150'],
            'manual_no'           => ['nullable', 'string', 'max:80'],
            'bg_number'           => ['nullable', 'string', 'max:80', 'required_if:alat_transaksi,giro'],
            'description'         => ['nullable', 'string', 'max:255'],
            'lines'                  => ['required', 'array', 'min:1'],
            'lines.*.ap_invoice_id'  => ['required', 'integer', 'exists:bs_ap_invoices,id'],
            'lines.*.amount'         => ['required', 'numeric', 'min:0.01'],
        ], ['bg_number.required_if' => 'Nomor BG/Cek wajib diisi untuk alat transaksi Cek/Giro.']);

        // #50: kasir dikunci ke user login (tidak dipercaya dari client).
        $v['kasir'] = $request->user()->name;

        // #50: rekening kas/bank harus sesuai alat transaksi.
        $code  = (string) BsAccount::where('id', $v['cash_account_id'])->value('code');
        $isKas = in_array($code, self::KAS_CODES, true);
        if ($v['alat_transaksi'] === 'tunai' && ! $isKas) {
            return back()->withInput()->with('error', 'Alat transaksi Tunai hanya boleh dari Kas Operasional atau Kas Kecil.');
        }
        if (in_array($v['alat_transaksi'], ['transfer', 'giro'], true) && $isKas) {
            return back()->withInput()->with('error', 'Alat transaksi Transfer / Cek-Giro harus dari rekening bank (bukan Kas Operasional/Kas Kecil).');
        }

        try {
            $kui = $svc->create($v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.ap.kuitansi.show', $kui)->with('success', "Kuitansi {$kui->kuitansi_number} dibuat — menunggu persetujuan di Approval Pengeluaran Kas & Bank.");
    }

    public function show(Request $request, BsApKuitansi $kuitansi): Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        return Inertia::render('BusinessSupport/Payable/Kuitansi/Show', [
            'kuitansi' => $kuitansi->load([
                'supplier:id,code,name', 'cashAccount:id,code,name', 'lines.invoice:id,invoice_number,description',
                'journal:id,journal_number', 'journal.lines:id,journal_id,account_id,debit,credit,description', 'journal.lines.account:id,code,name',
                'voidJournal:id,journal_number',
            ]),
            'alat'     => BsApKuitansi::ALAT,
        ]);
    }

    public function void(Request $request, BsApKuitansi $kuitansi, ApKuitansiService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        try {
            $svc->void($kuitansi, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.ap.kuitansi.show', $kuitansi)->with('success', 'Kuitansi di-void — pembayaran dibatalkan & jurnal pembayaran dihapus dari General Ledger.');
    }

    /** Approval Pengeluaran Kas & Bank (AP): setujui kuitansi pending → jurnal JAP + payment + saldo invoice. */
    public function approve(Request $request, BsApKuitansi $kuitansi, ApKuitansiService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        try {
            $svc->approve($kuitansi, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Kuitansi {$kuitansi->kuitansi_number} disetujui — jurnal JAP otomatis terbentuk.");
    }

    /** Tolak kuitansi pending — tidak ada jurnal terbentuk. */
    public function reject(Request $request, BsApKuitansi $kuitansi, ApKuitansiService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        try {
            $svc->reject($kuitansi, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Kuitansi {$kuitansi->kuitansi_number} ditolak.");
    }

    /** Setujui beberapa kuitansi AP sekaligus (dari Approval Pengeluaran Kas & Bank). Lewati yang bukan pending. */
    public function approveBulk(Request $request, ApKuitansiService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $v = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:bs_ap_kuitansis,id'],
        ]);
        $uid = $request->user()->id;
        $ok = 0;
        $skip = 0;
        $fail = [];
        foreach (array_unique($v['ids']) as $id) {
            $k = BsApKuitansi::find($id);
            if (! $k || $k->status !== 'pending') {
                $skip++;
                continue;
            }
            try {
                $svc->approve($k, $uid);
                $ok++;
            } catch (BusinessRuleException $e) {
                $fail[] = $k->kuitansi_number;
            }
        }
        $msg = "$ok kuitansi disetujui.".($skip ? " $skip dilewati (bukan pending)." : '')
            .($fail ? ' Gagal posting jurnal: '.implode(', ', array_slice($fail, 0, 8)).'.' : '');

        return back()->with($ok ? 'success' : ($fail ? 'error' : 'warning'), $msg);
    }

    /** Tolak beberapa kuitansi AP sekaligus. Lewati yang bukan pending. */
    public function rejectBulk(Request $request, ApKuitansiService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $v = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:bs_ap_kuitansis,id'],
        ]);
        $uid = $request->user()->id;
        $ok = 0;
        $skip = 0;
        foreach (array_unique($v['ids']) as $id) {
            $k = BsApKuitansi::find($id);
            if (! $k || $k->status !== 'pending') {
                $skip++;
                continue;
            }
            try {
                $svc->reject($k, $uid);
                $ok++;
            } catch (BusinessRuleException $e) {
                // lewati yang gagal; ringkas di pesan
            }
        }
        $msg = "$ok kuitansi ditolak.".($skip ? " $skip dilewati (bukan pending)." : '');

        return back()->with($ok ? 'success' : 'warning', $msg);
    }
}
