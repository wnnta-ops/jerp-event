<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Payable;

use App\Domain\BusinessSupport\Actions\PayApInvoice;
use App\Domain\BusinessSupport\Actions\RegisterApInvoice;
use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApCategory;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsApInvoiceDraft;
use App\Domain\BusinessSupport\Models\BsApPayment;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsDepartment;
use App\Domain\BusinessSupport\Models\BsLedger;
use App\Domain\BusinessSupport\Models\BsLocation;
use App\Domain\BusinessSupport\Models\BsSip;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Domain\BusinessSupport\Services\CostCenterPengguna;
use App\Domain\BusinessSupport\Support\PosisiHutang;
use App\Domain\Master\Unit\Models\Unit;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\GalatAnggaran;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ApInvoiceController extends Controller
{
    use GalatAnggaran;

    public function index(Request $request): Response|\Illuminate\Http\Response
    {
        return $this->daftar($request, 'invoice');
    }

    /**
     * Daftar Reimburse Biaya Karyawan / Deposit Renovasi Customer — layar yang sama dengan
     * Invoice Hutang, disaring per jenis. Rutenya mengirim `jenis` lewat ->defaults().
     */
    public function indexJenis(Request $request, string $jenis): Response|\Illuminate\Http\Response
    {
        return $this->daftar($request, $jenis);
    }

    public function createJenis(Request $request, string $jenis): Response
    {
        return $this->halamanForm($request, $jenis);
    }

    public function storeJenis(Request $request, string $jenis, RegisterApInvoice $action, \App\Domain\BusinessSupport\Services\BudgetGuardService $budgetGuard): \Symfony\Component\HttpFoundation\Response
    {
        return $this->simpan($request, $jenis, $action, $budgetGuard);
    }

    /**
     * Pemegang izin modul Reimburse / Deposit boleh membuka detail & mencetak Memo dokumennya
     * sendiri — tanpa ini ia menabrak 403 tepat sesudah menyimpan (simpan → cetak Memo).
     * Tidak berlaku untuk Invoice Hutang biasa: izinnya tetap ap.invoice.show / ap.memo.
     */
    private function bolehModulJenis(Request $request, BsApInvoice $invoice): bool
    {
        $jenis = (string) ($invoice->jenis ?: 'invoice');
        if ($jenis === 'invoice' || ! isset(BsApInvoice::JENIS[$jenis])) {
            return false;
        }
        $izin = BsApInvoice::JENIS[$jenis]['izin'];

        return (bool) ($request->user()?->can($izin.'.view') || $request->user()?->can($izin.'.create'));
    }

    /** Definisi jenis + kuncinya, untuk dikirim ke layar. @return array<string,string> */
    private function infoJenis(string $jenis): array
    {
        abort_unless(isset(BsApInvoice::JENIS[$jenis]), 404);

        return ['kunci' => $jenis] + BsApInvoice::JENIS[$jenis];
    }

    private function daftar(Request $request, string $jenis): Response|\Illuminate\Http\Response
    {
        $info = $this->infoJenis($jenis);
        abort_unless($request->user()?->can($info['izin'].'.view'), 403);

        // DISARING & DIHALAMANKAN DI SERVER (25 baris) — lihat DaftarInvoiceHutang. Dulu seluruh
        // invoice dikirim ke browser dan disaring di sana; ribuan baris membuat halamannya lambat.
        // Total & Sisa = nett (setelah PPN & PPh). Invoice BATAL ter-soft-delete tetapi tetap
        // tampil berlabel BATAL supaya nomornya bisa ditelusuri.
        $daftar = new \App\Domain\BusinessSupport\Support\DaftarInvoiceHutang($jenis);
        $q = $daftar->saring($daftar->dasar(), $request);

        // Status Memo Urgent yang ditarik ke invoice — dimuat per memo yang muncul (≤ 25 per halaman).
        $memo = [];
        $memoDari = function (?int $id) use (&$memo): ?array {
            if (! $id) {
                return null;
            }
            if (! array_key_exists($id, $memo)) {
                $m = \App\Domain\ApprovalInbox\Models\MemoInternal::withTrashed()->with('approval.steps')->find($id);
                $memo[$id] = $m ? ['number' => $m->memo_number, 'status' => \App\Domain\ApprovalInbox\Services\MemoUntukInvoice::status($m),
                    'url' => $m->approval_id ? route('approval-inbox.show', $m->approval_id) : null] : null;
            }

            return $memo[$id];
        };
        $baris = fn ($r) => [
            'id' => $r->id,
            'memo' => $memoDari($r->memo_internal_id ? (int) $r->memo_internal_id : null),
            'invoice_number' => $r->invoice_number,
            'invoice_date' => $r->invoice_date?->toDateString(),
            'receive_date' => $r->receive_date?->toDateString(),
            'due_date' => $r->due_date?->toDateString(),
            'supplier' => $r->nama_supplier !== null ? ['name' => $r->nama_supplier] : null,
            'customer' => $r->nama_customer !== null ? ['name' => $r->nama_customer] : null, // refund: lawan = customer
            'source_type' => $r->source_type,    // po / spk / refund / null(manual)
            // id PO asal (hanya bila source_type='po') — dipakai tombol "Cetak Purchase Order".
            'po_id' => $r->source_type === 'po' ? $r->source_id : null,
            'refund_source' => $r->refund_source,  // SPR / SPRS / TRANSFER_ANONIM
            'description' => $r->description,
            'berkas_notes' => $r->berkas_notes,
            'berkas_finance_date' => $r->berkas_finance_date?->toDateString(),
            'status' => $r->status,
            'status_jurnal' => (string) ($r->status_jurnal ?? '—'),   // Baru / Final / Approved / Closed
            'journal_id' => $r->journal_id,
            'payable' => round($r->payable_amount, 2),   // nett dibayar ke supplier (total − PPh dipotong)
            'sisa' => $r->status === BsApInvoice::STATUS_BATAL ? 0 : round($r->outstanding, 2), // nett − terbayar
        ];
        $urut = fn ($x) => $x->orderByDesc('bs_ap_invoices.invoice_date')->orderByDesc('bs_ap_invoices.id');

        // Excel = SELURUH hasil saringan (bukan hanya halaman yang tampil).
        if ($request->boolean('excel')) {
            return $this->excelDaftar($urut((clone $q)->select(\App\Domain\BusinessSupport\Support\DaftarInvoiceHutang::kolomBaris()))->get()->map($baris), $info);
        }

        $rows = $urut((clone $q)->select(\App\Domain\BusinessSupport\Support\DaftarInvoiceHutang::kolomBaris()))
            ->paginate(\App\Domain\BusinessSupport\Support\DaftarInvoiceHutang::PER_HALAMAN)->withQueryString()
            ->through($baris);

        return Inertia::render('BusinessSupport/Payable/Index', [
            'jenis' => $info,
            'invoices' => $rows,
            'summary' => $daftar->ringkasan($q),
            'jumlahSemua' => (int) $daftar->dasar()->count(),
            'opsi' => $daftar->opsi(),
            'batasOpsi' => \App\Domain\BusinessSupport\Support\DaftarInvoiceHutang::BATAS_OPSI,
            'filters' => [
                'search' => (string) $request->input('search', ''),
                't' => (array) $request->input('t', []),
                'c' => (array) $request->input('c', []),
            ],
            // Hanya Finance yang boleh mengisi tanggal berkas diterima Finance.
            'canBerkasFinance' => $this->isFinance($request),
            // Invoice yang menunggu berkas supplier (link) — nomor sudah dipesan, belum aktif. Titik = berkas sudah masuk.
            'menungguSupplier' => $jenis === 'invoice'
                ? app(\App\Domain\BusinessSupport\Services\LinkBerkasSupplier::class)->aktifUntuk($request->user())->map(fn ($l) => [
                    'id' => $l->id, 'invoice_number' => $l->invoice_number, 'supplier' => $l->supplier?->name, 'status' => $l->status,
                    'baru' => $l->status === 'terisi' && ! $l->seen_at, 'berkas_number' => $l->berkas?->berkas_number,
                    'description' => $l->data['description'] ?? null, 'url' => $l->url(), 'created_at' => $l->created_at?->toDateString(),
                ])->values()
                : [],
        ]);
    }

    /** Excel daftar invoice — kolom sama dengan unduhan layar sebelumnya (nilai nett). */
    private function excelDaftar(\Illuminate\Support\Collection $rows, array $info): \Illuminate\Http\Response
    {
        $e = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $tgl = fn (?string $d) => $d ? \Illuminate\Support\Carbon::parse($d)->translatedFormat('d M Y') : '—';
        $num = fn ($v) => number_format((float) $v, 0, ',', '.');
        $status = fn (string $s) => $s === 'paid' ? 'Lunas' : ($s === 'batal' ? 'Batal' : 'Open');
        $body = '';
        foreach ($rows as $r) {
            $body .= '<tr><td>'.$e($r['invoice_number']).'</td><td>'.$e($tgl($r['invoice_date'])).'</td><td>'.$e($tgl($r['receive_date'])).'</td>'
                .'<td>'.$e($tgl($r['due_date'])).'</td><td>'.$e($r['supplier']['name'] ?? $r['customer']['name'] ?? '').'</td><td>'.$e($r['description']).'</td>'
                .'<td>'.$e($num($r['payable'])).'</td><td>'.$e($num($r['sisa'])).'</td><td>'.$e($status($r['status'])).'</td>'
                .'<td>'.$e($r['status_jurnal'] ?? '—').'</td><td>'.$e($r['berkas_notes']).'</td><td>'.$e($tgl($r['berkas_finance_date'])).'</td></tr>';
        }
        $html = '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'
            .'<table border="1"><tr style="background:#0f766e;color:#fff">'
            .'<th>No. Invoice</th><th>Tanggal</th><th>Tgl. Diterima</th><th>Jatuh Tempo</th><th>Supplier</th><th>Keterangan</th><th>Total (Net)</th><th>Sisa (Net)</th><th>Status</th><th>Status Jurnal</th><th>Kelengkapan Berkas</th><th>Berkas Diterima Finance</th>'
            .'</tr>'.$body.'</table></body></html>';
        $nama = str_replace(' ', '-', (string) ($info['judul'] ?? 'Invoice-Hutang')).'-'.now()->toDateString().'.xls';

        return response($html, 200, [
            'Content-Type' => 'application/vnd.ms-excel',
            'Content-Disposition' => 'attachment; filename="'.$nama.'"',
        ]);
    }

    /** Hub Account Payable — direktori sub-modul + ringkasan hutang. */
    public function hub(Request $request): Response
    {
        abort_unless($request->user()?->can('ap.printhub'), 403);

        $openTotal = (float) BsApInvoice::where('status', 'open')->sum('total');
        $openPaid = (float) BsApInvoice::where('status', 'open')->sum('paid_amount');
        $overdueQ = BsApInvoice::where('status', 'open')->whereNotNull('due_date')->whereDate('due_date', '<', now()->toDateString());
        $dueSoonQ = BsApInvoice::where('status', 'open')->whereNotNull('due_date')->whereDate('due_date', '<=', now()->addDays(30)->toDateString());

        return Inertia::render('BusinessSupport/Payable/Hub', [
            'summary' => [
                'outstanding' => round($openTotal - $openPaid, 2),
                'overdue' => round((float) (clone $overdueQ)->sum('total') - (float) (clone $overdueQ)->sum('paid_amount'), 2),
                'due_soon' => round((float) (clone $dueSoonQ)->sum('total') - (float) (clone $dueSoonQ)->sum('paid_amount'), 2),
                'count_open' => BsApInvoice::where('status', 'open')->count(),
                'count_total' => BsApInvoice::count(),
                'suppliers' => BsSupplier::count(),
            ],
        ]);
    }

    /** Print-Out — hub cetak laporan hutang. */
    /** Landing per sub-modul AP (grup BO) — detail item muncul di halaman ini. */
    public function section(Request $request, string $section): Response
    {
        abort_unless($request->user()?->can('ap.invoice.view'), 403);
        $titles = [
            'general' => 'General Function',
            'setup' => 'Setup',
            'master' => 'Master',
            'query' => 'Query',
            'generate' => 'Generate Invoice',
            'penyesuaian' => 'Penyesuaian Invoice',
            // Kunci 'posting-po' dipertahankan (dipakai URL/section), hanya judulnya yang berubah.
            'posting-po' => 'Posting Invoice dari FO',
            'posting-spk' => 'Posting Invoice SPK',
            'saldoawal' => 'Saldo Awal',
        ];
        abort_unless(isset($titles[$section]), 404);

        return Inertia::render('BusinessSupport/Payable/Section', [
            'section' => $section,
            'title' => $titles[$section],
        ]);
    }

    /** Export daftar AP ke Excel (.xls) — mengikuti filter status/search. */
    public function export(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('ap.invoice.view'), 403);

        $rows = PosisiHutang::saring(BsApInvoice::query()->with('supplier:id,code,name'), PosisiHutang::dariRequest($request))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('supplier_id'), fn ($q, $v) => $q->where('supplier_id', $v))   // #135
            ->when($request->input('date_from'), fn ($q, $v) => $q->whereDate('invoice_date', '>=', $v)) // periode laporan
            ->when($request->input('date_to'), fn ($q, $v) => $q->whereDate('invoice_date', '<=', $v))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('invoice_number', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")))
            ->latest('invoice_date')->latest('id')->get();

        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
        $today = \Illuminate\Support\Carbon::today();
        $h = '<table border="1"><tr style="background:#0f766e;color:#fff"><th>No Invoice</th><th>Tanggal</th><th>Tgl Diterima</th><th>Berkas Diterima Finance</th><th>Jatuh Tempo</th><th>Aging Hutang</th><th>Aging Jatuh Tempo</th><th>Supplier</th><th>Keterangan</th><th>Status</th><th>Total Invoice</th><th>Hutang (jurnal)</th><th>Terbayar</th><th>Sisa</th></tr>';
        // Hutang = kredit akun Hutang Supplier/BYMHD di jurnal (sesudah PPh); Sisa = Hutang − Terbayar.
        $hutang = BsApInvoice::nilaiHutangJurnal($rows);
        $jml = ['total' => 0.0, 'hutang' => 0.0, 'paid' => 0.0, 'sisa' => 0.0];
        foreach ($rows as $r) {
            $sisa = $hutang[$r->id] - (float) $r->paid_amount;
            $jml['total'] += (float) $r->total;
            $jml['hutang'] += $hutang[$r->id];
            $jml['paid'] += (float) $r->paid_amount;
            $jml['sisa'] += $sisa;
            // Aging Hutang = jumlah hari sejak tanggal diterima (receive_date) sampai hari ini (>=0).
            $agingHutang = $r->receive_date
                ? (string) (int) $r->receive_date->copy()->startOfDay()->diffInDays($today, false)
                : '-';
            // Aging Jatuh Tempo = hari lewat jatuh tempo = hari ini − due_date (positif = telat).
            $agingJt = $r->due_date
                ? (string) (int) $r->due_date->copy()->startOfDay()->diffInDays($today, false)
                : '-';
            $h .= '<tr><td>'.$e($r->invoice_number).'</td><td>'.$e($r->invoice_date?->toDateString()).'</td><td>'.$e($r->receive_date?->toDateString())
                .'</td><td>'.$e($r->berkas_finance_date?->toDateString()).'</td><td>'.$e($r->due_date?->toDateString()).'</td><td>'.$e($agingHutang).'</td><td>'.$e($agingJt).'</td><td>'.$e($r->supplier?->code).' '.$e($r->supplier?->name).'</td><td>'.$e($r->description).'</td><td>'.$e($r->status).'</td><td>'.$rp($r->total).'</td><td>'.$rp($hutang[$r->id]).'</td><td>'.$rp($r->paid_amount).'</td><td>'.$rp($sisa).'</td></tr>';
        }
        $h .= '<tr style="font-weight:bold;background:#e2e8f0"><td colspan="10">TOTAL ('.count($rows).' invoice)</td><td>'.$rp($jml['total']).'</td><td>'.$rp($jml['hutang']).'</td><td>'.$rp($jml['paid']).'</td><td>'.$rp($jml['sisa']).'</td></tr>';
        $h .= '</table>';

        $file = 'Account-Payable-'.now()->format('Ymd-His').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'.$h.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /** Laporan Invoice Hutang — pilih periode tanggal & supplier dulu, tarik data (preview), lalu unduh .xls. */
    public function report(Request $request): Response
    {
        abort_unless($request->user()?->can('ap.invoice.view'), 403);

        $dateFrom = $request->input('date_from') ?: null;
        $dateTo = $request->input('date_to') ?: null;
        $supplierId = $request->integer('supplier_id') ?: null;
        $pulled = $request->boolean('pull'); // data hanya ditarik setelah user pilih periode & klik Tampilkan

        $rows = collect();
        if ($pulled) {
            $today = \Illuminate\Support\Carbon::today();
            $inv = PosisiHutang::saring(BsApInvoice::with('supplier:id,code,name'), PosisiHutang::dariRequest($request))
                ->when($dateFrom, fn ($q, $v) => $q->whereDate('invoice_date', '>=', $v))
                ->when($dateTo, fn ($q, $v) => $q->whereDate('invoice_date', '<=', $v))
                ->when($supplierId, fn ($q, $v) => $q->where('supplier_id', $v))
                ->latest('invoice_date')->latest('id')->get();
            // Hutang = kredit akun Hutang Supplier/BYMHD di jurnal (sesudah PPh) — bukan total bruto.
            $hutang = BsApInvoice::nilaiHutangJurnal($inv);
            $rows = $inv->map(fn ($r) => [
                    'invoice_no' => $r->invoice_number,
                    'invoice_date' => $r->invoice_date?->toDateString(),
                    'receive_date' => $r->receive_date?->toDateString(),
                    'due_date' => $r->due_date?->toDateString(),
                    'aging_hutang' => $r->receive_date ? (int) $r->receive_date->copy()->startOfDay()->diffInDays($today, false) : null,
                    'aging_jt' => $r->due_date ? (int) $r->due_date->copy()->startOfDay()->diffInDays($today, false) : null,
                    'supplier' => trim(($r->supplier?->code ?? '').' '.($r->supplier?->name ?? '')),
                    'description' => $r->description,
                    'status' => $r->status,
                    'berkas_finance_date' => $r->berkas_finance_date?->toDateString(),
                    'total' => round((float) $r->total, 2),
                    'hutang' => $hutang[$r->id],
                    'paid' => round((float) $r->paid_amount, 2),
                    'sisa' => round($hutang[$r->id] - (float) $r->paid_amount, 2),
                ])->values();
        }

        return Inertia::render('BusinessSupport/Payable/InvoiceReport', [
            'rows' => $rows,
            'suppliers' => BsSupplier::orderBy('name')->get(['id', 'code', 'name']),
            'filters' => ['date_from' => $dateFrom, 'date_to' => $dateTo, 'supplier_id' => $supplierId, 'posisi' => PosisiHutang::dariRequest($request)],
            'posisiOpsi' => PosisiHutang::opsi(),
            'pulled' => $pulled,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->halamanForm($request, 'invoice');
    }

    private function halamanForm(Request $request, string $jenis, ?BsApInvoice $edit = null): Response
    {
        $info = $this->infoJenis($jenis);
        abort_unless($edit !== null || $request->user()?->can($info['izin'].'.create'), 403);

        // Unit + customer (dari booking terbaru per unit) untuk tag invoice — cari cepat by kode unit / nama customer.
        $unitCustomer = DB::table('bookings')
            ->join('customers', 'bookings.customer_id', '=', 'customers.id')
            ->whereNull('bookings.deleted_at')
            ->orderByDesc('bookings.id')
            ->get(['bookings.unit_id', 'bookings.customer_id', 'customers.full_name'])
            ->unique('unit_id')->keyBy('unit_id');
        $units = Unit::orderBy('unit_code')->get(['id', 'unit_code'])->map(fn ($u) => [
            'id' => $u->id, 'code' => $u->unit_code, 'name' => $unitCustomer[$u->id]->full_name ?? null,
            'customer_id' => $unitCustomer[$u->id]->customer_id ?? null, 'customer_name' => $unitCustomer[$u->id]->full_name ?? null,
        ]);

        // #113: akun PPh + default akun per jenis PPh (dipetakan by kode COA) — auto-isi di form, tetap bisa diubah.
        $pphAccounts = BsAccount::where('is_active', true)->where('is_postable', true)
            ->where('code', 'like', '2010404%')->orderBy('code')->get(['id', 'code', 'name']);

        // ?berkas={id} dari Upload Berkas → isian awal diambil dari berkas (hanya yang siap, dan
        // hanya unggahan user itu sendiri — invoice hutang wajib memakai berkas milik pengaju).
        $dariBerkas = null;
        if ($edit === null && $jenis === 'invoice' && $request->filled('berkas')) {
            $b = \App\Domain\BusinessSupport\Models\BsApBerkasUpload::with('files.jenis:id,name')->find((int) $request->input('berkas'));
            if ($b && $b->bisaDiinvoice() && (int) $b->uploaded_by === (int) $request->user()->id) {
                // Invoice pertama: nilai & PPN sesuai berkas. Berikutnya (sebagian diinvoice):
                // sisanya, tanpa PPN tambahan — tim AP menyesuaikan bila perlu.
                $invoiced = $b->invoicedTotal();
                $pertama = $invoiced <= 0.01;
                $net = $b->subtotal !== null ? max(0.0, (float) $b->subtotal - (float) $b->discount) : null;
                $dariBerkas = [
                    'id' => $b->id,
                    'number' => $b->berkas_number,
                    'supplier_id' => $b->supplier_id,
                    'supplier_invoice_no' => $b->supplier_invoice_no,
                    'amount' => $b->amount !== null ? (float) $b->amount : null,
                    'description' => $b->description,
                    'invoice_date' => $b->document_date?->toDateString(),
                    'dpp' => $pertama ? ($net ?? ($b->amount !== null ? (float) $b->amount : null)) : $b->sisa(),
                    'ppn_mode' => $pertama && (float) $b->ppn_pct > 0 ? 'with' : 'none',
                    'ppn_pct' => (float) $b->ppn_pct,
                    'ppn_incl' => $pertama && (bool) $b->tax_inclusive,
                    'cost_center_id' => $b->cost_center_id,
                    'invoiced' => $invoiced,
                    'sisa' => $b->sisa(),
                    'files' => $b->files->map(fn ($f) => [
                        'name' => $f->original_name,
                        'jenis' => $f->jenis?->name,
                        'url' => route('bs.ap.berkas.file', [$b->id, $f->id]),
                    ]),
                ];
            }
        }

        // REGISTRASI INVOICE HUTANG WAJIB MEMILIH BERKAS (langkah Detil Tagihan) — pilihannya hanya
        // berkas yang diunggah user itu sendiri lewat Upload Berkas. Draft = isian yang disimpan
        // saat user menekan "Edit" berkas; dipulihkan di sini (kecuali dibuka dari ?berkas=).
        $wajibBerkas = $edit === null && $jenis === 'invoice';
        $draft = $wajibBerkas && $dariBerkas === null
            ? BsApInvoiceDraft::query()->where('user_id', $request->user()->id)->where('jenis', $jenis)->first()
            : null;
        // ?link= — lanjutkan draft yang dibawa link berkas supplier (bisa banyak per user, terpisah dari draft biasa).
        $link = $wajibBerkas && $request->integer('link')
            ? \App\Domain\BusinessSupport\Models\BsApBerkasLink::query()->whereKey($request->integer('link'))
                ->where('user_id', $request->user()->id)->whereIn('status', ['menunggu', 'terisi'])->first()
            : null;
        if ($link) {
            if ($link->status === 'terisi' && ! $link->seen_at) {
                $link->update(['seen_at' => now()]);
            }
            $draft = (object) ['data' => array_merge($link->data ?? [], ['link_id' => $link->id,
                'berkas_upload_id' => $link->berkas_upload_id ?? ($link->data['berkas_upload_id'] ?? '')]), 'step' => $link->step, 'updated_at' => $link->updated_at];
        }

        return Inertia::render('BusinessSupport/Payable/Form', [
            'jenis' => $info,
            'dariBerkas' => $dariBerkas,
            'berkasSaya' => $wajibBerkas ? app(\App\Domain\BusinessSupport\Services\ApBerkasService::class)->pilihanInvoice($request->user()) : null,
            'draftInvoice' => $draft ? ['data' => $draft->data, 'step' => $draft->step, 'updated_at' => $draft->updated_at?->toIso8601String()] : null,
            // Detil Tagihan › Upload File / Generate Link.
            'linkBerkas' => $link ? ['id' => $link->id, 'url' => $link->url(), 'invoice_number' => $link->invoice_number, 'status' => $link->status,
                'filled_at' => $link->filled_at?->toIso8601String(), 'berkas_upload_id' => $link->berkas_upload_id] : null,
            'jenisBerkas' => $wajibBerkas ? \App\Domain\BusinessSupport\Models\BsApBerkas::query()->where('is_active', true)
                ->orderByDesc('is_mandatory')->orderBy('id')->get(['id', 'name', 'is_mandatory']) : [],
            // Penerima Reimburse/Deposit tercatat sebagai supplier berkategori khusus
            // (PenerimaHutang) — mereka bukan pilihan pada Invoice Hutang ke supplier.
            'suppliers' => $jenis === 'invoice'
                ? BsSupplier::where('is_active', true)
                    ->where(fn ($q) => $q->whereNull('category')->orWhereNotIn('category', \App\Domain\BusinessSupport\Services\PenerimaHutang::KATEGORI))
                    ->orderBy('name')->get(['id', 'code', 'name', 'category'])
                : [],
            'employees' => $jenis === 'reimburse'
                ? \App\Domain\BusinessSupport\Models\BsEmployee::with('costCenter:id,name')->where('is_active', true)->orderBy('name')
                    ->get(['id', 'nik', 'name', 'position', 'cost_center_id', 'bank_name', 'bank_account'])
                    ->map(fn ($e) => ['id' => $e->id, 'code' => (string) $e->nik, 'name' => $e->name, 'category' => trim(($e->position ?? '').' · '.($e->costCenter?->name ?? ''), ' ·'),
                        'bank' => trim(($e->bank_name ?? '').' '.($e->bank_account ?? ''))])
                : [],
            'customers' => $jenis === 'deposit'
                ? \App\Domain\Master\Customer\Models\Customer::orderBy('full_name')->get(['id', 'code', 'full_name'])
                    ->map(fn ($c) => ['id' => $c->id, 'code' => (string) $c->code, 'name' => $c->full_name, 'category' => ''])
                : [],
            'units' => $units,
            // Kategori yang dicentang untuk modul ini; akun kreditnya dari pemakaian modul ini.
            'categories' => BsApCategory::where('is_active', true)->dipakaiDi($jenis)
                ->with(['debitAccount:id,code,name', 'usages' => fn ($u) => $u->where('jenis', $jenis)->with('payableAccount:id,code,name')])
                ->orderBy('name')->get()
                ->map(function ($c) use ($jenis) {
                    $kredit = $c->pemakaian($jenis)?->payableAccount;

                    return [
                        'id' => $c->id, 'code' => $c->code, 'name' => $c->name,
                        'debit' => $c->debitAccount?->code.' - '.$c->debitAccount?->name,
                        'payable' => $kredit?->code.' - '.$kredit?->name,
                        'debit_id' => $c->debit_account_id, 'debit_code' => $c->debitAccount?->code, 'debit_name' => $c->debitAccount?->name,
                        'payable_id' => $kredit?->id, 'payable_code' => $kredit?->code, 'payable_name' => $kredit?->name,
                    ];
                }),
            'pphAccounts' => $pphAccounts,
            'pphDefaults' => [   // #113 jenis PPh → akun default (HUTANG PPh 21/23/4(2))
                'pph21' => $pphAccounts->firstWhere('code', '20104040402')?->id,
                'pph23' => $pphAccounts->firstWhere('code', '20104040403')?->id,
                'pph4_2' => $pphAccounts->firstWhere('code', '20104040401')?->id,
            ],
            'ppnAccount' => BsAccount::where('is_active', true)->where('code', config('business_support.gl_accounts.ppn_in'))->first(['id', 'code', 'name']),
            // Debit "Biaya PPh" utk PPh DITANGGUNG, per JENIS PPh (bisa diganti di struktur jurnal).
            // Kuncinya sama dengan pphDefaults di atas, supaya satu jenis PPh menentukan sepasang
            // akun sekaligus: Dr Biaya PPh <jenis> / Cr Hutang PPh <jenis>.
            'pphBorneDefaults' => collect((array) config('business_support.gl_accounts.pph_borne_expense'))
                ->map(fn ($kode) => BsAccount::where('code', $kode)->value('id'))
                ->all(),
            // Cadangan bila jenis PPh belum dipilih padahal tarifnya diisi manual.
            'pphBorneExpense' => BsAccount::where('code', config('business_support.gl_accounts.pph_borne_expense_default'))->first(['id', 'code', 'name']),
            // cost_center_optional ikut dikirim: layar Struktur Jurnal mewajibkan cost center,
            // dan tanpa penanda ini ia tidak bisa membedakan akun yang MEMANG boleh tanpa cost
            // center (mis. Utang Usaha Supplier) — hasilnya wizard-nya mengunci diri sendiri
            // pada invoice yang sebenarnya sah. Daftar akunnya sama dengan Jurnal Memorial.
            'accounts' => BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'cost_center_optional', 'cost_center_id']),
            'costCenters' => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'departments' => BsDepartment::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'ledgers' => BsLedger::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'locations' => BsLocation::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),

            // COST CENTER MENGIKUTI UNIT KERJA PENGAJU, dan TERKUNCI.
            //
            // Diisikan dari karyawan yang tertaut ke akun ini (CostCenterPengguna) — definisi
            // yang sama dengan yang tercetak di Bukti Permohonan Pembayaran dan SIP, supaya
            // dokumen dan layar input tidak pernah menyebut unit berbeda untuk orang yang sama.
            //
            // Dikunci karena biaya yang dibebankan ke departemen lain menggeser realisasi
            // anggaran dua departemen sekaligus — yang satu tampak hemat, yang lain boros, dan
            // keduanya salah. Hanya pemegang ap.costcenter.override (Accounting) yang boleh
            // mengubahnya.
            'ccPengguna' => app(CostCenterPengguna::class)->idUntuk($request->user()),
            'bisaUbahCc' => (bool) $request->user()?->can('ap.costcenter.override'),
            // Akun tanpa anggaran: tidak diisi cost center otomatis, dan tidak dituntut
            // mengisinya. Dikirim sebagai KODE supaya layar memakai daftar yang sama dengan
            // server — bukan salinan kedua yang suatu saat berbeda.
            'coaTanpaAnggaran' => array_map('strval', (array) config('business_support.budget.coa_tanpa_anggaran', [])),
            // Mode EDIT: isian lama untuk mengisi wizard. null = registrasi baru.
            'invoiceEdit' => $edit !== null ? $this->dataEdit($edit) : null,
            // Memo Urgent yang sudah disetujui Direktur & belum dipakai invoice lain — tabel di
            // bawah Struktur Jurnal, satu memo per invoice (opsional).
            'memoUrgent' => app(\App\Domain\ApprovalInbox\Services\MemoUntukInvoice::class)->pilihan($edit?->id),
            // Tautan "Buat Memo Urgent" di langkah 1 — hanya bagi yang boleh mengajukan memo.
            'bisaBuatMemo' => (bool) $request->user()?->can('approvalinbox.submit'),
        ]);
    }

    /**
     * Sisa budget BULAN tanggal invoice per akun × cost center — kolom "Sisa Budget" di langkah
     * Struktur. Kunci "akun|cc" (cc kosong = tanpa cost center); null = akun tanpa anggaran.
     */
    public function sisaBudget(Request $request, \App\Domain\BusinessSupport\Services\BudgetGuardService $guard): \Illuminate\Http\JsonResponse
    {
        abort_unless($request->user()?->canAny(['ap.invoice.create', 'ap.invoice.update']), 403);
        $v = $request->validate([
            'date' => ['required', 'date'],
            'pairs' => ['array', 'max:60'],
            'pairs.*' => ['string', 'regex:/^\d+\|\d*$/'],
            'exclude_journal_id' => ['nullable', 'integer'],
        ]);
        $out = [];
        foreach (array_unique($v['pairs'] ?? []) as $pair) {
            [$acc, $cc] = explode('|', $pair);
            $out[$pair] = $guard->sisaBulan((int) $acc, $cc !== '' ? (int) $cc : null, $v['date'], $v['exclude_journal_id'] ?? null);
        }

        return response()->json(['bulan' => \App\Domain\BusinessSupport\Models\BsBudget::MONTHS[\Illuminate\Support\Carbon::parse($v['date'])->month - 1].' '.\Illuminate\Support\Carbon::parse($v['date'])->year, 'sisa' => $out]);
    }

    /**
     * Pilihan budget ASAL untuk Alokasi di langkah Struktur: anggaran approved tahun invoice yang
     * masih punya sisa di BULAN invoice (anggaran bulan − realisasi bulan, aturan penjaga budget).
     */
    public function sumberAlokasi(Request $request, \App\Domain\BusinessSupport\Services\BudgetAllocationService $alokasi): \Illuminate\Http\JsonResponse
    {
        abort_unless($request->user()?->canAny(['ap.invoice.create', 'ap.invoice.update']), 403);
        $v = $request->validate([
            'date' => ['required', 'date'],
            'kecuali' => ['array'],
            'kecuali.*' => ['integer'],
            'exclude_journal_id' => ['nullable', 'integer'],
        ]);
        $tgl = \Illuminate\Support\Carbon::parse($v['date']);
        $out = \App\Domain\BusinessSupport\Models\BsBudget::with(['account:id,code,name', 'costCenter:id,code,name'])
            ->where('year', $tgl->year)->where('status', 'approved')
            ->whereNotIn('id', $v['kecuali'] ?? [0])
            ->orderBy('doc_no')->get()
            ->map(fn ($b) => [
                'id' => $b->id, 'doc_no' => $b->doc_no,
                'label' => trim(($b->costCenter?->name ?? 'Semua cost center').' — '.$b->account?->code.' '.$b->account?->name),
                'sisa' => $alokasi->sisaBulan($b, (int) $tgl->month, $v['exclude_journal_id'] ?? null),
            ])
            ->filter(fn ($b) => $b['sisa'] > 0)->values();

        return response()->json(['bulan' => \App\Domain\BusinessSupport\Models\BsBudget::MONTHS[$tgl->month - 1].' '.$tgl->year, 'budgets' => $out]);
    }

    /**
     * Simpan isian wizard Registrasi Invoice Hutang sebagai DRAFT user (satu per user).
     *
     * edit_berkas = user menekan "Edit" pada berkas pilihannya: sesudah draft tersimpan ia dibawa
     * ke Upload Berkas, yang langsung meneruskan ke halaman edit berkas itu. Lewat daftar Upload
     * Berkas (bukan langsung ke edit) supaya tombol Back peramban kembali ke Upload Berkas, bukan
     * ke wizard invoice.
     */
    public function simpanDraft(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user?->can('ap.invoice.create'), 403);
        $v = $request->validate([
            'data' => ['required', 'array'],
            'step' => ['required', 'integer', 'min:1', 'max:5'],
        ]);
        $berkasId = isset($v['data']['berkas_upload_id']) && is_numeric($v['data']['berkas_upload_id']) ? (int) $v['data']['berkas_upload_id'] : null;

        BsApInvoiceDraft::query()->updateOrCreate(
            ['user_id' => $user->id, 'jenis' => 'invoice'],
            ['data' => $v['data'], 'step' => $v['step'], 'berkas_upload_id' => $berkasId],
        );

        return back()->with('success', 'Registrasi invoice disimpan sebagai draft.');
    }

    /** Buang draft Registrasi Invoice Hutang user. */
    public function buangDraft(Request $request): RedirectResponse
    {
        BsApInvoiceDraft::query()->where('user_id', $request->user()->id)->where('jenis', 'invoice')->delete();

        return redirect()->route('bs.ap.create')->with('success', 'Draft invoice dibuang.');
    }

    /** Isian invoice lama untuk wizard Edit — bentuknya sama dengan form registrasi. @return array<string,mixed> */
    private function dataEdit(BsApInvoice $inv): array
    {
        $inv->load(['lines', 'supplier:id,name']);
        $jurnal = $inv->journal_id
            ? \App\Domain\BusinessSupport\Models\BsJournal::with(['lines' => fn ($q) => $q->orderBy('id')])->find($inv->journal_id)
            : null;

        return [
            'id' => $inv->id,
            'invoice_number' => $inv->invoice_number,
            'pihak_nama' => $inv->supplier?->name,
            // Jurnal lama dikecualikan saat menghitung sisa budget di layar (sama dengan server).
            'journal_id' => $inv->journal_id,
            'data' => [
                'supplier_id' => $inv->supplier_id,
                'memo_internal_id' => $inv->memo_internal_id,
                'category_id' => $inv->category_id,
                'supplier_invoice_no' => (string) $inv->supplier_invoice_no,
                'manual_no' => (string) $inv->manual_no,
                'invoice_date' => $inv->invoice_date?->toDateString(),
                'receive_date' => $inv->receive_date?->toDateString(),
                'term_days' => $inv->term_days,
                'description' => (string) $inv->description,
                'transfer_detail' => $inv->transfer_detail,
                'unit_id' => $inv->unit_id,
                'customer_id' => $inv->customer_id,
                'dpp' => (float) $inv->dpp,
                'ppn_mode' => $inv->ppn_mode ?: 'none',
                'ppn_pct' => (float) ($inv->ppn_pct ?: 11),
                'ppn_base' => (float) $inv->ppn_base,
                'pph_mode' => $inv->pph_mode ?: 'none',
                'pph_type' => (string) $inv->pph_type,
                'pph_pct' => (float) $inv->pph_pct,
                'pph_base' => (float) $inv->pph_base,
                'pph_account_id' => $inv->pph_account_id ?? '',
                'lines' => $inv->lines->map(fn ($l) => [
                    'description' => (string) $l->description, 'qty' => (float) $l->qty,
                    'unit_price' => (float) $l->unit_price, 'amount' => (float) $l->amount,
                ])->values()->all(),
                'journal_lines' => $jurnal ? $jurnal->lines->map(fn ($l) => [
                    'account_id' => $l->account_id, 'cost_center_id' => $l->cost_center_id,
                    'department_id' => $l->department_id, 'ledger_id' => $l->ledger_id, 'location_id' => $l->location_id,
                    'currency' => $l->currency ?? 'IDR', 'kurs' => (float) ($l->kurs ?? 1),
                    'debit' => (float) $l->debit, 'credit' => (float) $l->credit, 'description' => (string) $l->description,
                ])->values()->all() : [],
            ],
        ];
    }

    // ── EDIT & BATAL ─────────────────────────────────────────────────────────────
    //
    // Sebelum diproses FAT (BsApInvoice::alasanDiprosesFat() = null): Edit (ap.invoice.update)
    // & Batal (ap.invoice.cancel). Sesudahnya terkunci; Batal hanya oleh ap.invoice.cancel-after.
    // Semua izin diberikan lewat Grup Akses BO. Hanya invoice dari Registrasi yang punya keduanya.

    public function edit(Request $request, BsApInvoice $invoice): Response
    {
        $this->pastikanBolehEdit($request, $invoice);

        return $this->halamanForm($request, (string) ($invoice->jenis ?: 'invoice'), $invoice);
    }

    public function update(Request $request, BsApInvoice $invoice, RegisterApInvoice $action, \App\Domain\BusinessSupport\Services\BudgetGuardService $budgetGuard): \Symfony\Component\HttpFoundation\Response
    {
        $this->pastikanBolehEdit($request, $invoice);

        return $this->simpan($request, (string) ($invoice->jenis ?: 'invoice'), $action, $budgetGuard, $invoice);
    }

    public function batal(Request $request, BsApInvoice $invoice, \App\Domain\BusinessSupport\Actions\BatalkanApInvoice $action): RedirectResponse
    {
        abort_unless($this->bolehBatal($request, $invoice), 403, 'Anda tidak punya hak akses membatalkan invoice ini.');
        $v = $request->validate(['alasan' => ['required', 'string', 'max:255']], [], ['alasan' => 'Alasan pembatalan']);

        try {
            $action->execute($invoice, $v['alasan'], $request->user()?->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.ap.show', $invoice->id)->with('success', "Invoice {$invoice->invoice_number} dibatalkan.");
    }

    private function pastikanBolehEdit(Request $request, BsApInvoice $invoice): void
    {
        abort_unless($request->user()?->can('ap.invoice.update'), 403, 'Anda tidak punya hak akses mengedit invoice.');
        abort_if($invoice->batal() || $invoice->trashed(), 404);
        abort_unless($invoice->dariRegistrasi(), 403, 'Invoice ini bukan dari Registrasi — tidak bisa diedit.');
        if ($alasan = $invoice->alasanDiprosesFat()) {
            abort(403, "Sudah diproses FAT ({$alasan}) — tidak bisa diedit. Batalkan dulu bila perlu diubah.");
        }
    }

    /** Izin Batal mengikuti keadaannya: sebelum diproses FAT ap.invoice.cancel, sesudahnya ap.invoice.cancel-after. */
    private function bolehBatal(Request $request, BsApInvoice $invoice): bool
    {
        if ($invoice->batal() || $invoice->trashed() || ! $invoice->dariRegistrasi()) {
            return false;
        }
        $izin = $invoice->alasanDiprosesFat() !== null ? 'ap.invoice.cancel-after' : 'ap.invoice.cancel';

        return (bool) $request->user()?->can($izin);
    }


    public function store(Request $request, RegisterApInvoice $action, \App\Domain\BusinessSupport\Services\BudgetGuardService $budgetGuard): \Symfony\Component\HttpFoundation\Response
    {
        return $this->simpan($request, 'invoice', $action, $budgetGuard);
    }

    private function simpan(Request $request, string $jenis, RegisterApInvoice $action, \App\Domain\BusinessSupport\Services\BudgetGuardService $budgetGuard, ?BsApInvoice $lama = null): \Symfony\Component\HttpFoundation\Response
    {
        $info = $this->infoJenis($jenis);
        // Edit: izinnya ap.invoice.update, sudah diperiksa pastikanBolehEdit() di update().
        abort_unless($lama !== null || $request->user()?->can($info['izin'].'.create'), 403);

        // PENERIMA — satu-satunya isian yang berbeda antarjenis. Reimburse & Deposit memilih
        // karyawan / customer, lalu dicatat sebagai supplier berkategori khusus (PenerimaHutang)
        // supaya Kuitansi, Approval & pembayaran menempuh jalur yang sama dengan Invoice Hutang.
        // Edit: penerima TIDAK bisa diganti (ganti penerima = Batal lalu buat baru).
        $penerima = $lama !== null ? [] : match ($jenis) {
            'reimburse' => $request->validate(['employee_id' => ['required', 'integer', 'exists:bs_employees,id']], [
                'employee_id.required' => 'Pilih karyawan penerima reimburse.',
            ]),
            'deposit' => $request->validate([
                'customer_id' => ['required', 'integer', 'exists:customers,id'],
                'bank_name' => ['required', 'string', 'max:80'],
                'bank_account_no' => ['required', 'string', 'max:50'],
                'bank_account_name' => ['required', 'string', 'max:120'],
            ], ['customer_id.required' => 'Pilih customer penerima pengembalian deposit.'],
                ['bank_name' => 'Bank', 'bank_account_no' => 'No. Rekening', 'bank_account_name' => 'Atas Nama']),
            default => [],
        };
        if ($lama !== null) {
            $request->merge(['supplier_id' => $lama->supplier_id]);
        } elseif ($jenis !== 'invoice') {
            $layanan = app(\App\Domain\BusinessSupport\Services\PenerimaHutang::class);
            $sup = $jenis === 'reimburse'
                ? $layanan->karyawan(\App\Domain\BusinessSupport\Models\BsEmployee::findOrFail($penerima['employee_id']))
                : $layanan->customer(\App\Domain\Master\Customer\Models\Customer::findOrFail($penerima['customer_id']),
                    $penerima['bank_name'], $penerima['bank_account_no'], $penerima['bank_account_name']);
            $request->merge(['supplier_id' => $sup->id]);
        }

        // UPLOAD BERKAS: invoice yang dibuat dari berkas ber-TTD Dept Head langsung diajukan ke
        // approval invoice saat tersimpan, dan tidak bisa dibayar sebelum disetujui penuh.
        // Hanya registrasi baru Invoice Hutang; diperiksa lagi (terkunci) di dalam transaksi.
        // Registrasi baru Invoice Hutang WAJIB memilih berkas unggahan pengaju sendiri.
        $berkas = null;
        if ($lama === null && $jenis === 'invoice') {
            $request->validate(['berkas_upload_id' => ['required', 'integer']], [
                'berkas_upload_id.required' => 'Pilih berkas dari Upload Berkas di langkah Detil Tagihan.',
            ]);
            $berkas = \App\Domain\BusinessSupport\Models\BsApBerkasUpload::findOrFail((int) $request->input('berkas_upload_id'));
            try {
                $layananBerkas = app(\App\Domain\BusinessSupport\Services\ApBerkasService::class);
                $layananBerkas->assertMilik($berkas, $request->user());
                $layananBerkas->assertSiapInvoice($berkas);
            } catch (BusinessRuleException $e) {
                return back()->withInput()->with('error', $e->getMessage());
            }
        }

        $v = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:bs_suppliers,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            // Kategori harus dicentang untuk modul ini ("Dipakai di" di Setup Kategori Invoice).
            'category_id' => ['required', 'integer', \Illuminate\Validation\Rule::exists('bs_ap_category_usages', 'category_id')->where('jenis', $jenis)],
            'supplier_invoice_no' => ['required', 'string', 'max:80'],
            'manual_no' => ['nullable', 'string', 'max:80'],
            'invoice_date' => ['required', 'date'],
            'receive_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            // wajib: jatuh tempo (hari). Dengan Memo Urgent = 0 (jatuh tempo hari itu juga, lihat di bawah).
            'term_days' => ['required', 'integer', $request->filled('memo_internal_id') ? 'min:0' : 'min:1', 'max:3650'],
            'description' => ['required', 'string', 'max:255'],
            'transfer_detail' => ['nullable', 'string', 'max:255'],
            'kurs' => ['nullable', 'numeric', 'min:0'],
            'dpp' => ['required', 'numeric', 'min:0.01'],
            'ppn_mode' => ['required', 'in:none,with'],
            'ppn_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ppn_base' => ['nullable', 'numeric', 'min:0'],          // #140 nilai objek PPN
            // Include PPN: nilai yang dikirim adalah TOTAL, bukan DPP. Harus sampai ke server —
            // dulu ia cuma alat bantu di layar, sehingga angka tersimpan bisa beda dari layar.
            'ppn_incl' => ['nullable', 'boolean'],
            'pph_mode' => ['required', 'in:none,withheld,borne'], // borne = PPh ditanggung (sebelumnya tertolak validasi)
            'pph_type' => ['nullable', 'in:pph21,pph23,pph4_2'],   // #94
            'pph_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pph_base' => ['nullable', 'numeric', 'min:0'],         // #92 nilai objek PPh
            'pph_account_id' => ['nullable', 'integer', 'exists:bs_accounts,id'],
            'lines' => ['nullable', 'array'],
            'lines.*.description' => ['required_with:lines', 'string', 'max:200'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.amount' => ['required_with:lines', 'numeric', 'min:0'],
            'schedules' => ['nullable', 'array'],
            'schedules.*.due_date' => ['required_with:schedules', 'date'],
            'schedules.*.amount' => ['required_with:schedules', 'numeric', 'min:0.01'],
            'journal_lines' => ['nullable', 'array'],
            'journal_lines.*.account_id' => ['nullable', 'integer', 'exists:bs_accounts,id'],
            'journal_lines.*.cost_center_id' => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            'journal_lines.*.department_id' => ['nullable', 'integer', 'exists:bs_departments,id'],
            'journal_lines.*.ledger_id' => ['nullable', 'integer', 'exists:bs_ledgers,id'],
            'journal_lines.*.location_id' => ['nullable', 'integer', 'exists:bs_locations,id'],
            'journal_lines.*.currency' => ['nullable', 'string', 'max:8'],
            'journal_lines.*.kurs' => ['nullable', 'numeric', 'min:0'],
            'journal_lines.*.description' => ['nullable', 'string', 'max:255'],
            'journal_lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'journal_lines.*.credit' => ['nullable', 'numeric', 'min:0'],
        ], [
            'category_id.exists' => 'Kategori ini bukan untuk modul '.$info['judul'].' — pilih kategori yang "Dipakai di" modul ini (Setup › Kategori Invoice).',
        ], [
            // Nama kolom sebagaimana tertulis di layar — pesan galat tampil di bawah kolomnya.
            'category_id' => 'Kategori Invoice',
            'supplier_invoice_no' => $jenis === 'invoice' ? 'No. Invoice Supplier' : 'No. Bukti / Nota',
            'manual_no' => 'No. Manual',
            'receive_date' => 'Tgl. Terima',
            'term_days' => 'Jatuh Tempo (hari)',
            'description' => 'Keterangan',
            'dpp' => 'Nilai Transaksi',
            'lines.*.description' => 'Uraian',
            'lines.*.amount' => 'Jumlah',
        ]);
        // Tipe Transaksi ditiadakan dari layar: registrasi manual selalu "Normal". Saldo awal
        // hutang punya menunya sendiri (Account Payable › Saldo Awal).
        $v['trx_type'] = 'normal';

        // Cost center WAJIB pada baris jurnal yang akunnya memang memerlukannya.
        //
        // Layar Struktur Jurnal sudah menahan tombol Berikutnya, tetapi penjagaan di layar saja
        // hanya berlaku bagi yang memakai wizardnya apa adanya — kiriman langsung, tab yang
        // tertinggal terbuka sejak sebelum aturan ini ada, atau draf yang dipulihkan peramban
        // tetap lolos. Baris jurnal tanpa cost center membuat nilainya tidak terhitung di
        // Budget Control maupun laporan per cost center, dan itu tidak terlihat sebagai galat
        // apa pun — hanya angka yang kurang.
        //
        // PENGECUALIAN: akun bertanda cost_center_optional (lihat CoaTanpaCostCenterSeeder) —
        // mis. Utang Usaha Supplier — memang boleh tanpa cost center. Aturannya sama dengan
        // Jurnal Memorial (JournalController), bukan aturan kedua yang berdiri sendiri.
        if ($kurang = $this->akunKurangCostCenter($v['journal_lines'] ?? [])) {
            return back()->withInput()->with('error',
                'Cost center wajib diisi untuk akun: '.implode(', ', $kurang).'.');
        }

        // KOREKSI BUDGET (langkah Struktur): baris yang melewati sisa budget BULAN invoice boleh tetap
        // jalan bila koreksinya diajukan bersama — tambahan yang diajukan dihitung sebagai ruang
        // bulan itu oleh penjaga budget, lalu tiap koreksi masuk Approval Inbox. Hanya budget yang
        // memang dipakai baris jurnal invoice ini yang boleh dikoreksi dari sini.
        $koreksi = $request->validate([
            'koreksi' => ['nullable', 'array'],
            'koreksi.*.budget_id' => ['required', 'integer', 'exists:bs_budgets,id'],
            'koreksi.*.account_id' => ['required', 'integer'],
            'koreksi.*.cost_center_id' => ['nullable', 'integer'],
            'koreksi.*.keterangan' => ['nullable', 'string', 'max:200'],
            'koreksi.*.nilai' => ['required', 'numeric', 'min:1'],
        ], ['koreksi.*.nilai.min' => 'Nilai pengajuan koreksi budget wajib diisi.'], ['koreksi.*.nilai' => 'Nilai pengajuan'])['koreksi'] ?? [];
        // ALOKASI (langkah Struktur): alternatif koreksi — kekurangan budget bulan invoice diambil
        // dari sisa bulan yang sama di budget lain. Langsung berlaku (tanpa approval), dijalankan
        // satu transaksi dengan invoice SEBELUM penjaga budget memeriksa.
        $alokasi = $request->validate([
            'alokasi' => ['nullable', 'array'],
            'alokasi.*.budget_id' => ['required', 'integer', 'exists:bs_budgets,id'],
            'alokasi.*.dari_budget_id' => ['required', 'integer', 'exists:bs_budgets,id'],
            'alokasi.*.keterangan' => ['nullable', 'string', 'max:200'],
            'alokasi.*.nilai' => ['required', 'numeric', 'min:1'],
        ], ['alokasi.*.nilai.min' => 'Nilai alokasi wajib diisi.', 'alokasi.*.dari_budget_id.required' => 'Pilih budget asal alokasi.'],
            ['alokasi.*.nilai' => 'Nilai alokasi', 'alokasi.*.dari_budget_id' => 'Budget asal'])['alokasi'] ?? [];
        // MEMO URGENT (opsional): satu memo yang tahap Direktur-nya sudah menyetujui.
        $memoId = $request->validate(['memo_internal_id' => ['nullable', 'integer']])['memo_internal_id'] ?? null;
        if ($memoId && ! app(\App\Domain\ApprovalInbox\Services\MemoUntukInvoice::class)->boleh((int) $memoId, $lama?->id)) {
            return back()->withInput()->with('error', 'Memo Urgent ini belum disetujui Direktur atau sudah dipakai invoice lain.');
        }
        if ($memoId && $jenis === 'invoice' && ! app(\App\Domain\ApprovalInbox\Services\MemoUntukInvoice::class)->supplierCocok((int) $memoId, $request->integer('supplier_id') ?: null)) {
            return back()->withInput()->with('error', 'Supplier invoice berbeda dengan supplier di Memo Urgent yang ditarik.');
        }
        $v['memo_internal_id'] = $memoId ? (int) $memoId : null;
        // Memo Urgent: jatuh tempo hari itu juga (= Tgl. Terima / Tgl. Invoice).
        if ($memoId) {
            $v['term_days'] = 0;
            $v['due_date'] = null;
        }
        // Dari link berkas supplier: pakai nomor invoice yang sudah dipesan link itu.
        $linkBerkas = null;
        if ($lama === null && $jenis === 'invoice' && $request->integer('link_id')) {
            $linkBerkas = \App\Domain\BusinessSupport\Models\BsApBerkasLink::query()->whereKey($request->integer('link_id'))
                ->where('user_id', $request->user()->id)->whereIn('status', ['menunggu', 'terisi'])->first();
            if ($linkBerkas) {
                $v['nomor_dipesan'] = $linkBerkas->invoice_number;
            }
        }

        $tambahan = [];
        if ($koreksi !== [] || $alokasi !== []) {
            $dipakai = collect($v['journal_lines'] ?? [])
                ->filter(fn ($l) => ! empty($l['account_id']))
                ->map(fn ($l) => $budgetGuard->sisaBulan((int) $l['account_id'], ! empty($l['cost_center_id']) ? (int) $l['cost_center_id'] : null, $v['invoice_date'])['budget_id'] ?? null)
                ->filter()->unique()->all();
            foreach ([...$koreksi, ...$alokasi] as $k) {
                if (! in_array((int) $k['budget_id'], $dipakai, true)) {
                    return back()->withInput()->with('error', 'Koreksi / alokasi budget hanya untuk anggaran yang dipakai baris jurnal invoice ini.');
                }
            }
            foreach ($koreksi as $k) {
                $tambahan[(int) $k['budget_id']] = ($tambahan[(int) $k['budget_id']] ?? 0) + (float) $k['nilai'];
            }
        }

        try {
            $koreksiDiajukan = [];
            $alokasiDibuat = [];
            $simpanInvoice = function () use ($action, $v, $jenis, $request, $lama, $berkas, $linkBerkas, $koreksi, $alokasi, $tambahan, $budgetGuard, &$koreksiDiajukan, &$alokasiDibuat): array {
                // Alokasi dulu (budget tujuan bertambah), baru gerbang over-budget (BULANAN): tolak bila
                // baris jurnal melewati sisa anggaran bulan invoice, kecuali tertutup koreksi yang
                // diajukan bersama invoice ini. Edit: jurnal lama invoice ini dikecualikan supaya
                // realisasinya tidak terhitung dua kali.
                foreach ($alokasi as $a) {
                    $bulan = (int) \Illuminate\Support\Carbon::parse($v['invoice_date'])->month;
                    $alokasiDibuat[] = app(\App\Domain\BusinessSupport\Services\BudgetAllocationService::class)->pindah(
                        (int) $a['dari_budget_id'], (int) $a['budget_id'], [$bulan => (float) $a['nilai']], $v['invoice_date'],
                        $a['keterangan'] ?? null, $request->user()->id, null, $lama?->journal_id);
                }
                $budgetGuard->assertWithinBudget($v['journal_lines'] ?? [], null, $v['invoice_date'], $lama?->journal_id, $tambahan);
                $inv = $action->execute($v + ['jenis' => $jenis], $request->user()->id, $lama);
                if ($linkBerkas) {
                    app(\App\Domain\BusinessSupport\Services\LinkBerkasSupplier::class)->pakai($linkBerkas, $inv);
                }
                // Memo Urgent yang sudah dibayar di Kuitansi: invoice yang menyusul tidak boleh lebih kecil.
                app(\App\Domain\BusinessSupport\Services\PembayaranMemoUrgent::class)->assertInvoiceCukup($inv->refresh());
                foreach ($alokasiDibuat as $a) {
                    $a->forceFill(['source_type' => \App\Domain\BusinessSupport\Models\BsBudgetAllocation::SOURCE_INVOICE, 'source_id' => $inv->id,
                        'notes' => mb_substr(trim($a->notes.' · '.$inv->invoice_number, ' ·'), 0, 200)])->save();
                }
                // "Simpan & Buat Jurnal" sekaligus menerbitkan Purchase Order-nya, supaya pembelian
                // yang tagihannya masuk lebih dulu tetap punya dokumen pesanan. Data yang tidak ada
                // di invoice (no. PO, master barang) dibangkitkan sistem — lihat actionnya.
                $po = app(\App\Domain\BusinessSupport\Actions\GeneratePoFromApInvoice::class)->execute($inv, $request->user()->id);
                if ($berkas !== null) {
                    app(\App\Domain\BusinessSupport\Services\ApBerkasService::class)->ajukanInvoice($berkas, $inv);
                }
                if ($koreksi !== []) {
                    $koreksiDiajukan = app(\App\Domain\BusinessSupport\Services\BudgetCorrectionService::class)
                        ->ajukanDariInvoice($inv, $koreksi, $request->user()->id);
                }

                return [$inv, $po];
            };
            // Dari berkas / dengan koreksi budget: invoice + pengajuan approval satu transaksi — tidak
            // pernah ada invoice berkas tersimpan tanpa approval, atau over budget tanpa koreksinya.
            [$inv, $po] = ($berkas !== null || $koreksi !== [] || $alokasi !== [] || ! empty($v['memo_internal_id'])) ? DB::transaction($simpanInvoice) : $simpanInvoice();
        } catch (BusinessRuleException $e) {
            // Penolakan anggaran disalurkan ke DIALOG; selebihnya tetap toast. Lihat trait.
            return $this->balikDenganGalat($e);
        }

        if ($lama === null) {
            // Invoice sudah jadi → draft registrasinya tidak diperlukan lagi.
            BsApInvoiceDraft::query()->where('user_id', $request->user()->id)->where('jenis', $jenis)->delete();
        }

        if ($lama !== null) {
            session()->flash('success', "Invoice {$inv->invoice_number} diperbarui".($po ? " — Purchase Order {$po->po_number} dibuat ulang." : '.'));
        } elseif ($berkas !== null) {
            session()->flash('success', "Invoice {$inv->invoice_number} dari berkas {$berkas->berkas_number} tersimpan & diajukan ke approval — belum bisa dibayar sampai disetujui MW / BA."
                .($koreksiDiajukan ? ' '.count($koreksiDiajukan).' koreksi budget diajukan ke Approval Inbox.' : ''));
        } elseif ($po) {
            session()->flash('success', "Invoice {$inv->invoice_number} tersimpan & Purchase Order {$po->po_number} dibuat otomatis.");
        }

        // Langsung tampilkan halaman cetak Memo Internal setelah simpan.
        // Inertia::location = full-page visit ke respons HTML cetak (otomatis memunculkan dialog print);
        // ini cara resmi mengarahkan request Inertia ke halaman non-Inertia, dan bebas popup-blocker.
        // Tombol "Kembali ke Invoice" ada di halaman memo untuk balik ke detail (status, Bayar Hutang, dll).
        return Inertia::location(route('bs.ap.memo', $inv->id));
    }

    public function show(Request $request, BsApInvoice $invoice): Response
    {
        abort_unless($request->user()?->can('ap.invoice.show') || $this->bolehModulJenis($request, $invoice), 403);
        // Rute memakai withTrashed demi invoice BATAL; soft delete lain (mis. Saldo Awal dihapus) tetap 404.
        abort_if($invoice->trashed() && ! $invoice->batal(), 404);

        $invoice->load([
            'supplier:id,code,name', 'category:id,code,name',
            'unit:id,unit_code', 'customer:id,full_name',
            'lines', 'schedules' => fn ($q) => $q->orderBy('installment_no'),
            'payments' => fn ($q) => $q->latest('payment_date')->latest('id'),
        ]);

        // Memo penghapusan hutang yang menyelesaikan (sebagian) invoice ini — bukan pembayaran tunai.
        $writeoffs = \App\Domain\BusinessSupport\Models\BsApMemorial::where('ap_invoice_id', $invoice->id)
            ->where('trx_type', 'penghapusan')
            ->with('journal:id,journal_number')
            ->orderBy('memorial_date')->orderBy('id')
            ->get(['id', 'memorial_number', 'memorial_date', 'amount', 'description', 'journal_id'])
            ->map(fn ($m) => [
                'memorial_number' => $m->memorial_number,
                'memorial_date' => $m->memorial_date?->format('Y-m-d'),
                'amount' => (float) $m->amount,
                'reason' => $m->description, // alasan penghapusan hutang
                'journal_id' => $m->journal_id,  // tautan ke jurnal General Ledger
                'journal_number' => $m->journal?->journal_number,
            ]);

        // Struktur jurnal pendaftaran invoice (Dr Akun + PPN Masukan / Cr Hutang + PPh).
        // Ditarik dari journal_id invoice agar tampil di rincian AP.INV — penting untuk audit.
        $journal = $invoice->journal_id
            ? \App\Domain\BusinessSupport\Models\BsJournal::with(['lines' => fn ($q) => $q->orderBy('id'), 'lines.account:id,code,name'])->find($invoice->journal_id)
            : null;
        $journalData = null;
        if ($journal) {
            $ccCodes = DB::table('bs_cost_centers')->pluck('code', 'id');
            $deptCodes = DB::table('bs_departments')->pluck('code', 'id');
            $journalData = [
                'id' => $journal->id,
                'number' => $journal->journal_number,
                'date' => $journal->journal_date ? \Illuminate\Support\Carbon::parse($journal->journal_date)->toDateString() : null,
                'lines' => $journal->lines->map(function ($l) use ($ccCodes, $deptCodes) {
                    $dim = collect([$ccCodes[$l->cost_center_id] ?? null, $deptCodes[$l->department_id] ?? null])->filter()->implode(' · ');

                    return [
                        'account_code' => $l->account?->code,
                        'account_name' => $l->account?->name,
                        'dimensi' => $dim !== '' ? $dim : null,
                        'description' => $l->description,
                        'debit' => (float) $l->debit,
                        'credit' => (float) $l->credit,
                    ];
                })->values(),
                'total_debit' => round((float) $journal->lines->sum('debit'), 2),
                'total_credit' => round((float) $journal->lines->sum('credit'), 2),
            ];
        }

        // Rincian invoice = rincian Purchase Order. Invoice yang diposting dari PO menyalin baris PO
        // saat dibuat (PostingInvoiceController::poLines). Untuk invoice LAMA yang terlanjur tanpa
        // baris, rincian PO ditarik langsung sebagai cadangan tampilan — tanpa mengubah data.
        $po = $invoice->purchaseOrder();
        if ($po && $invoice->lines->isEmpty()) {
            $po->load('lines.item.uom');
            $fallback = $po->lines
                ->filter(fn ($l) => (float) $l->qty_received > 0)
                ->map(function ($l) {
                    $qty = (float) $l->qty_received;
                    $uom = $l->item?->uom?->code ?? $l->item?->uom?->name;
                    $name = trim((($l->item?->code) ? $l->item->code.' - ' : '').($l->item?->name ?? 'Barang'));

                    return [
                        'description' => trim($name.($uom ? ' ('.$uom.')' : '')),
                        'qty' => $qty,
                        'unit_price' => (float) $l->unit_price,
                        'amount' => round($qty * (float) $l->unit_price, 2),
                    ];
                })->values();
            $invoice->setRelation('lines', $fallback->map(fn ($l) => new \App\Domain\BusinessSupport\Models\BsApInvoiceLine($l)));
        }

        return Inertia::render('BusinessSupport/Payable/Show', [
            'invoice' => array_merge($invoice->toArray(), ['payable_amount' => $invoice->payable_amount]),
            // Tautan ke PO asal — dipakai tombol "Cetak Purchase Order" pada rincian invoice.
            'purchaseOrder' => $po ? ['id' => $po->id, 'po_number' => $po->po_number] : null,
            'journal' => $journalData,
            'outstanding' => (float) $invoice->outstanding,
            'cashAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)->where('code', 'like', '101010%')->orderBy('code')->get(['id', 'code', 'name']),
            'defaultCash' => $this->accId('cash'),
            'writeoffs' => $writeoffs,
            'writeoffTotal' => round((float) $writeoffs->sum('amount'), 2),
            // Tombol Edit / Batal — keputusannya di server, layar hanya mencerminkannya.
            'aksi' => $invoice->batal() ? null : [
                'dariRegistrasi' => $invoice->dariRegistrasi(),
                // Invoice hasil Posting dari FO punya Edit-nya sendiri (bs.ap.edit-fo), aturan kunci sama.
                'dariPostingFo' => $invoice->dariPostingFo(),
                'diprosesFat' => ($invoice->dariRegistrasi() || $invoice->dariPostingFo()) ? $invoice->alasanDiprosesFat() : null,
                'edit' => ($invoice->dariRegistrasi() || $invoice->dariPostingFo()) && $invoice->alasanDiprosesFat() === null && (bool) $request->user()?->can('ap.invoice.update'),
                'editRute' => $invoice->dariPostingFo() ? 'bs.ap.edit-fo' : 'bs.ap.edit',
                'batal' => $this->bolehBatal($request, $invoice),
                // Batal masih tertahan Kuitansi/pembayaran → sebutkan sebabnya, jangan tombol mati tanpa alasan.
                'penahanBatal' => $invoice->kuitansiAktifAtauMenunggu() ? 'Void / tolak Kuitansi Hutangnya lebih dulu.'
                    : (((float) $invoice->paid_amount > 0) ? 'Sudah ada pembayaran.' : null),
            ],
            'infoBatal' => $invoice->batal() ? [
                'at' => $invoice->cancelled_at?->format('d-m-Y H:i'),
                'by' => $invoice->cancelled_by ? DB::table('users')->where('id', $invoice->cancelled_by)->value('name') : null,
                'reason' => $invoice->cancel_reason,
            ] : null,
        ]);
    }

    /** Simpan / hapus catatan kelengkapan berkas (free-text) pada rincian AP.INV. */
    public function updateBerkasNotes(Request $request, BsApInvoice $invoice): RedirectResponse
    {
        abort_unless($request->user()?->can('ap.berkas.update'), 403);

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:5000']]);
        $invoice->update(['berkas_notes' => ($data['notes'] ?? '') !== '' ? $data['notes'] : null]);

        return back(303);
    }

    /**
     * Tanggal berkas fisik diterima Finance — HANYA boleh diisi Finance.
     *
     * Dibatasi lewat PERAN (Finance / FinanceManager), bukan departemen: di sistem ini user tidak
     * punya kaitan departemen sama sekali (users tanpa kolom departemen, bs_hr_departments kosong,
     * bs_employees.department_id semuanya NULL), sehingga peran adalah proksi Dept Finance yang
     * benar-benar ada. SuperAdmin tetap bisa — bypass global yang berlaku di seluruh aplikasi.
     *
     * Catatan: belum ada syarat "approval lengkap" karena Invoice Hutang memang belum punya alur
     * approval (status hanya Open/Lunas). Syarat itu menyusul bila alurnya dibangun.
     */
    public function updateBerkasFinance(Request $request, BsApInvoice $invoice): RedirectResponse
    {
        abort_unless($request->user()?->can('ap.berkas.update'), 403);
        abort_unless($this->isFinance($request), 403, 'Hanya Dept Finance yang boleh mengisi tanggal berkas diterima Finance.');

        $data = $request->validate(['berkas_finance_date' => ['nullable', 'date']], [], [
            'berkas_finance_date' => 'tanggal berkas diterima Finance',
        ]);
        $invoice->update(['berkas_finance_date' => $data['berkas_finance_date'] ?? null]);

        return back(303)->with('success', ($data['berkas_finance_date'] ?? null)
            ? 'Tanggal berkas diterima Finance disimpan.'
            : 'Tanggal berkas diterima Finance dikosongkan.');
    }

    /** Apakah user termasuk Dept Finance (proksi peran). SuperAdmin ikut lewat Gate::before. */
    /**
     * Akun pada baris jurnal yang MEMERLUKAN cost center tetapi barisnya dikosongkan.
     *
     * Memulangkan label akunnya ("50302020213 - JASA OUTSOURCE …") supaya pesan galatnya bisa
     * langsung ditelusuri ke barisnya — bukan sekadar "cost center wajib diisi" yang membuat
     * orang menebak baris mana pada jurnal berisi sepuluh baris.
     *
     * Baris tanpa akun diabaikan: layar Struktur Jurnal memang membolehkan baris kosong
     * sebagai pengisi, dan barisnya dibuang saat diproses.
     *
     * @param  array<int,array<string,mixed>>  $lines
     * @return array<int,string>
     */
    private function akunKurangCostCenter(array $lines): array
    {
        $perluCc = [];
        foreach ($lines as $l) {
            $accId = (int) ($l['account_id'] ?? 0);
            if ($accId <= 0 || ! empty($l['cost_center_id'])) {
                continue;
            }
            $perluCc[$accId] = true;
        }
        if ($perluCc === []) {
            return [];
        }

        // Hanya akun yang TIDAK bertanda optional yang dilaporkan. Penandanya bisa NULL pada
        // baris lama, dan NULL berarti "wajib" — sama dengan cara JournalController membacanya.
        //
        // Akun TANPA ANGGARAN juga dikecualikan. Keduanya satu hal yang sama dilihat dari dua
        // sisi: akun yang tidak dianggarkan tidak dibebankan per cost center, jadi menuntut
        // cost center di sana hanya menahan dokumen tanpa guna. PPN Masukan contohnya — ia
        // didebit otomatis tiap invoice ber-PPN.
        $bebas = array_map('strval', (array) config('business_support.budget.coa_tanpa_anggaran', []));

        return BsAccount::whereIn('id', array_keys($perluCc))
            ->where(fn ($q) => $q->where('cost_center_optional', false)->orWhereNull('cost_center_optional'))
            ->whereNotIn('code', $bebas)
            ->orderBy('code')->get(['code', 'name'])
            ->map(fn ($a) => trim($a->code.' - '.$a->name))
            ->values()->all();
    }

    private function isFinance(Request $request): bool
    {
        return (bool) $request->user()?->hasAnyRole(['Finance', 'FinanceManager', 'SuperAdmin']);
    }

    /** #51: Cetak bukti hutang supplier + riwayat pembayaran (auto-print). */
    public function print(Request $request, BsApInvoice $invoice): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('ap.invoice.print'), 403);

        $invoice->load(['supplier:id,code,name', 'lines', 'payments' => fn ($q) => $q->orderBy('payment_date')->orderBy('id')]);
        $accNames = BsAccount::whereIn('id', $invoice->payments->pluck('cash_account_id')->filter()->unique())->pluck('name', 'id');

        return response($this->renderInvoicePrint($invoice, $accNames))->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function renderInvoicePrint(BsApInvoice $invoice, $accNames): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
        $co = (string) config('business_support.company.name', 'PT. Mitra Usaha Propertindo');
        $now = now()->translatedFormat('d F Y H:i');

        $payable = (float) $invoice->payable_amount;
        $paid = (float) $invoice->paid_amount;
        $sisa = round($payable - $paid, 2);
        $lunas = $sisa <= 0.01;

        // #115: rincian pajak (DPP, PPN, PPh dipungut) untuk print-out.
        $dpp = (float) $invoice->dpp;
        $ppnAmt = (float) $invoice->ppn_amount;
        $pphAmt = (float) $invoice->pph_amount;
        $grossTotal = (float) $invoice->total > 0 ? (float) $invoice->total : $dpp + $ppnAmt; // DPP + PPN
        $pct = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
        $pphLabels = ['pph21' => 'PPh Pasal 21', 'pph23' => 'PPh Pasal 23', 'pph4_2' => 'PPh Pasal 4(2)'];
        $pphLabel = $pphLabels[$invoice->pph_type] ?? 'PPh';

        $sumRows = '<tr><td>DPP</td><td class="r">'.$rp($dpp).'</td></tr>';
        if ($invoice->ppn_mode === 'with' || $ppnAmt > 0) {
            $sumRows .= '<tr><td>PPN '.$pct($invoice->ppn_pct).'%</td><td class="r">'.$rp($ppnAmt).'</td></tr>';
        }
        $sumRows .= '<tr><td>Total (DPP + PPN)</td><td class="r">'.$rp($grossTotal).'</td></tr>';
        if ($invoice->pph_mode === 'borne' && $pphAmt > 0) {
            // Ditanggung → PPh beban perusahaan, TIDAK dipotong dari supplier (net = total).
            $sumRows .= '<tr><td>'.$e($pphLabel).' '.$pct($invoice->pph_pct).'% (ditanggung)</td><td class="r">'.$rp($pphAmt).'</td></tr>';
        } elseif ($invoice->pph_mode === 'withheld' || $pphAmt > 0) {
            $sumRows .= '<tr><td>'.$e($pphLabel).' '.$pct($invoice->pph_pct).'% (dipotong)</td><td class="r">- '.$rp($pphAmt).'</td></tr>';
        }
        $sumRows .= '<tr class="net"><td>Nilai Hutang (Net)</td><td class="r">'.$rp($payable).'</td></tr>'
            .'<tr><td>Telah Dibayar</td><td class="r">'.$rp($paid).'</td></tr>'
            .'<tr class="tot"><td>Sisa</td><td class="r">'.$rp(max(0, $sisa)).'</td></tr>';

        $lineRows = '';
        foreach ($invoice->lines as $l) {
            $lineRows .= '<tr><td>'.$e($l->description).'</td><td class="r">'.$rp($l->amount ?? $l->total ?? 0).'</td></tr>';
        }
        if ($lineRows === '') {
            $lineRows = '<tr><td>'.$e($invoice->description).'</td><td class="r">'.$rp($payable).'</td></tr>';
        }

        $payRows = '';
        foreach ($invoice->payments as $p) {
            $payRows .= '<tr><td>'.$e(optional($p->payment_date)->translatedFormat('d/m/Y')).'</td><td>'
                .$e($accNames[$p->cash_account_id] ?? '—').'</td><td>'.$e($p->reference).'</td><td class="r">'.$rp($p->amount).'</td></tr>';
        }
        $payBlock = $payRows !== ''
            ? '<h3>Riwayat Pembayaran</h3><table><thead><tr><th>Tanggal</th><th>Dari Kas/Bank</th><th>Referensi</th><th class="r">Jumlah</th></tr></thead><tbody>'.$payRows.'</tbody></table>'
            : '<p class="muted">Belum ada pembayaran.</p>';

        $badge = $lunas
            ? '<span class="badge lunas">LUNAS</span>'
            : '<span class="badge sisa">SISA '.$rp($sisa).'</span>';

        return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>'.$e($invoice->invoice_number).'</title><style>
            @page{size:A4;margin:16mm}*{box-sizing:border-box}body{font-family:"Segoe UI",Arial,sans-serif;font-size:12px;color:#1f2937;margin:0}
            h1{font-size:17px;margin:0 0 2px;color:#b91c1c}h3{font-size:12px;margin:14px 0 4px;color:#991b1b}
            .head{border-bottom:2px solid #b91c1c;padding-bottom:8px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:flex-start}
            .muted{color:#6b7280;font-size:11px}table{border-collapse:collapse;width:100%;margin:4px 0;font-size:11px}
            th,td{border:1px solid #cbd5e1;padding:6px 8px;text-align:left}.r{text-align:right;font-variant-numeric:tabular-nums}
            dl{display:grid;grid-template-columns:130px 1fr;gap:3px 8px;margin:6px 0}dt{color:#6b7280}
            .sum{margin-top:8px;width:300px;margin-left:auto}.sum td{border:none;padding:2px 4px}.sum .tot{font-weight:bold;border-top:1px solid #cbd5e1}.sum .net{font-weight:600;border-top:1px solid #e5e7eb}
            .badge{display:inline-block;padding:2px 10px;border-radius:9px;font-size:11px;font-weight:bold}
            .lunas{background:#dcfce7;color:#166534}.sisa{background:#fef3c7;color:#92400e}
            .sign{margin-top:32px;display:flex;justify-content:space-between;font-size:11px;text-align:center}
            @media print{.noprint{display:none}}</style></head><body>
            <div class="head"><div><h1>BUKTI HUTANG SUPPLIER</h1><div class="muted">'.$e($co).'</div></div>
            <div class="muted" style="text-align:right">No: '.$e($invoice->invoice_number).'<br>Cetak: '.$e($now).'<br>'.$badge.'</div></div>
            <dl><dt>Supplier</dt><dd>'.$e($invoice->supplier?->name).'</dd>
            <dt>Tanggal Invoice</dt><dd>'.$e(optional($invoice->invoice_date)->translatedFormat('d F Y')).'</dd>
            <dt>Jatuh Tempo</dt><dd>'.$e(optional($invoice->due_date)->translatedFormat('d F Y')).'</dd>
            <dt>Keterangan</dt><dd>'.$e($invoice->description).'</dd></dl>
            <h3>Rincian</h3><table><thead><tr><th>Keterangan</th><th class="r">Jumlah</th></tr></thead><tbody>'.$lineRows.'</tbody></table>
            <table class="sum">'.$sumRows.'</table>
            '.$payBlock.'
            <div class="sign"><div>Dibuat,<br><br><br>(__________)</div><div>Diperiksa,<br><br><br>(__________)</div><div>Finance,<br><br><br>(__________)</div></div>
            <button class="noprint" onclick="window.print()" style="margin-top:14px;padding:6px 14px">Cetak</button>
            <script>window.onload=function(){setTimeout(function(){window.print()},300)}</script></body></html>';
    }

    /** Cetak "Memo Internal" + "Bukti Permohonan Pembayaran" (layout eProperty MI) di akhir registrasi. */
    public function memo(Request $request, BsApInvoice $invoice): \Illuminate\Http\Response
    {
        // Pembuat invoicenya sendiri boleh mencetak memo — staf pengunggah yang meregistrasi
        // Invoice Hutang dari berkasnya tidak memegang ap.memo, padahal sesudah simpan layar
        // langsung pindah ke cetak memo ini.
        abort_unless($request->user()?->can('ap.memo') || $this->bolehModulJenis($request, $invoice)
            || ((int) $invoice->created_by === (int) $request->user()?->id && $request->user()?->can('ap.invoice.create')), 403);

        $invoice->load([
            'supplier:id,code,name,bank_name,bank_account_no,bank_account_name',
            'payableAccount:id,code,name',
            'category.payableAccount:id,code,name',
            'lines',
        ]);

        return response($this->renderMemoPrint($invoice, $request->user()))
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** @param  mixed  $user  pengguna yang mencetak — dipakai hanya bila pembuat invoice tidak diketahui */
    private function renderMemoPrint(BsApInvoice $invoice, $user): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => 'Rp. '.number_format((float) $n, 2, ',', '.');
        $pct = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');

        $dpp = (float) $invoice->dpp;
        $ppn = (float) $invoice->ppn_amount;
        $pph = (float) $invoice->pph_amount;
        $net = (float) $invoice->payable_amount;

        $sup = $invoice->supplier;
        // Akun beku di invoice, bukan akun kategori: satu kategori bisa mengkredit akun berbeda
        // per modul, dan setup kategori bisa berubah sesudah invoice ini terbit.
        $payAcc = $invoice->payableAccount ?? $invoice->category?->payableAccount;
        $created = $e(now()->translatedFormat('d-m-Y H.i.s'));
        $eNo = $e($invoice->invoice_number);
        $eTgl = $e(optional($invoice->invoice_date)->translatedFormat('d F Y'));
        // Jatuh tempo dari invoice — diisi saat Registrasi Invoice Hutang, dan sejak sekarang
        // juga saat posting dari FO / SPK / Purchase Order. Tanda '-' bila memang belum ada,
        // supaya barisnya tetap tercetak dan ketiadaannya terbaca sebagai keadaan, bukan
        // sebagai baris yang lupa dipasang.
        $eJatuhTempo = $invoice->due_date
            ? $e($invoice->due_date->translatedFormat('d F Y'))
            : '-';
        $eDesc = $e($invoice->description);
        // NAMA LENGKAP KARYAWAN, bukan nama akun. users.name berisi nama login — di pemasangan
        // ini sering sependek "aji" — sehingga memo tercetak "Dari: aji" dan kolom tanda tangan
        // pun begitu. Dokumen yang keluar perusahaan menyebut orangnya, bukan akunnya.
        //
        // Jalur pencariannya sama dengan Departemen di bawah (NIP dulu, baru nama), lewat
        // CostCenterPengguna supaya kedua keterangan pada satu kertas tidak pernah menunjuk
        // karyawan yang berbeda. Jatuh ke nama akun bila akunnya memang belum tertaut.
        //
        // "Dari" & "Hormat Kami" = MANAGER cost center si PEMBUAT invoice (kolom Manager di master
        // Cost Center — SPV ke atas per departemen). Dibaca dari pembuat, bukan dari yang mencetak:
        // memo milik Legal yang dicetak orang Finance tetap ditandatangani Manager Legal.
        // Jatuh ke nama karyawan pembuat bila kolom Manager-nya kosong / pembuat tak tertaut.
        $user = \App\Models\User::find($invoice->created_by) ?? $user;
        $cc = app(CostCenterPengguna::class);
        $eDari = $e($cc->namaManager($user) ?: $cc->namaKaryawan($user) ?: ($user?->name ?? '-'));
        $eSupNm = $e($sup?->name ?? '-');
        $eBankNm = $e($sup?->bank_name ?: '-');
        $eBankRek = $e($sup?->bank_account_no ?: '-');
        $eBankAn = $e($sup?->bank_account_name ?: ($sup?->name ?: '-'));
        $eBankLbl = 'Bank'.($sup?->bank_name ? ' ('.$e($sup->bank_name).')' : '');
        $ePayAcc = $e($payAcc?->code ?: '-');
        $fDpp = $rp($dpp);
        $fPpn = $rp($ppn);
        $fPph = $rp($pph);
        $fNet = $rp($net);
        $ppnPct = $pct($invoice->ppn_pct);
        $eWords = $e(strtoupper(trim(preg_replace('/\s+/', ' ', terbilang((int) round($net))))).' RUPIAH');

        // Penyetuju Memo Internal AP (sesuai contoh): kiri Rizki Setyawardana, kanan Rachmat Suhendra.
        $eSign1 = $e('Rizki Setyawardana');
        $eSign2 = $e('Rachmat Suhendra');
        $eMeng1 = $e(BsSip::SIGNERS['moch_winarto'] ?? 'Moch Winarto');
        $eMeng2 = $e(BsSip::SIGNERS['boy_arifin'] ?? 'Boy Arifin');
        $logo = '/images/mup-logo.jpeg'; // logo MUP (public/images) — dimuat sebelum window.onload print

        // Baris item: No | Nama Barang | Harga Sat | Jml Harga
        $itemRows = '';
        if ($invoice->lines->count()) {
            foreach ($invoice->lines as $i => $l) {
                $itemRows .= '<tr><td class="c">'.($i + 1).'</td><td>'.$e($l->description).'</td><td class="r">'.$rp($l->unit_price ?: $l->amount).'</td><td class="r">'.$rp($l->amount).'</td></tr>';
            }
        } else {
            $itemRows = '<tr><td class="c">1</td><td>'.$eDesc.'</td><td class="r">'.$fDpp.'</td><td class="r">'.$fDpp.'</td></tr>';
        }

        // #BPP — checkbox OTOMATIS dari data invoice (sisanya kosong utk dicentang manual).
        $hasRek = trim((string) ($sup?->bank_account_no ?? '')) !== '';        // ada nomor rekening?
        $hasPph = in_array($invoice->pph_mode, ['withheld', 'borne'], true);   // ada PPh dipungut/ditanggung?
        $cbKas = $hasRek ? '&nbsp;' : 'X';   // Dibayar Via: KAS bila TANPA rekening
        $cbBank = $hasRek ? 'X' : '&nbsp;';   // Dibayar Via: Bank/transfer bila ADA rekening
        $cbTransfer = $hasRek ? 'X' : '&nbsp;';   // Cara bayar: Transfer bila ada rekening
        $cbTunai = $hasRek ? '&nbsp;' : 'X';   // Cara bayar: Tunai bila KAS (tanpa rekening)
        $cbPekerjaan = $hasPph ? 'X' : '&nbsp;';   // Type: Pekerjaan bila ada PPh
        $cbMaterial = $hasPph ? '&nbsp;' : 'X';   // Type: Material bila tanpa PPh
        $cbPnjLgsg = 'X';                         // Lampiran: selalu silang Penunjukan Langsung
        $backUrl = route('bs.ap.show', $invoice->id);

        // Unit kerja si pengaju, dari karyawan yang tertaut ke akunnya (employee_nip = NIK,
        // atau cocok nama). Bersumber cost center, jatuh ke Jabatan bila belum diisi.
        //
        // Dulu ada master Departemen tersendiri yang didahulukan, tapi tidak pernah diisi —
        // 0 baris — sehingga dokumen selalu jatuh ke Jabatan, yang menyebut peran orangnya
        // ("Staff Accounting") dan bukan unit yang menanggung biayanya. Master itu sudah
        // dicabut; namanya tetap "Departemen" di cetakan.
        //
        // SIP memakai urutan yang sama lewat SipController::unitPerUser(), supaya kedua dokumen
        // tidak menyebut unit yang berbeda untuk orang yang sama.
        $eDept = '-';
        $emp = null;
        if (! empty($user?->employee_nip)) {
            $emp = \App\Domain\BusinessSupport\Models\BsEmployee::with('costCenter:id,name')
                ->where('nik', $user->employee_nip)->first();
        }
        if (! $emp && ! empty($user?->name)) {
            $emp = \App\Domain\BusinessSupport\Models\BsEmployee::with('costCenter:id,name')
                ->where('name', $user->name)->first();
        }
        if ($emp) {
            $eDept = $e($emp->costCenter?->name ?: ($emp->position ?: '-'));
        }

        // #BPP — tabel No. Account / Uraian / Jumlah mengikuti Struktur Jurnal:
        //   • semua akun jurnal KECUALI Utang Usaha Supplier (akun payable),
        //   • Debit bernilai PLUS, Kredit bernilai MINUS,
        //   • TOTAL = penjumlahan SELURUH rincian yang tercetak di atasnya.
        $jlines = $invoice->journal_id
            ? \App\Domain\BusinessSupport\Models\BsJournalLine::with('account:id,code,name')
                ->where('journal_id', $invoice->journal_id)->orderBy('id')->get()
            : collect();
        // Akun hutang menurut jurnalnya — invoice lama yang Struktur Jurnalnya disusun manual bisa
        // menyimpan akun Kategori yang tidak dikredit jurnal; baris hutangnya lalu ikut tercetak.
        $payableId = (int) (BsApInvoice::akunHutangDariJurnal(
            $jlines->map(fn ($l) => ['account_id' => $l->account_id, 'credit' => $l->credit]),
            (int) ($invoice->payable_account_id ?? 0),
        ) ?? $invoice->payable_account_id ?? 0);
        // Nilai minus diberi tanda '-'; nilai positif (debit) tanpa tanda '+'.
        $signFmt = fn (float $n) => ($n < 0 ? '- Rp. ' : 'Rp. ').number_format(abs($n), 2, ',', '.');

        $bppRows = '';
        $bppCount = 0;
        // Total dijumlahkan dari baris yang BENAR-BENAR dicetak, bukan diambil dari baris Utang
        // Usaha Supplier. Satu invoice bisa punya BEBERAPA baris utang (mis. gimmick per customer);
        // mengambil baris pertama saja membuat total tercetak hanya sebesar rincian pertama.
        $totalPay = 0.0;
        foreach ($jlines as $l) {
            if ((int) $l->account_id === $payableId) {
                continue; // Utang Usaha Supplier → lawan jurnalnya, tidak dirinci
            }
            $amt = (float) $l->debit > 0 ? (float) $l->debit : -(float) $l->credit; // Debit + , Kredit −
            $bppRows .= '<tr><td>'.$e($l->account?->code ?: '-').'</td><td>'.$e($l->description ?: $invoice->description).'</td><td class="r">'.$signFmt($amt).'</td></tr>';
            $totalPay += $amt;
            $bppCount++;
        }
        $totalPay = round($totalPay, 2);
        if ($bppCount === 0) { // fallback data lama tanpa jurnal: baris hutang tunggal.
            $bppRows = '<tr><td>'.$ePayAcc.'</td><td>'.$eDesc.'</td><td class="r">'.$rp($net).'</td></tr>';
            $bppCount = 1;
            $totalPay = $net;
        }
        for ($k = $bppCount; $k < 6; $k++) { // padding baris kosong agar tabel rapi
            $bppRows .= '<tr class="empty"><td>&nbsp;</td><td></td><td></td></tr>';
        }
        $fTotalPay = $rp($totalPay);

        return <<<HTML
<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Memo Internal {$eNo}</title><style>
@page{size:A4;margin:13mm}*{box-sizing:border-box}
body{font-family:"Times New Roman",Georgia,serif;font-size:12px;color:#111;margin:0;line-height:1.4}
.page{page-break-after:always}.page:last-of-type{page-break-after:auto}
.created{font-size:9px;color:#555;text-align:right;margin-bottom:2px}
.ttl{text-align:center;font-weight:bold;font-size:15px;text-decoration:underline;letter-spacing:.5px}
.sub{text-align:center;font-size:12px;margin:0 0 10px}
.meta{border-collapse:collapse;font-size:12px}.meta td{padding:1px 3px;vertical-align:top}
.meta .lbl{width:130px}.meta .sep{width:8px}
p{margin:7px 0;text-align:justify}
table.grid{width:100%;border-collapse:collapse;margin:5px 0;font-size:11px}
table.grid th,table.grid td{border:1px solid #333;padding:4px 6px;vertical-align:top}
table.grid th{background:#f0f0f0;text-align:center}
.r{text-align:right;font-variant-numeric:tabular-nums}.c{text-align:center}
table.sum{float:right;width:280px;border-collapse:collapse;margin:2px 0 6px;font-size:11px}
table.sum td{padding:2px 6px;border:1px solid #333}table.sum .tot{font-weight:bold;background:#f0f0f0}
.bx{display:inline-block;width:11px;height:11px;border:1px solid #333;text-align:center;line-height:11px;font-size:9px;font-weight:bold}
.signs{margin-top:26px}
.srow{display:flex;justify-content:space-between;margin-bottom:6px}
.srow.ctr{justify-content:center;gap:70px;margin-top:10px}
.scol{width:31%;text-align:center}.sp{height:88px}.nm{font-weight:bold}
.sign2 td{height:18px}.sign2 .sph td{height:55px}table.sign2{table-layout:fixed}
.logo{width:62px;height:62px;object-fit:contain}
.phead{width:100%;border-collapse:collapse;margin-bottom:4px}.phead td{vertical-align:middle;border:0;padding:0}
.empty td{height:15px}
@media print{.noprint{display:none}}
</style></head><body>

<div class="page">
  <div class="created">Created By Java Core System ({$created})</div>
  <div style="text-align:center"><img class="logo" src="{$logo}" alt="MUP"></div>
  <div class="ttl">MEMO INTERNAL</div>
  <div class="sub">No : {$eNo}</div>
  <table class="meta">
    <tr><td class="lbl">Kepada Yth</td><td class="sep">:</td><td>RS</td></tr>
    <tr><td class="lbl">Tembusan</td><td class="sep">:</td><td>BA, MW</td></tr>
    <tr><td class="lbl">Dari</td><td class="sep">:</td><td>{$eDari}</td></tr>
    <tr><td class="lbl">Hari / Tanggal</td><td class="sep">:</td><td>{$eTgl}</td></tr>
    <tr><td class="lbl">Perihal</td><td class="sep">:</td><td>{$eDesc}</td></tr>
  </table>
  <p>Dengan hormat,<br>Sehubungan dengan keperluan {$eDesc}. Maka kami mengajukan permohonan pembayaran sebesar {$fNet} ({$eWords}). Dengan rincian sebagai berikut :</p>
  <table class="grid">
    <thead><tr><th style="width:32px">No.</th><th>Nama Barang</th><th style="width:130px">HARGA SAT. (Rp)</th><th style="width:130px">JML HARGA</th></tr></thead>
    <tbody>{$itemRows}</tbody>
  </table>
  <table class="sum">
    <tr><td>Sub Total</td><td class="r">{$fDpp}</td></tr>
    <tr><td>PPN {$ppnPct}%</td><td class="r">{$fPpn}</td></tr>
    <tr><td>PPH</td><td class="r">{$fPph}</td></tr>
    <tr class="tot"><td>TOTAL</td><td class="r">{$fNet}</td></tr>
  </table>
  <div style="clear:both"></div>
  <p style="margin-top:10px">Pembayaran dilakukan melalui transfer Rekening :</p>
  <table class="meta">
    <tr><td class="lbl">No. Rekening</td><td class="sep">:</td><td>{$eBankRek}</td></tr>
    <tr><td class="lbl">Bank</td><td class="sep">:</td><td>{$eBankNm}</td></tr>
    <tr><td class="lbl">Atas Nama</td><td class="sep">:</td><td>{$eBankAn}</td></tr>
  </table>
  <p>Demikian ini kami sampaikan, atas perhatiannya kami ucapkan terima kasih.</p>
  <div class="signs">
    <div class="srow">
      <div class="scol" style="width:34%">Hormat Kami,<div class="sp"></div><div class="nm">{$eDari}</div></div>
      <div style="width:60%">
        <div style="text-align:center">Menyetujui,</div>
        <div class="sp"></div>
        <div style="display:flex;justify-content:space-around;gap:12px">
          <div class="nm" style="flex:1;text-align:center">{$eSign1}</div>
          <div class="nm" style="flex:1;text-align:center">{$eSign2}</div>
        </div>
      </div>
    </div>
    <div class="srow" style="margin-top:40px">
      <div class="scol" style="width:34%">&nbsp;</div>
      <div style="width:60%">
        <div style="text-align:center">Mengetahui,</div>
        <div class="sp"></div>
        <div style="display:flex;justify-content:space-around;gap:12px">
          <div class="nm" style="flex:1;text-align:center">{$eMeng1}</div>
          <div class="nm" style="flex:1;text-align:center">{$eMeng2}</div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="page">
  <div class="created">Created By Java Core System ({$created})</div>
  <table class="phead"><tr>
    <td style="width:70px"><img class="logo" src="{$logo}" alt="MUP"></td>
    <td><div class="ttl">BUKTI PERMOHONAN PEMBAYARAN</div></td>
    <td style="width:70px"></td>
  </tr></table>
  <table class="meta" style="margin-top:8px">
    <tr><td class="lbl">Tanggal Pengajuan</td><td class="sep">:</td><td>{$eTgl}</td></tr>
    <tr><td class="lbl">Tanggal Jatuh Tempo</td><td class="sep">:</td><td>{$eJatuhTempo}</td></tr>
    <tr><td class="lbl">Dibayarkan Kepada</td><td class="sep">:</td><td>{$eSupNm}</td></tr>
    <tr><td class="lbl">Departemen</td><td class="sep">:</td><td>{$eDept}</td></tr>
  </table>
  <table class="grid">
    <colgroup><col style="width:25%"><col style="width:25%"><col style="width:25%"><col style="width:25%"></colgroup>
    <thead><tr><th>Dibayar Via</th><th>Type Pengeluaran</th><th>Cara Pembayaran Bank</th><th>Lampiran</th></tr></thead>
    <tbody><tr>
      <td><span class="bx">{$cbKas}</span> KAS<br><span class="bx">{$cbBank}</span> {$eBankLbl}<br>No. Rekening : {$eBankRek}</td>
      <td><span class="bx">{$cbPekerjaan}</span> Pekerjaan<br><span class="bx">{$cbMaterial}</span> Material</td>
      <td><span class="bx">{$cbTunai}</span> Tunai<br><span class="bx">{$cbTransfer}</span> Transfer</td>
      <td><span class="bx">{$cbPnjLgsg}</span> Penunjukan Langsung<br><span class="bx">&nbsp;</span> Tender<br><span class="bx">&nbsp;</span> Non Pembelian</td>
    </tr></tbody>
  </table>
  <table class="grid">
    <thead><tr><th style="width:135px">No. Account</th><th>Uraian Pembayaran</th><th style="width:150px">Jumlah</th></tr></thead>
    <tbody>{$bppRows}</tbody>
    <tfoot>
      <tr>
        <td>No. Cek / BG : -</td>
        <td>No. Voucher : {$eNo}</td>
        <td class="r" style="font-weight:bold;background:#f0f0f0">Total : {$fTotalPay}</td>
      </tr>
    </tfoot>
  </table>
  <table class="meta">
    <tr><td class="lbl">Terbilang</td><td class="sep">:</td><td>{$eWords}</td></tr>
  </table>
  <table class="grid sign2" style="margin-top:18px;text-align:center">
    <colgroup><col style="width:14.28%"><col style="width:14.28%"><col style="width:14.28%"><col style="width:14.28%"><col style="width:14.28%"><col style="width:14.28%"><col style="width:14.32%"></colgroup>
    <tr><td>Diajukan Oleh,</td><td colspan="2">Di Verifikasi Oleh,</td><td colspan="2">Di Setujui Oleh,</td><td>Di Bayarkan Oleh,</td><td>Di Terima Oleh,</td></tr>
    <tr class="sph"><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
    <tr><td>SPV/Manager Dept</td><td>Manager Accounting</td><td>Manager Finance</td><td>General Manager</td><td>Direktur</td><td>General Cashier</td><td>Penerima</td></tr>
  </table>
  <div class="noprint" style="margin-top:14px">
    <button onclick="window.print()" style="padding:6px 14px">Cetak</button>
    <a href="{$backUrl}" style="margin-left:10px;padding:6px 14px;border:1px solid #ccc;text-decoration:none;color:#333;border-radius:4px">&larr; Kembali ke Invoice</a>
  </div>
</div>

<script>window.onload=function(){setTimeout(function(){window.print()},300)}</script>
</body></html>
HTML;
    }

    public function pay(Request $request, BsApInvoice $invoice, PayApInvoice $action): RedirectResponse
    {
        abort_unless($request->user()?->can('ap.invoice.pay'), 403);

        $v = $request->validate([
            'payment_date' => ['required', 'date'],
            'cash_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reference' => ['nullable', 'string', 'max:150'],
        ]);

        try {
            $action->execute($invoice, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.ap.show', $invoice)->with('success', 'Pembayaran hutang tercatat — jurnal otomatis terbentuk.');
    }

    /** Berapa supplier teratas yang ditampilkan utuh sebelum sisanya dirangkum. */
    private const TOP_SUPPLIER = 20;

    /**
     * AGING HUTANG SUPPLIER — outstanding per supplier, 20 terbesar + rangkuman sisanya.
     *
     * Memakai computeAging() YANG SAMA dengan layar Aging Hutang, PDF, dan Excel-nya. Menyalin
     * ulang perhitungannya akan melahirkan angka kedua untuk pertanyaan yang sama, dan yang
     * membacanya tidak punya cara menebak mana yang benar.
     *
     * TIGA EMBER, BUKAN LIMA. computeAging menghasilkan lima (belum jatuh tempo · 1–30 · 31–60 ·
     * 61–90 · >90); layar ini merangkumnya menjadi tiga seperti pada laporan ringkas:
     *
     *     0–30   = belum jatuh tempo + 1–30 hari lewat
     *     31–60  = 31–60
     *     > 60   = 61–90 + >90
     *
     * Yang BELUM jatuh tempo sengaja masuk ke 0–30, bukan dibuang: menghilangkannya membuat
     * jumlah ketiga kolom tidak lagi sama dengan Outstanding-nya, dan selisihnya tidak pernah
     * dijelaskan di mana pun.
     *
     * OTHERS BUKAN SEKADAR SISA TAMPILAN. Ia dijumlah dari seluruh supplier ke-21 dan seterusnya,
     * sehingga Top 20 + Others SELALU sama dengan TOTAL. Tanpa baris itu, tabel yang memuat 20
     * dari 80 supplier akan terbaca seolah itulah seluruh hutangnya.
     */
    public function agingSupplier(Request $request): Response
    {
        abort_unless($request->user()?->can('ap.aging.view'), 403);

        return Inertia::render('BusinessSupport/Payable/AgingSupplier', $this->dataAgingSupplier($request));
    }

    /** Hitungan Aging Hutang Supplier — SATU sumber untuk layar, Excel, dan PDF-nya. */
    private function dataAgingSupplier(Request $request): array
    {
        [$rowsC, , , $asOf, $dateFrom] = $this->computeAging($request, $request->input('date_to'), $request->input('date_from'));

        $semua = [];
        foreach ($rowsC as $r) {
            $semua[] = [
                'name' => $r['name'],
                'b0' => round((float) $r['current'] + (float) $r['d30'], 2),
                'b31' => round((float) $r['d60'], 2),
                'b60' => round((float) $r['d90'] + (float) $r['d90p'], 2),
                'total' => round((float) $r['total'], 2),
            ];
        }
        usort($semua, fn ($a, $b) => $b['total'] <=> $a['total']);

        $jml = fn (array $baris, string $k) => round(array_sum(array_column($baris, $k)), 2);

        // Jumlah supplier yang dirinci: 10 / 20 (bawaan) / 50 / semua — dipilih di layar.
        $tampil = $request->input('tampil') === 'semua' ? 'semua'
            : (in_array((int) $request->input('tampil'), [10, 20, 50], true) ? (int) $request->input('tampil') : self::TOP_SUPPLIER);
        $n = $tampil === 'semua' ? count($semua) : $tampil;
        $top = array_slice($semua, 0, $n);
        $sisa = array_slice($semua, $n);
        $others = $sisa === [] ? null : [
            'jumlah' => count($sisa),
            'b0' => $jml($sisa, 'b0'),
            'b31' => $jml($sisa, 'b31'),
            'b60' => $jml($sisa, 'b60'),
            'total' => $jml($sisa, 'total'),
        ];

        return [
            'rows' => $top,
            'others' => $others,
            'total' => [
                'jumlah' => count($semua),
                'b0' => $jml($semua, 'b0'),
                'b31' => $jml($semua, 'b31'),
                'b60' => $jml($semua, 'b60'),
                'total' => $jml($semua, 'total'),
            ],
            // Supplier terbesar disebut namanya: pada hutang yang terpusat di satu pihak, itulah
            // keterangan yang paling menentukan dan paling mudah terlewat dari tabel panjang.
            'dominan' => $semua[0] ?? null,
            'topN' => $n,
            'tampil' => (string) $tampil,
            'dateFrom' => $dateFrom,
            'dateTo' => $asOf->toDateString(),
            'posisi' => PosisiHutang::dariRequest($request),
            'posisiOpsi' => PosisiHutang::opsi(),
        ];
    }

    /**
     * Unduh Aging Hutang Supplier — isi SAMA dengan layar (filter tanggal, posisi, Tampilkan
     * 10/20/50/semua; baris Others & TOTAL), angka penuh. Pencarian di layar tidak ikut.
     *
     * @return array{0:string,1:string,2:string} [judul periode, label posisi, baris tabel HTML]
     */
    /**
     * Kotak "Cari supplier" di layar ikut ke unduhan: hanya baris supplier yang disaring —
     * Others & TOTAL tetap utuh, persis seperti di layar (keduanya menjawab "berapa seluruhnya").
     */
    private function cariAgingSupplier(array $d, Request $request): array
    {
        $q = mb_strtolower(trim((string) $request->input('cari', '')));
        $d['cari'] = $q;
        if ($q !== '') {
            $d['rows'] = array_values(array_filter($d['rows'], fn ($r) => str_contains(mb_strtolower((string) $r['name']), $q)));
        }

        return $d;
    }

    private function tabelAgingSupplier(array $d, bool $excel): array
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
        $sel = fn (string $isi, bool $kanan = true) => $excel ? '<td>'.$isi.'</td>' : '<td'.($kanan ? ' class="r"' : '').'>'.$isi.'</td>';
        $baris = function (string $nama, array $r, string $gaya = '') use ($e, $rp, $sel) {
            return '<tr'.$gaya.'>'.$sel($e($nama), false).$sel($rp($r['total'])).$sel($rp($r['b0'])).$sel($rp($r['b31'])).$sel($rp($r['b60'])).'</tr>';
        };

        $html = '';
        foreach ($d['rows'] as $r) {
            $html .= $baris($r['name'], $r);
        }
        if ($d['others']) {
            $html .= $baris('Others ('.$d['others']['jumlah'].' supplier lainnya)', $d['others'], ' style="font-style:italic"');
        }
        if ($d['rows'] === []) {
            $html .= '<tr><td colspan="5" style="text-align:center">'
                .(($d['cari'] ?? '') !== '' ? 'Tidak ada supplier yang cocok dengan pencarian "'.$e($d['cari']).'".' : 'Tidak ada hutang outstanding.')
                .'</td></tr>';
        }
        $html .= $baris('TOTAL ('.$d['total']['jumlah'].' supplier)', $d['total'], ' style="font-weight:bold;background:#e2e8f0"');

        $periode = ($d['dateFrom'] ? $e($d['dateFrom']).' s/d ' : 'sampai ').$e($d['dateTo']);
        $posisi = collect($d['posisiOpsi'])->firstWhere('nilai', $d['posisi'])['label'] ?? 'Semua';

        return [$periode, $e($posisi), $html];
    }

    public function agingSupplierExcel(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('ap.aging.export'), 403);
        $d = $this->cariAgingSupplier($this->dataAgingSupplier($request), $request);
        [$periode, $posisi, $baris] = $this->tabelAgingSupplier($d, true);
        $co = htmlspecialchars((string) config('business_support.company.name', 'PT. Mitra Usaha Propertindo'), ENT_QUOTES, 'UTF-8');
        $judul = $d['tampil'] === 'semua' ? 'seluruh supplier' : $d['topN'].' terbesar';

        $html = '<table border="1"><tr><td colspan="5" style="font-weight:bold">AGING HUTANG SUPPLIER — '.$co.'</td></tr>'
            .'<tr><td colspan="5">Periode '.$periode.' · Posisi: '.$posisi.' · Tampil: '.$judul.' · Cetak '.now()->format('d-m-Y H:i').'</td></tr>'
            .'<tr style="background:#0f766e;color:#fff"><th>Supplier</th><th>Outstanding</th><th>0-30</th><th>31-60</th><th>&gt; 60</th></tr>'
            .$baris
            .'<tr><td colspan="5">Kolom 0-30 sudah termasuk hutang yang belum jatuh tempo.</td></tr></table>';
        $file = 'Aging-Hutang-Supplier-'.now()->format('Ymd-His').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'.$html.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /** Cetak Aging Hutang Supplier (HTML siap-cetak / simpan PDF) — auto-print, gaya sama dgn Aging Hutang. */
    public function agingSupplierPdf(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('ap.aging.export'), 403);
        $d = $this->cariAgingSupplier($this->dataAgingSupplier($request), $request);
        [$periode, $posisi, $baris] = $this->tabelAgingSupplier($d, false);
        $co = htmlspecialchars((string) config('business_support.company.name', 'PT. Mitra Usaha Propertindo'), ENT_QUOTES, 'UTF-8');
        $judul = $d['tampil'] === 'semua' ? 'Seluruh supplier' : $d['topN'].' supplier terbesar, sisanya dirangkum';

        $html = '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Aging Hutang Supplier</title><style>
            @page{size:A4 portrait;margin:14mm}*{box-sizing:border-box}body{font-family:"Segoe UI",Arial,sans-serif;font-size:11px;color:#1f2937;margin:0}
            h1{font-size:16px;margin:0 0 2px;color:#b91c1c}
            .head{border-bottom:2px solid #b91c1c;padding-bottom:8px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:flex-start}
            .muted{color:#6b7280;font-size:10px}table{border-collapse:collapse;width:100%;margin:4px 0;font-size:10px}
            th,td{border:1px solid #cbd5e1;padding:4px 6px;text-align:left}.r{text-align:right;font-variant-numeric:tabular-nums}
            thead th{background:#f1f5f9}
            @media print{.noprint{display:none}}</style></head><body>
            <div class="head"><div><h1>AGING HUTANG SUPPLIER</h1><div class="muted">'.$co.'</div></div>
            <div class="muted" style="text-align:right">Periode: '.$periode.'<br>Posisi: '.$posisi.' · '.htmlspecialchars($judul, ENT_QUOTES, 'UTF-8').'<br>Cetak: '.htmlspecialchars(now()->translatedFormat('d F Y H:i'), ENT_QUOTES, 'UTF-8').'</div></div>
            <table><thead><tr><th>Supplier</th><th class="r">Outstanding</th><th class="r">0–30</th><th class="r">31–60</th><th class="r">&gt; 60</th></tr></thead>
            <tbody>'.$baris.'</tbody></table>
            <div class="muted" style="margin-top:6px">Kolom 0–30 sudah termasuk hutang yang belum jatuh tempo.</div>
            <button class="noprint" onclick="window.print()" style="margin-top:14px;padding:6px 14px">Cetak</button>
            <script>window.onload=function(){setTimeout(function(){window.print()},300)}</script></body></html>';

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Aging Hutang — umur hutang per supplier (Summary) atau per invoice (Detail). */
    public function aging(Request $request): Response
    {
        abort_unless($request->user()?->can('ap.aging.view'), 403);

        $view = $request->input('view') === 'detail' ? 'detail' : 'summary';
        $pulled = $request->boolean('pull'); // data baru ditarik setelah klik "Tampilkan" → hindari load berat
        $dateFrom = $request->input('date_from') ?: null;
        $dateTo = $request->input('date_to') ?: null;
        $asOfStr = $dateTo ?: now()->toDateString();

        $rows = [];
        $details = [];
        $totals = ['current' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'd90p' => 0.0, 'total' => 0.0];
        if ($pulled) {
            [$rowsC, $totals, $details, $asOf, $dateFrom] = $this->computeAging($request, $dateTo, $dateFrom);
            $rows = array_values($rowsC);
            $asOfStr = $asOf->toDateString();
        }

        return Inertia::render('BusinessSupport/Payable/Aging', [
            'view' => $view,
            'rows' => $rows,
            'totals' => $totals,
            'details' => $details,
            'dateFrom' => $dateFrom,
            'dateTo' => $asOfStr,
            'pulled' => $pulled,
            'posisi' => PosisiHutang::dariRequest($request),
            'posisiOpsi' => PosisiHutang::opsi(),
        ]);
    }

    /**
     * Perhitungan aging hutang — dipakai layar & export (#139).
     * date_to = tanggal acuan aging (default hari ini); date_from = batas bawah tanggal invoice (opsional).
     *
     * @return array{0:array<int,array<string,mixed>>,1:array<string,float>,2:array<int,array<string,mixed>>,3:\Illuminate\Support\Carbon,4:?string}
     */
    /**
     * Nama supplier untuk laporan hutang — tidak pernah kosong.
     *
     * Sejak migrasi 2026_08_29_100000, supplier_id WAJIB terisi dan ber-foreign key, dan
     * relasinya memuat supplier yang sudah dihapus sekalipun. Jadi nama kosong seharusnya
     * mustahil. Kalau toh terjadi, id-nya DISEBUT — bukan diganti "—" yang membuat hutang
     * milik banyak pihak menyatu jadi satu baris tak bernama yang tidak bisa ditelusuri.
     */
    private function namaSupplier(?object $inv): string
    {
        $s = $inv?->supplier;
        if ($s === null) {
            return $inv?->supplier_id ? 'Supplier #'.$inv->supplier_id.' (tidak ditemukan)' : 'TANPA SUPPLIER';
        }
        // Supplier terhapus tetap disebut namanya, dengan penanda supaya jelas kenapa ia
        // tidak muncul lagi di daftar pilihan.
        //
        // deleted_at dibaca lewat getAttributes(), BUKAN $s->deleted_at: aplikasi ini berjalan
        // dengan mode atribut ketat, sehingga membaca kolom yang tidak ikut ter-select melempar
        // exception dan MENJATUHKAN SELURUH layar Aging. Satu eager-load yang lupa menyebut
        // deleted_at tidak boleh berakibat sebesar itu — di sini ia cukup kehilangan penandanya.
        $terhapus = ($s->getAttributes()['deleted_at'] ?? null) !== null;

        return $terhapus ? $s->name.' (dihapus)' : (string) $s->name;
    }

    /**
     * $request diminta sebagai argumen PERTAMA dan WAJIB — bukan sekadar gaya.
     *
     * Penyaring posisi hutang harus berlaku di keempat pemanggil (layar Aging, Aging Supplier,
     * ekspor Excel, cetak PDF). Bila posisinya dikirim sebagai argumen ketiga yang opsional,
     * pemanggil yang terlupa akan tetap berjalan dan diam-diam memulangkan SELURUH posisi —
     * berkas ekspor berbeda dari layarnya, tanpa satu pun galat. Dengan Request sebagai argumen
     * wajib, pemanggil yang terlupa gagal sebagai TypeError, bukan sebagai angka yang salah.
     */
    private function computeAging(Request $request, ?string $dateTo = null, ?string $dateFrom = null): array
    {
        $posisi = PosisiHutang::dariRequest($request);

        $asOf = $dateTo ? \Illuminate\Support\Carbon::parse($dateTo)->startOfDay() : now()->startOfDay();
        $from = $dateFrom ?: null;
        $rows = [];
        $details = [];
        $totals = ['current' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'd90p' => 0.0, 'total' => 0.0];
        $bucketLabel = ['current' => 'Belum JT', 'd30' => '1–30', 'd60' => '31–60', 'd90' => '61–90', 'd90p' => '>90'];

        // Basis NET & cutoff per tanggal acuan IDENTIK dgn Kartu Hutang: outstanding = net_payable
        // (setelah PPN & PPh) − Σ(pembayaran s/d tanggal acuan). TANPA filter status (status = kondisi
        // KINI, bukan posisi per-tanggal) → angka per-supplier sama persis dgn Saldo Hutang Kartu.
        $asOfStr = $asOf->toDateString();
        PosisiHutang::saring(
            BsApInvoice::with(['supplier:id,code,name,deleted_at', 'payments' => fn ($q) => $q->whereDate('payment_date', '<=', $asOfStr)]),
            $posisi,
        )
            ->when($from, fn ($q, $v) => $q->whereDate('invoice_date', '>=', $v))
            ->whereDate('invoice_date', '<=', $asOfStr)
            ->get()->each(function ($inv) use (&$rows, &$details, &$totals, $asOf, $bucketLabel): void {
                $paidToDate = (float) $inv->payments->sum('amount');
                $out = round((float) $inv->payable_amount - $paidToDate, 2); // NET outstanding per tanggal acuan
                if ($out <= 0.009) {
                    return;
                }
                $ref = $inv->due_date ?? $inv->invoice_date;
                $overdue = $ref && $ref->lt($asOf) ? (int) $ref->diffInDays($asOf) : 0;
                $bucket = $overdue <= 0 ? 'current' : ($overdue <= 30 ? 'd30' : ($overdue <= 60 ? 'd60' : ($overdue <= 90 ? 'd90' : 'd90p')));

                $key = $inv->supplier_id;
                $rows[$key] ??= ['name' => $this->namaSupplier($inv), 'current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90p' => 0, 'total' => 0];
                $rows[$key][$bucket] += $out;
                $rows[$key]['total'] += $out;
                $totals[$bucket] += $out;
                $totals['total'] += $out;

                $details[] = [
                    'supplier' => $this->namaSupplier($inv),
                    'invoice_no' => $inv->invoice_number,
                    'due_date' => $ref?->toDateString(),
                    'overdue' => $overdue,
                    'bucket' => $bucketLabel[$bucket],
                    'outstanding' => round($out, 2),
                ];
            });

        usort($details, fn ($a, $b) => $b['overdue'] <=> $a['overdue']);

        return [$rows, $totals, $details, $asOf, $from];
    }

    /** #139: Export Aging Hutang ke Excel (.xls) — ringkasan per supplier + detail per invoice. */
    public function agingExportExcel(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('ap.aging.export'), 403);

        [$rows, $totals, $details, $asOf, $dateFrom] = $this->computeAging($request, $request->input('date_to'), $request->input('date_from'));

        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');

        $period = ($dateFrom ? $e($dateFrom).' s/d ' : 'sampai ').$e($asOf->toDateString());

        $sum = '<table border="1"><tr><td colspan="7" style="font-weight:bold">AGING HUTANG (per '.$period.')</td></tr>'
            .'<tr style="background:#0f766e;color:#fff"><th>Supplier</th><th>Belum JT</th><th>1-30</th><th>31-60</th><th>61-90</th><th>&gt;90</th><th>Total</th></tr>';
        foreach (array_values($rows) as $r) {
            $sum .= '<tr><td>'.$e($r['name']).'</td><td>'.$rp($r['current']).'</td><td>'.$rp($r['d30']).'</td><td>'.$rp($r['d60']).'</td><td>'.$rp($r['d90']).'</td><td>'.$rp($r['d90p']).'</td><td>'.$rp($r['total']).'</td></tr>';
        }
        $sum .= '<tr style="font-weight:bold;background:#e2e8f0"><td>TOTAL</td><td>'.$rp($totals['current']).'</td><td>'.$rp($totals['d30']).'</td><td>'.$rp($totals['d60']).'</td><td>'.$rp($totals['d90']).'</td><td>'.$rp($totals['d90p']).'</td><td>'.$rp($totals['total']).'</td></tr></table><br>';

        $det = '<table border="1"><tr style="background:#0f766e;color:#fff"><th>Supplier</th><th>No Invoice</th><th>Jatuh Tempo</th><th>Hari</th><th>Bucket</th><th>Outstanding</th></tr>';
        foreach ($details as $d) {
            $det .= '<tr><td>'.$e($d['supplier']).'</td><td>'.$e($d['invoice_no']).'</td><td>'.$e($d['due_date']).'</td><td>'.$e($d['overdue']).'</td><td>'.$e($d['bucket']).'</td><td>'.$rp($d['outstanding']).'</td></tr>';
        }
        $det .= '</table>';

        $file = 'Aging-Hutang-'.now()->format('Ymd-His').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'.$sum.$det.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /** #139: Cetak Aging Hutang (HTML siap-cetak / PDF) — auto-print. */
    public function agingExportPdf(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('ap.aging.export'), 403);

        [$rows, $totals, $details, $asOf, $dateFrom] = $this->computeAging($request, $request->input('date_to'), $request->input('date_from'));

        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
        $co = (string) config('business_support.company.name', 'PT. Mitra Usaha Propertindo');
        $now = now()->translatedFormat('d F Y H:i');
        $period = ($dateFrom ? $e($dateFrom).' s/d ' : 'sampai ').$e($asOf->translatedFormat('d F Y'));

        $sumRows = '';
        foreach (array_values($rows) as $r) {
            $sumRows .= '<tr><td>'.$e($r['name']).'</td><td class="r">'.$rp($r['current']).'</td><td class="r">'.$rp($r['d30']).'</td><td class="r">'.$rp($r['d60']).'</td><td class="r">'.$rp($r['d90']).'</td><td class="r">'.$rp($r['d90p']).'</td><td class="r">'.$rp($r['total']).'</td></tr>';
        }
        if ($sumRows === '') {
            $sumRows = '<tr><td colspan="7" class="muted" style="text-align:center">Tidak ada hutang outstanding.</td></tr>';
        }
        $totRow = '<tr class="tot"><td>TOTAL</td><td class="r">'.$rp($totals['current']).'</td><td class="r">'.$rp($totals['d30']).'</td><td class="r">'.$rp($totals['d60']).'</td><td class="r">'.$rp($totals['d90']).'</td><td class="r">'.$rp($totals['d90p']).'</td><td class="r">'.$rp($totals['total']).'</td></tr>';

        $detRows = '';
        foreach ($details as $d) {
            $detRows .= '<tr><td>'.$e($d['supplier']).'</td><td>'.$e($d['invoice_no']).'</td><td>'.$e($d['due_date']).'</td><td class="r">'.$e($d['overdue']).'</td><td>'.$e($d['bucket']).'</td><td class="r">'.$rp($d['outstanding']).'</td></tr>';
        }
        $detBlock = $detRows !== ''
            ? '<h3>Detail per Invoice</h3><table><thead><tr><th>Supplier</th><th>No Invoice</th><th>Jatuh Tempo</th><th class="r">Hari</th><th>Bucket</th><th class="r">Outstanding</th></tr></thead><tbody>'.$detRows.'</tbody></table>'
            : '';

        $html = '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Aging Hutang</title><style>
            @page{size:A4 landscape;margin:14mm}*{box-sizing:border-box}body{font-family:"Segoe UI",Arial,sans-serif;font-size:11px;color:#1f2937;margin:0}
            h1{font-size:16px;margin:0 0 2px;color:#b91c1c}h3{font-size:12px;margin:14px 0 4px;color:#991b1b}
            .head{border-bottom:2px solid #b91c1c;padding-bottom:8px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:flex-start}
            .muted{color:#6b7280;font-size:10px}table{border-collapse:collapse;width:100%;margin:4px 0;font-size:10px}
            th,td{border:1px solid #cbd5e1;padding:4px 6px;text-align:left}.r{text-align:right;font-variant-numeric:tabular-nums}
            thead th{background:#f1f5f9}.tot{font-weight:bold;background:#e2e8f0}
            @media print{.noprint{display:none}}</style></head><body>
            <div class="head"><div><h1>AGING HUTANG (Account Payable)</h1><div class="muted">'.$e($co).'</div></div>
            <div class="muted" style="text-align:right">Periode: '.$period.'<br>Cetak: '.$e($now).'</div></div>
            <h3>Ringkasan per Supplier</h3>
            <table><thead><tr><th>Supplier</th><th class="r">Belum JT</th><th class="r">1–30</th><th class="r">31–60</th><th class="r">61–90</th><th class="r">&gt;90</th><th class="r">Total</th></tr></thead>
            <tbody>'.$sumRows.'</tbody><tfoot>'.$totRow.'</tfoot></table>
            '.$detBlock.'
            <button class="noprint" onclick="window.print()" style="margin-top:14px;padding:6px 14px">Cetak</button>
            <script>window.onload=function(){setTimeout(function(){window.print()},300)}</script></body></html>';

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Kartu Hutang — mutasi per supplier (Detail) atau rekap per supplier (Summary). */
    public function card(Request $request): Response
    {
        abort_unless($request->user()?->can('ap.paycard.view'), 403);

        $d = $this->computeCard($request);

        return Inertia::render('BusinessSupport/Payable/Card', $d);
    }

    /**
     * Hitungan Kartu Hutang — SATU sumber untuk layar, Excel, dan PDF.
     *
     * Sengaja tidak disalin ke masing-masing ekspor. Kartu Hutang memakai basis NET
     * (net_payable, setelah PPN & PPh) dengan cutoff s/d date_to, dan angkanya harus sama
     * persis dengan Aging Hutang. Salinan yang terpisah akan menyimpang perlahan setiap kali
     * salah satunya disesuaikan — dan berkas ekspor yang berbeda dari layarnya tidak akan
     * ketahuan sampai ada yang menjumlahkan ulang dengan tangan.
     *
     * @return array<string,mixed>
     */
    private function computeCard(Request $request): array
    {
        $view = $request->input('view') === 'summary' ? 'summary' : 'detail';
        $supplierId = $request->integer('supplier_id') ?: null;
        $posisi = PosisiHutang::dariRequest($request);
        // #138: filter periode — invoice by invoice_date, pembayaran by payment_date.
        $dateFrom = $request->input('date_from') ?: null;
        $dateTo = $request->input('date_to') ?: null;
        $entries = collect();
        $openingBalance = 0.0;

        // KUNCI sinkron Detail↔Summary: keduanya pakai basis NET (payable_amount = net_payable =
        // total − PPh, sudah termasuk PPN) & cutoff yang sama (s/d date_to). Saldo akhir Detail
        // === Outstanding Summary untuk supplier yang sama.
        if ($supplierId) {
            $invoices = PosisiHutang::saring(BsApInvoice::where('supplier_id', $supplierId), $posisi)
                ->when($dateTo, fn ($q, $v) => $q->whereDate('invoice_date', '<=', $v)) // posisi hutang s/d date_to
                ->with(['payments' => fn ($q) => $q->when($dateTo, fn ($w, $v) => $w->whereDate('payment_date', '<=', $v))])
                ->get();

            $raw = collect();
            foreach ($invoices as $inv) {
                // Tagihan = NET (net_payable, setelah PPN & PPh) — bukan gross total.
                $raw->push(['date' => $inv->invoice_date?->format('Y-m-d'), 'ref' => $inv->invoice_number, 'desc' => $inv->description, 'debit' => 0.0, 'credit' => round((float) $inv->payable_amount, 2)]);
                foreach ($inv->payments as $p) {
                    // Bayar = nominal yang benar-benar dibayar (NET kas).
                    $raw->push(['date' => $p->payment_date?->format('Y-m-d'), 'ref' => $inv->invoice_number, 'desc' => 'Pembayaran'.($p->reference ? ' · '.$p->reference : ''), 'debit' => round((float) $p->amount, 2), 'credit' => 0.0]);
                }
            }
            $raw = $raw->sortBy('date')->values();

            // Saldo Awal = akumulasi NET sebelum date_from (hutang yang terbawa ke periode).
            $window = $raw;
            if ($dateFrom) {
                $openingBalance = round((float) $raw->filter(fn ($e) => $e['date'] !== null && $e['date'] < $dateFrom)->sum(fn ($e) => $e['credit'] - $e['debit']), 2);
                $window = $raw->filter(fn ($e) => $e['date'] === null || $e['date'] >= $dateFrom)->values();
            }

            $running = $openingBalance;
            $entries = $window->map(function ($e) use (&$running) {
                $running = round($running + $e['credit'] - $e['debit'], 2); // hutang +saat invoice, −saat bayar

                return [...$e, 'balance' => $running];
            });
        }

        // Summary: rekap per supplier dgn basis & cutoff IDENTIK dengan Detail → Outstanding == Saldo Hutang.
        $summary = collect();
        if ($view === 'summary') {
            $invs = PosisiHutang::saring(BsApInvoice::with(['supplier:id,code,name,deleted_at', 'payments' => fn ($q) => $q->when($dateTo, fn ($w, $v) => $w->whereDate('payment_date', '<=', $v))]), $posisi)
                ->when($supplierId, fn ($q, $v) => $q->where('supplier_id', $v))
                ->when($dateTo, fn ($q, $v) => $q->whereDate('invoice_date', '<=', $v))
                ->get();
            $summary = $invs->groupBy('supplier_id')
                ->map(function ($g) {
                    $tertagih = round((float) $g->sum(fn ($i) => (float) $i->payable_amount), 2);          // NET tagihan
                    $terbayar = round((float) $g->sum(fn ($i) => (float) $i->payments->sum('amount')), 2); // NET dibayar s/d date_to

                    return [
                        'name' => $this->namaSupplier($g->first()),
                        'invoiced' => $tertagih,
                        'paid' => $terbayar,
                        'outstanding' => round($tertagih - $terbayar, 2),
                    ];
                })
                ->filter(fn ($r) => $r['outstanding'] > 0.009)
                ->sortByDesc('outstanding')->values();
        }

        $closing = $entries->isNotEmpty() ? (float) $entries->last()['balance'] : $openingBalance;

        return [
            'view' => $view,
            'suppliers' => BsSupplier::orderBy('name')->get(['id', 'code', 'name']),
            'supplierId' => $supplierId,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'entries' => $entries,
            'openingBalance' => round((float) $openingBalance, 2),
            'summary' => $summary,
            'outstanding' => round((float) $closing, 2), // = Saldo Akhir Detail (sinkron dgn Outstanding Summary)
            'posisi' => $posisi,
            'posisiOpsi' => PosisiHutang::opsi(),
        ];
    }

    /**
     * Kartu Hutang → Excel.
     *
     * Memakai computeCard() yang SAMA dengan layarnya, jadi angkanya tidak mungkin berbeda.
     */
    public function cardExportExcel(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('ap.paycard.view'), 403);

        $d = $this->computeCard($request);
        $sup = $d['supplierId'] ? $d['suppliers']->firstWhere('id', $d['supplierId']) : null;

        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
        // Kolom identitas dipaksa jadi TEKS: tanpa ini Excel membaca "AP/2608/0012" sebagai
        // tanggal atau memangkas nol di depannya.
        $t = fn ($v) => '<td style="mso-number-format:\'\@\'">'.$e($v).'</td>';

        $periode = ($d['dateFrom'] ? $e($d['dateFrom']).' s/d ' : 'sampai ').$e($d['dateTo'] ?: now()->toDateString());
        $judul = $sup ? $e($sup->code.' — '.$sup->name) : 'SEMUA SUPPLIER';

        $h = '<table border="1">'
            .'<tr><td colspan="6" style="font-weight:bold">KARTU HUTANG — '.$judul.'</td></tr>'
            .'<tr><td colspan="6">Periode: '.$periode.'</td></tr>'
            .'<tr><td colspan="6">Tagihan &amp; Bayar ditampilkan NET (setelah PPN &amp; PPh).</td></tr>'
            .'<tr><td colspan="6"></td></tr>';

        if ($d['view'] === 'summary') {
            $h .= '<tr style="background:#b91c1c;color:#fff"><th>No</th><th>Supplier</th><th>Tertagih</th><th>Terbayar</th><th>Outstanding</th><th></th></tr>';
            $no = 0;
            foreach ($d['summary'] as $r) {
                $no++;
                $h .= '<tr>'.$t($no).$t($r['name']).'<td>'.$rp($r['invoiced']).'</td><td>'.$rp($r['paid']).'</td><td>'.$rp($r['outstanding']).'</td><td></td></tr>';
            }
            $h .= '<tr style="font-weight:bold;background:#e2e8f0"><td colspan="4">TOTAL OUTSTANDING</td><td>'
                .$rp(collect($d['summary'])->sum('outstanding')).'</td><td></td></tr>';
        } else {
            $h .= '<tr style="background:#b91c1c;color:#fff"><th>Tanggal</th><th>Referensi</th><th>Keterangan</th><th>Bayar</th><th>Tagihan</th><th>Saldo</th></tr>'
                .'<tr>'.$t('').$t('').$t('Saldo Awal').'<td></td><td></td><td>'.$rp($d['openingBalance']).'</td></tr>';
            foreach ($d['entries'] as $r) {
                $h .= '<tr>'.$t($r['date']).$t($r['ref']).$t($r['desc'])
                    .'<td>'.($r['debit'] > 0 ? $rp($r['debit']) : '').'</td>'
                    .'<td>'.($r['credit'] > 0 ? $rp($r['credit']) : '').'</td>'
                    .'<td>'.$rp($r['balance']).'</td></tr>';
            }
            $h .= '<tr style="font-weight:bold;background:#e2e8f0"><td colspan="3">SALDO HUTANG</td>'
                .'<td>'.$rp(collect($d['entries'])->sum('debit')).'</td>'
                .'<td>'.$rp(collect($d['entries'])->sum('credit')).'</td>'
                .'<td>'.$rp($d['outstanding']).'</td></tr>';
        }
        $h .= '</table>';

        $file = 'Kartu-Hutang-'.($sup ? preg_replace('/[^A-Za-z0-9]+/', '-', (string) $sup->code).'-' : '').now()->format('Ymd-His').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'.$h.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /** Kartu Hutang → HTML siap-cetak (PDF), auto-print. Pola sama dengan Aging Hutang. */
    public function cardExportPdf(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('ap.paycard.view'), 403);

        $d = $this->computeCard($request);
        $sup = $d['supplierId'] ? $d['suppliers']->firstWhere('id', $d['supplierId']) : null;

        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
        $co = (string) config('business_support.company.name', 'PT. Mitra Usaha Propertindo');
        $now = now()->translatedFormat('d F Y H:i');
        $periode = ($d['dateFrom'] ? $e($d['dateFrom']).' s/d ' : 'sampai ').$e($d['dateTo'] ?: now()->toDateString());
        $judul = $sup ? $e($sup->code.' — '.$sup->name) : 'Semua Supplier';

        if ($d['view'] === 'summary') {
            $baris = '';
            foreach ($d['summary'] as $r) {
                $baris .= '<tr><td>'.$e($r['name']).'</td><td class="r">'.$rp($r['invoiced']).'</td><td class="r">'.$rp($r['paid']).'</td><td class="r">'.$rp($r['outstanding']).'</td></tr>';
            }
            if ($baris === '') {
                $baris = '<tr><td colspan="4" class="muted" style="text-align:center">Tidak ada hutang outstanding.</td></tr>';
            }
            $tabel = '<table><thead><tr><th>Supplier</th><th class="r">Tertagih</th><th class="r">Terbayar</th><th class="r">Outstanding</th></tr></thead>'
                .'<tbody>'.$baris.'</tbody>'
                .'<tfoot><tr class="tot"><td>TOTAL</td><td class="r"></td><td class="r"></td><td class="r">'.$rp(collect($d['summary'])->sum('outstanding')).'</td></tr></tfoot></table>';
        } else {
            $baris = '<tr><td></td><td></td><td>Saldo Awal</td><td class="r"></td><td class="r"></td><td class="r">'.$rp($d['openingBalance']).'</td></tr>';
            foreach ($d['entries'] as $r) {
                $baris .= '<tr><td>'.$e($r['date']).'</td><td>'.$e($r['ref']).'</td><td>'.$e($r['desc']).'</td>'
                    .'<td class="r">'.($r['debit'] > 0 ? $rp($r['debit']) : '').'</td>'
                    .'<td class="r">'.($r['credit'] > 0 ? $rp($r['credit']) : '').'</td>'
                    .'<td class="r">'.$rp($r['balance']).'</td></tr>';
            }
            $tabel = '<table><thead><tr><th>Tanggal</th><th>Referensi</th><th>Keterangan</th><th class="r">Bayar</th><th class="r">Tagihan</th><th class="r">Saldo</th></tr></thead>'
                .'<tbody>'.$baris.'</tbody>'
                .'<tfoot><tr class="tot"><td colspan="3">SALDO HUTANG</td>'
                .'<td class="r">'.$rp(collect($d['entries'])->sum('debit')).'</td>'
                .'<td class="r">'.$rp(collect($d['entries'])->sum('credit')).'</td>'
                .'<td class="r">'.$rp($d['outstanding']).'</td></tr></tfoot></table>';
        }

        $html = '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Kartu Hutang</title><style>
            @page{size:A4;margin:14mm}*{box-sizing:border-box}body{font-family:"Segoe UI",Arial,sans-serif;font-size:11px;color:#1f2937;margin:0}
            h1{font-size:16px;margin:0 0 2px;color:#b91c1c}h3{font-size:12px;margin:14px 0 4px;color:#991b1b}
            .head{border-bottom:2px solid #b91c1c;padding-bottom:8px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:flex-start}
            .muted{color:#6b7280;font-size:10px}table{border-collapse:collapse;width:100%;margin:4px 0;font-size:10px}
            th,td{border:1px solid #cbd5e1;padding:4px 6px;text-align:left}.r{text-align:right;font-variant-numeric:tabular-nums}
            thead th{background:#f1f5f9}.tot{font-weight:bold;background:#e2e8f0}
            @media print{.noprint{display:none}}</style></head><body>
            <div class="head"><div><h1>KARTU HUTANG</h1><div class="muted">'.$e($co).'</div></div>
            <div class="muted" style="text-align:right">Periode: '.$periode.'<br>Cetak: '.$e($now).'</div></div>
            <h3>'.$judul.'</h3>
            <div class="muted">Tagihan &amp; Bayar ditampilkan NET (setelah PPN &amp; PPh); basis &amp; cutoff sama dengan Aging Hutang.</div>
            '.$tabel.'
            <button class="noprint" onclick="window.print()" style="margin-top:14px;padding:6px 14px">Cetak</button>
            <script>window.onload=function(){setTimeout(function(){window.print()},300)}</script></body></html>';

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Kartu Pembayaran Hutang — riwayat pembayaran (BsApPayment) per supplier. */
    public function paymentCard(Request $request): Response
    {
        abort_unless($request->user()?->can('ap.paycard.view'), 403);

        $supplierId = $request->integer('supplier_id') ?: null;
        $posisi = PosisiHutang::dariRequest($request);
        $bySupplier = fn ($q) => $q->whereHas('invoice', fn ($w) => $w->where('supplier_id', $supplierId));

        $payments = PosisiHutang::saringLewat(
            BsApPayment::query()->with(['invoice:id,invoice_number,supplier_id', 'invoice.supplier:id,code,name']),
            $posisi,
            'invoice',
        )
            ->when($supplierId, $bySupplier)
            ->orderByDesc('payment_date')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        return Inertia::render('BusinessSupport/Payable/PaymentCard', [
            'payments' => $payments,
            'suppliers' => BsSupplier::orderBy('name')->get(['id', 'code', 'name']),
            'supplierId' => $supplierId,
            // Query TERPISAH dari daftar di atas — penyaringnya WAJIB ikut di sini juga, kalau
            // tidak angka Total menjadi lebih besar daripada jumlah baris yang ditampilkannya.
            'total' => round((float) PosisiHutang::saringLewat(BsApPayment::query(), $posisi, 'invoice')->when($supplierId, $bySupplier)->sum('amount'), 2),
            'posisi' => $posisi,
            'posisiOpsi' => PosisiHutang::opsi(),
        ]);
    }

    /** Data Kartu Pembayaran Hutang (seluruh riwayat, tanpa paginasi) — dipakai layar export. */
    private function paymentCardData(Request $request): array
    {
        $supplierId = $request->integer('supplier_id') ?: null;
        $bySupplier = fn ($q) => $q->whereHas('invoice', fn ($w) => $w->where('supplier_id', $supplierId));
        $payments = PosisiHutang::saringLewat(
            BsApPayment::query()->with(['invoice:id,invoice_number,supplier_id', 'invoice.supplier:id,code,name']),
            PosisiHutang::dariRequest($request),
            'invoice',
        )
            ->when($supplierId, $bySupplier)
            ->orderByDesc('payment_date')->orderByDesc('id')->get();
        $supplierName = $supplierId ? (BsSupplier::find($supplierId)?->name ?? '-') : 'Semua Supplier';

        return [$payments, round((float) $payments->sum('amount'), 2), $supplierName];
    }

    /** Export Kartu Pembayaran Hutang ke Excel (.xls). */
    public function paymentCardExportExcel(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('ap.paycard.view'), 403);
        [$payments, $total, $supplierName] = $this->paymentCardData($request);

        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');

        $h = '<table border="1"><tr><td colspan="5" style="font-weight:bold">KARTU PEMBAYARAN HUTANG — '.$e($supplierName).'</td></tr>'
            .'<tr style="background:#0f766e;color:#fff"><th>Tanggal Bayar</th><th>Supplier</th><th>No Invoice</th><th>Referensi</th><th>Nominal</th></tr>';
        foreach ($payments as $p) {
            $h .= '<tr><td>'.$e(optional($p->payment_date)->toDateString()).'</td><td>'.$e($p->invoice?->supplier?->name).'</td><td>'.$e($p->invoice?->invoice_number).'</td><td>'.$e($p->reference).'</td><td>'.$rp($p->amount).'</td></tr>';
        }
        $h .= '<tr style="font-weight:bold;background:#e2e8f0"><td colspan="4">TOTAL</td><td>'.$rp($total).'</td></tr></table>';

        $file = 'Kartu-Pembayaran-Hutang-'.now()->format('Ymd-His').'.xls';

        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'.$h.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /** Cetak Kartu Pembayaran Hutang (HTML siap-cetak / PDF) — auto-print. */
    public function paymentCardExportPdf(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('ap.paycard.view'), 403);
        [$payments, $total, $supplierName] = $this->paymentCardData($request);

        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
        $co = (string) config('business_support.company.name', 'PT. Mitra Usaha Propertindo');
        $now = now()->translatedFormat('d F Y H:i');

        $rows = '';
        foreach ($payments as $p) {
            $rows .= '<tr><td>'.$e(optional($p->payment_date)->translatedFormat('d M Y')).'</td><td>'.$e($p->invoice?->supplier?->name).'</td><td>'.$e($p->invoice?->invoice_number).'</td><td>'.$e($p->reference).'</td><td class="r">'.$rp($p->amount).'</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="muted" style="text-align:center">Belum ada pembayaran.</td></tr>';
        }
        $totRow = '<tr class="tot"><td colspan="4">TOTAL</td><td class="r">'.$rp($total).'</td></tr>';

        $html = '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Kartu Pembayaran Hutang</title><style>
            @page{size:A4;margin:14mm}*{box-sizing:border-box}body{font-family:"Segoe UI",Arial,sans-serif;font-size:11px;color:#1f2937;margin:0}
            h1{font-size:16px;margin:0 0 2px;color:#b91c1c}
            .head{border-bottom:2px solid #b91c1c;padding-bottom:8px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:flex-start}
            .muted{color:#6b7280;font-size:10px}table{border-collapse:collapse;width:100%;margin:4px 0;font-size:10px}
            th,td{border:1px solid #cbd5e1;padding:4px 6px;text-align:left}.r{text-align:right;font-variant-numeric:tabular-nums}
            thead th{background:#f1f5f9}.tot{font-weight:bold;background:#e2e8f0}
            @media print{.noprint{display:none}}</style></head><body>
            <div class="head"><div><h1>KARTU PEMBAYARAN HUTANG</h1><div class="muted">'.$e($co).'</div></div>
            <div class="muted" style="text-align:right">Supplier: '.$e($supplierName).'<br>Cetak: '.$e($now).'</div></div>
            <table><thead><tr><th>Tanggal Bayar</th><th>Supplier</th><th>No Invoice</th><th>Referensi</th><th class="r">Nominal</th></tr></thead>
            <tbody>'.$rows.'</tbody><tfoot>'.$totRow.'</tfoot></table>
            <button class="noprint" onclick="window.print()" style="margin-top:14px;padding:6px 14px">Cetak</button>
            <script>window.onload=function(){setTimeout(function(){window.print()},300)}</script></body></html>';

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function accId(string $key): ?int
    {
        $code = config("business_support.gl_accounts.$key");

        return $code ? BsAccount::where('code', $code)->value('id') : null;
    }
}
