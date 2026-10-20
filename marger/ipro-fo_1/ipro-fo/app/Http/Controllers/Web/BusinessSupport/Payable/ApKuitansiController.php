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
        abort_unless($request->user()?->can('ap.kuitansi.view'), 403);

        // DISARING & DIHALAMANKAN DI SERVER (25 baris). Dulu semua kuitansi (ribuan) dikirim ke
        // browser dan disaring di sana. Nilai saringan tetap bentuk tampilan layar ("20 Okt 2026",
        // "Rp 1.300.000", "Aktif"); tiap kolom punya ekspresi SQL yang menghasilkan bentuk itu.
        // Ringkasan dihitung atas SELURUH hasil saringan.
        $T = 'bs_ap_kuitansis';
        $bulan = "'Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'";
        $eks = [
            'no' => "COALESCE($T.kuitansi_number,'')",
            'tgl' => "COALESCE(CONCAT(LPAD(DAY($T.kuitansi_date),2,'0'),' ',ELT(MONTH($T.kuitansi_date),$bulan),' ',YEAR($T.kuitansi_date)),'—')",
            'supplier' => "COALESCE(NULLIF(s.name,''),'—')",
            'alat' => "CASE $T.alat_transaksi WHEN 'tunai' THEN 'Tunai' WHEN 'transfer' THEN 'Transfer' WHEN 'giro' THEN 'Cek/Giro' ELSE COALESCE($T.alat_transaksi,'—') END",
            'total' => "ROUND($T.total,2)",
            'status' => "CASE $T.status WHEN 'pending' THEN 'Menunggu' WHEN 'active' THEN 'Aktif' WHEN 'rejected' THEN 'Ditolak' WHEN 'void' THEN 'Void' ELSE COALESCE($T.status,'') END",
        ];
        $dasar = fn () => BsApKuitansi::query()->leftJoin('bs_suppliers as s', 's.id', '=', "$T.supplier_id");

        $q = $dasar();
        if (($cari = trim((string) $request->input('search'))) !== '') {
            $q->where(function ($w) use ($eks, $cari) {
                foreach ($eks as $k => $e) {
                    if ($k === 'total') {
                        $d = preg_replace('/\D/', '', $cari);
                        if ($d !== '') {
                            $w->orWhereRaw("CAST(ROUND($e) AS CHAR) LIKE ?", ['%'.$d.'%']);
                        }

                        continue;
                    }
                    $w->orWhereRaw("LOWER($e) LIKE ?", ['%'.mb_strtolower($cari).'%']);
                }
            });
        }
        foreach ($eks as $k => $e) {
            $nilai = array_values(array_filter((array) $request->input("c.$k", []), fn ($v) => $v !== null));
            if (! $nilai) {
                continue;
            }
            if ($k === 'total') {
                $angka = array_values(array_filter(array_map([\App\Domain\BusinessSupport\Support\DaftarInvoiceHutang::class, 'angkaDariTampilan'], $nilai), fn ($v) => $v !== null));
                $q->where(function ($w) use ($e, $angka) {
                    $w->whereRaw('1=0');
                    foreach ($angka as $x) {
                        $w->orWhereRaw("$e = ?", [$x]);
                    }
                });
            } else {
                $q->whereIn(\Illuminate\Support\Facades\DB::raw($e), array_map('strval', $nilai));
            }
        }

        $ringkas = (clone $q)->toBase()->selectRaw("COUNT(*) AS n, COALESCE(SUM(CASE WHEN $T.status='active' THEN $T.total END),0) AS aktif")->first();

        $rows = (clone $q)->select(["$T.id", "$T.kuitansi_number", "$T.kuitansi_date", "$T.supplier_id", "$T.alat_transaksi", "$T.total", "$T.status", \Illuminate\Support\Facades\DB::raw('s.name AS nama_supplier')])
            ->orderByDesc("$T.kuitansi_date")->orderByDesc("$T.id")
            ->paginate(25)->withQueryString()
            ->through(fn ($k) => [
                'id'              => $k->id,
                'kuitansi_number' => $k->kuitansi_number,
                'kuitansi_date'   => $k->kuitansi_date?->toDateString(),
                'supplier'        => $k->nama_supplier !== null ? ['name' => $k->nama_supplier] : null,
                'alat_transaksi'  => $k->alat_transaksi,
                'total'           => (float) $k->total,
                'status'          => $k->status,
            ]);

        // Isi dropdown per kolom — dari SELURUH kuitansi, lepas dari saringan lain.
        $opsi = [];
        foreach ($eks as $k => $e) {
            $v = $dasar()->toBase()->selectRaw("$e AS v")->groupByRaw('v')
                ->orderByRaw($k === 'tgl' ? "MAX($T.kuitansi_date) DESC" : 'v')->limit(1000)->pluck('v');
            $opsi[$k] = $k === 'total'
                ? $v->map(fn ($x) => \App\Domain\BusinessSupport\Support\DaftarInvoiceHutang::rp((float) $x))->values()->all()
                : $v->map(fn ($x) => (string) $x)->values()->all();
        }

        return Inertia::render('BusinessSupport/Payable/Kuitansi/Index', [
            'kuitansis' => $rows,
            'summary' => ['active' => round((float) $ringkas->aktif, 2), 'count' => (int) $ringkas->n],
            'jumlahSemua' => (int) $dasar()->count(),
            'opsi' => $opsi,
            'filters' => ['search' => (string) $request->input('search', ''), 'c' => (array) $request->input('c', [])],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('ap.kuitansi.create'), 403);

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
            // defaultCash TIDAK dikirim: layar ini sengaja tidak punya kas/bank bawaan supaya
            // rekening sumbernya selalu dipilih sadar. Lihat komentar di Kuitansi/Form.vue.
            'alat'         => BsApKuitansi::ALAT,
            'kasir'        => $request->user()->name,
        ]);
    }

    public function store(Request $request, ApKuitansiService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('ap.kuitansi.store'), 403);

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
        abort_unless($request->user()?->can('ap.kuitansi.show'), 403);

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
