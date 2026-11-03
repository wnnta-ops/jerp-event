<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Payable;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsApKuitansi;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Domain\BusinessSupport\Services\ApKuitansiService;
use App\Domain\BusinessSupport\Services\PembayaranMemoUrgent;
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

        // Invoice dari Upload Berkas yang approval-nya belum selesai / ditolak tidak ditawarkan —
        // servernya juga menolak (BsApInvoice::assertBolehDibayar); ini hanya merapikan pilihan.
        $tertahan = \App\Domain\Approval\Models\Approval::query()
            ->where('document_type', BsApInvoice::APPROVAL_DOCUMENT_TYPE)
            ->whereIn('status', ['pending', 'in_review', 'rejected'])
            ->pluck('approvable_id');

        $memoBayar = app(PembayaranMemoUrgent::class);
        $open = BsApInvoice::where('status', 'open')->whereNotIn('id', $tertahan)->with('supplier:id,code,name')->get()
            ->map(function ($i) use ($memoBayar) {
                // Uang muka Memo Urgent yang sudah dibayar — wajib dipotong saat invoice ini dibayar.
                $um = $i->memo_internal_id ? $memoBayar->uangMukaInvoice($i) : null;

                return [
                    'id' => $i->id, 'invoice_number' => $i->invoice_number, 'supplier_id' => $i->supplier_id,
                    'description' => $i->description, 'outstanding' => (float) $i->outstanding,
                    'due_date' => $i->due_date?->format('Y-m-d'),
                    'um' => $um ? ['kuitansi_number' => $um[0]->kuitansi_number, 'memo_number' => $um[0]->memo?->memo_number,
                        'nilai' => round(min($um[1], (float) $i->outstanding), 2)] : null,
                ];
            })->filter(fn ($i) => $i['outstanding'] > 0.009)->values();

        return Inertia::render('BusinessSupport/Payable/Kuitansi/Form', [
            // Hanya supplier yang PUNYA invoice open — pelunasan memang hanya untuk itu. Kode & jenisnya
            // ikut di label, supaya nama kembar (supplier lama vs penerima Reimburse/Deposit) terbedakan.
            'suppliers'    => BsSupplier::withTrashed()->whereIn('id', $open->pluck('supplier_id')->unique()->values())
                ->orderBy('name')->get(['id', 'code', 'name', 'category'])
                ->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name,
                    'label' => $s->name.' — '.$s->code.' · '.match ($s->category) { 'karyawan' => 'Karyawan', 'customer' => 'Customer', default => 'Supplier' }])
                ->values(),
            'openInvoices' => $open,
            'cashAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)->where('code', 'like', '101010%')->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'is_kas' => in_array($a->code, self::KAS_CODES, true)]),
            // defaultCash TIDAK dikirim: layar ini sengaja tidak punya kas/bank bawaan supaya
            // rekening sumbernya selalu dipilih sadar. Lihat komentar di Kuitansi/Form.vue.
            'alat'         => BsApKuitansi::ALAT,
            'kasir'        => $request->user()->name,
            // Bayar Memo Urgent (tanpa invoice): memo yang sudah disetujui Direktur & belum dibayar.
            'memoBayar'    => $memoBayar->bisaDibayar()->map(fn ($m) => [
                'id' => $m->id, 'number' => $m->memo_number, 'subject' => (string) $m->subject, 'date' => $m->memo_date?->toDateString(),
                'department' => $m->department, 'supplier' => $m->supplier?->name, 'amount' => (float) $m->amount,
                'status' => \App\Domain\ApprovalInbox\Services\MemoUntukInvoice::status($m),
                'url' => $m->approval_id ? route('approval-inbox.show', $m->approval_id) : null,
            ])->values(),
            'akunUangMuka' => $memoBayar->akunUangMuka(),
            'mode'         => $request->input('mode') === 'memo' ? 'memo' : 'invoice',
        ]);
    }

    /**
     * Rincian satu invoice untuk pop-up di form Pelunasan AP (diambil saat No. Invoice diklik):
     * Struktur Jurnal, ringkasan nominal & riwayat kuitansinya — termasuk yang masih menunggu
     * approval, supaya pelunasan sebagian tidak terbayar dobel. Hanya baca.
     */
    public function rincianInvoice(Request $request, BsApInvoice $invoice): \Illuminate\Http\JsonResponse
    {
        abort_unless($request->user()?->canAny(['ap.kuitansi.create', 'ap.invoice.show']), 403);
        $invoice->load('category:id,code,name');

        $baris = $invoice->journal_id
            ? \App\Domain\BusinessSupport\Models\BsJournalLine::with(['account:id,code,name', 'costCenter:id,code'])
                ->where('journal_id', $invoice->journal_id)->orderBy('id')->get()
            : collect();
        $akunHutang = BsApInvoice::akunHutangDariJurnal(
            $baris->map(fn ($l) => ['account_id' => $l->account_id, 'credit' => $l->credit]),
            (int) $invoice->payable_account_id,
        );
        $statusJurnal = $invoice->journal_id
            ? \App\Domain\BusinessSupport\Models\BsJournal::whereKey($invoice->journal_id)->value('status') : null;

        $riwayat = \App\Domain\BusinessSupport\Models\BsApKuitansiLine::query()
            ->join('bs_ap_kuitansis as k', 'k.id', '=', 'bs_ap_kuitansi_lines.kuitansi_id')
            ->where('bs_ap_kuitansi_lines.ap_invoice_id', $invoice->id)
            ->orderBy('k.kuitansi_date')->orderBy('k.id')
            ->get(['k.id', 'k.kuitansi_number', 'k.kuitansi_date', 'k.status', 'bs_ap_kuitansi_lines.amount']);

        return response()->json([
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'kategori' => $invoice->category ? trim($invoice->category->code.' — '.$invoice->category->name) : null,
            'description' => $invoice->description,
            'total' => (float) $invoice->total,
            'pph' => (float) $invoice->pph_amount,
            'nett' => (float) $invoice->payable_amount,
            'dibayar' => (float) $invoice->paid_amount,
            'sisa' => (float) $invoice->outstanding,
            'status_jurnal' => ['draft' => 'Baru', 'final' => 'Final', 'posted' => 'Approved'][$statusJurnal] ?? null,
            'jurnal' => $baris->map(fn ($l) => [
                'akun' => trim(($l->account?->code ?? '').' '.($l->account?->name ?? '')),
                'cc' => $l->costCenter?->code,
                'debit' => (float) $l->debit, 'credit' => (float) $l->credit,
                'hutang' => (float) $l->credit > 0 && (int) $l->account_id === (int) $akunHutang,
            ])->values(),
            'riwayat' => $riwayat->map(fn ($r) => [
                'nomor' => $r->kuitansi_number,
                'tanggal' => $r->kuitansi_date ? substr((string) $r->kuitansi_date, 0, 10) : null,
                'status' => $r->status, 'amount' => (float) $r->amount,
                'url' => route('bs.ap.kuitansi.show', $r->id),
            ])->values(),
            'url' => route('bs.ap.show', $invoice->id),
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

        if ($galat = $this->galatAlatKas($v)) {
            return back()->withInput()->with('error', $galat);
        }

        try {
            $kui = $svc->create($v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.ap.kuitansi.show', $kui)->with('success', "Kuitansi {$kui->kuitansi_number} dibuat — menunggu persetujuan di Approval Pengeluaran Kas & Bank.");
    }

    /**
     * Bayar Memo Urgent tanpa invoice — kuitansi langsung aktif (memo sudah disetujui Direktur),
     * jurnal Dr Uang Muka / Cr Kas/Bank. Invoice hutangnya wajib menyusul.
     */
    public function storeMemo(Request $request, PembayaranMemoUrgent $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('ap.kuitansi.store'), 403);

        $v = $request->validate([
            'memo_internal_id'   => ['required', 'integer', 'exists:memo_internals,id'],
            'kuitansi_date'      => ['required', 'date'],
            'cash_account_id'    => ['required', 'integer', 'exists:bs_accounts,id'],
            'advance_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            'amount'             => ['required', 'numeric', 'min:1'],
            'alat_transaksi'     => ['required', 'in:tunai,transfer,giro'],
            'manual_no'          => ['nullable', 'string', 'max:80'],
            'bg_number'          => ['nullable', 'string', 'max:80', 'required_if:alat_transaksi,giro'],
            'description'        => ['nullable', 'string', 'max:255'],
        ], [
            'memo_internal_id.required' => 'Pilih Memo Urgent yang dibayar.',
            'advance_account_id.required' => 'Pilih akun uang muka.',
            'bg_number.required_if' => 'Nomor BG/Cek wajib diisi untuk alat transaksi Cek/Giro.',
        ]);
        $v['kasir'] = $request->user()->name;
        if ($galat = $this->galatAlatKas($v)) {
            return back()->withInput()->with('error', $galat);
        }

        try {
            $kui = $svc->bayar($v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.ap.kuitansi.show', $kui)
            ->with('success', "Kuitansi {$kui->kuitansi_number} untuk Memo Urgent tersimpan & jurnal terbentuk. Invoice hutangnya wajib menyusul.");
    }

    /** Daftar Memo Urgent yang sudah dibayar tapi invoice hutangnya belum masuk (umur hari + lewat batas). */
    public function memoOutstanding(Request $request, PembayaranMemoUrgent $svc): Response
    {
        abort_unless($request->user()?->canAny(['ap.kuitansi.create', 'ap.kuitansi.show']), 403);

        return Inertia::render('BusinessSupport/Payable/Kuitansi/MemoOutstanding', [
            'rows' => $svc->outstanding(),
            'batasHari' => $svc->batasHari(),
        ]);
    }

    /** #50: rekening kas/bank harus sesuai alat transaksi. Null = cocok. */
    private function galatAlatKas(array $v): ?string
    {
        $code  = (string) BsAccount::where('id', $v['cash_account_id'])->value('code');
        $isKas = in_array($code, self::KAS_CODES, true);
        if ($v['alat_transaksi'] === 'tunai' && ! $isKas) {
            return 'Alat transaksi Tunai hanya boleh dari Kas Operasional atau Kas Kecil.';
        }
        if (in_array($v['alat_transaksi'], ['transfer', 'giro'], true) && $isKas) {
            return 'Alat transaksi Transfer / Cek-Giro harus dari rekening bank (bukan Kas Operasional/Kas Kecil).';
        }

        return null;
    }

    /** Cetak kuitansi (HTML siap-cetak). Kuitansi Memo Urgent: no. memo + TTD Direktur & MW / BA otomatis. */
    public function print(Request $request, BsApKuitansi $kuitansi, PembayaranMemoUrgent $svc): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('ap.kuitansi.show'), 403);
        $kuitansi->load(['supplier:id,code,name', 'cashAccount:id,code,name', 'advanceAccount:id,code,name', 'lines.invoice:id,invoice_number,description,memo_internal_id',
            'lines.invoice.memo:id,memo_number', 'memo.approval.steps', 'journal:id,journal_number']);

        return response(view('pdf.kuitansi-hutang', [
            'k' => $kuitansi,
            'alat' => BsApKuitansi::ALAT,
            'ttd' => $kuitansi->memo_internal_id ? $svc->kotakTtd($kuitansi) : null,
            'dicetakOleh' => $request->user()?->name,
        ])->render());
    }

    public function show(Request $request, BsApKuitansi $kuitansi): Response
    {
        abort_unless($request->user()?->can('ap.kuitansi.show'), 403);

        return Inertia::render('BusinessSupport/Payable/Kuitansi/Show', [
            'kuitansi' => $kuitansi->load([
                'supplier:id,code,name', 'cashAccount:id,code,name', 'lines.invoice:id,invoice_number,description,memo_internal_id',
                'journal:id,journal_number', 'journal.lines:id,journal_id,account_id,debit,credit,description', 'journal.lines.account:id,code,name',
                'voidJournal:id,journal_number', 'advanceAccount:id,code,name', 'lines.invoice.memo:id,memo_number',
                'memo:id,memo_number,subject,amount,approval_id', 'memo.approval.steps',
            ]),
            'alat'     => BsApKuitansi::ALAT,
            // Kuitansi Memo Urgent: TTD Kasir / Direktur / MW / BA (digital, otomatis setelah disetujui) + invoice penyusulnya.
            'ttd'      => $kuitansi->memo_internal_id ? collect(app(PembayaranMemoUrgent::class)->kotakTtd($kuitansi))->map(fn ($t) => array_diff_key($t, ['img' => 1]))->all() : null,
            'invoiceMemo' => $kuitansi->memo_internal_id
                ? BsApInvoice::where('memo_internal_id', $kuitansi->memo_internal_id)->where('status', '!=', BsApInvoice::STATUS_BATAL)->first(['id', 'invoice_number'])
                : null,
        ]);
    }

    public function void(Request $request, BsApKuitansi $kuitansi, ApKuitansiService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['ap.kuitansi.void', 'businesssupport.approval.act']), 403);

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
        abort_unless($request->user()?->canAny(['ap.kuitansi.approve', 'cashbook.transaction.approve', 'businesssupport.approval.act']), 403);

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
        abort_unless($request->user()?->canAny(['ap.kuitansi.reject', 'cashbook.transaction.approve', 'cashbook.transaction.reject', 'businesssupport.approval.act']), 403);

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
        abort_unless($request->user()?->canAny(['ap.kuitansi.approve', 'cashbook.transaction.approve', 'businesssupport.approval.act']), 403);
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
        abort_unless($request->user()?->canAny(['ap.kuitansi.reject', 'cashbook.transaction.approve', 'cashbook.transaction.reject', 'businesssupport.approval.act']), 403);
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
