<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\CashBook;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsOutgoingCheque;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Domain\BusinessSupport\Services\OutgoingChequeService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Support\Xlsx\SimpleXlsx;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * #55 — Arus keluar BG/Cek perusahaan: register + dashboard + alat cetak BG/Cek.
 * #67/#68/#70/#71/#72/#73/#74 — dua penandatangan, status void, import stock,
 * datalist nomor warkat, opsi cetak, bank/rekening penerima (Bilyet Giro).
 */
class OutgoingChequeController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $rows = BsOutgoingCheque::query()
            ->with('bankAccount:id,code,name')
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('type'), fn ($q, $v) => $q->where('cheque_type', $v))
            ->when($request->input('bank_account_id'), fn ($q, $v) => $q->where('bank_account_id', $v))
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('cheque_number', 'like', "%{$s}%")->orWhere('payee', 'like', "%{$s}%")))
            ->latest('cheque_date')->latest('id')
            ->paginate(50)->withQueryString();

        $bucket = fn (string $status) => [
            'count' => BsOutgoingCheque::where('status', $status)->count(),
            'total' => (float) BsOutgoingCheque::where('status', $status)->sum('amount'),
        ];

        return Inertia::render('BusinessSupport/CashBook/OutgoingCheque/Index', [
            'cheques'      => $rows,
            'types'        => BsOutgoingCheque::TYPES,
            'statuses'     => BsOutgoingCheque::STATUSES,
            'bankAccounts' => $this->bankAccounts(),
            'filters'      => $request->only(['status', 'search', 'type', 'bank_account_id']),
            'summary'  => [
                'dimiliki'  => $bucket('available'), // BG/Cek dimiliki — tidak termasuk beredar & void
                'beredar'   => $bucket('issued'),    // sudah beredar (diserahkan, belum cair)
                'dicairkan' => $bucket('cleared'),   // sudah dicairkan
                'void'      => $bucket('void'),       // #68 dibatalkan (VOID)
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        return Inertia::render('BusinessSupport/CashBook/OutgoingCheque/Form', [
            'bankAccounts' => $this->bankAccounts(),
            'types'        => BsOutgoingCheque::TYPES,
            // Supplier + invoice hutang terbuka → referensi pelunasan saat BG/Cek diserahkan.
            'suppliers'    => $this->activeSuppliers(),
            'openInvoices' => $this->openInvoicesPayload(),
            // #70 pool nomor warkat KOSONG (dimiliki, belum diterbitkan) per rekening bank → untuk datalist.
            'pool'         => BsOutgoingCheque::where('status', 'available')
                ->whereNull('amount')
                ->orderBy('cheque_number')
                ->get(['cheque_number', 'bank_account_id'])
                ->groupBy('bank_account_id')
                ->map(fn ($g) => $g->pluck('cheque_number')->values()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $v = $request->validate([
            'cheque_number'        => ['required', 'string', 'max:80'],
            'cheque_type'          => ['required', 'in:cek,bg'],
            // Tujuan: 'hutang' (penerima Supplier/Vendor) | 'non_hutang' (penerima pihak ketiga, tanpa hutang).
            'cheque_purpose'       => ['nullable', 'in:hutang,non_hutang'],
            'bank_account_id'      => ['required', 'integer', 'exists:bs_accounts,id'],
            'supplier_id'          => ['nullable', 'integer', Rule::exists('bs_suppliers', 'id')->where('is_active', true)],
            'payee'                => ['nullable', 'string', 'max:150'],
            'amount'               => ['required', 'numeric', 'min:0.01'],
            'cheque_date'          => ['required', 'date'],
            'signer_name'          => ['required', 'string', 'max:150'],   // #67 dua pejabat wajib
            'signer_name_2'        => ['required', 'string', 'max:150'],
            'recipient_bank'       => ['nullable', 'required_if:cheque_type,bg', 'string', 'max:150'], // #72
            'recipient_account_no' => ['nullable', 'required_if:cheque_type,bg', 'string', 'max:50'],
            'description'          => ['nullable', 'string', 'max:255'],
            // Referensi pelunasan hutang AP (opsional) — beberapa invoice per BG/Cek.
            'lines'                  => ['nullable', 'array'],
            'lines.*.ap_invoice_id'  => ['required_with:lines', 'integer', 'exists:bs_ap_invoices,id'],
            'lines.*.amount'         => ['required_with:lines', 'numeric', 'min:0.01'],
        ], [
            'signer_name.required'             => 'Pejabat penandatangan 1 wajib diisi.',
            'signer_name_2.required'           => 'Pejabat penandatangan 2 wajib diisi.',
            'recipient_bank.required_if'       => 'Bank penerima wajib diisi untuk Bilyet Giro.',
            'recipient_account_no.required_if' => 'No. rekening penerima wajib diisi untuk Bilyet Giro.',
        ]);

        $purpose = $v['cheque_purpose'] ?? 'hutang';
        unset($v['cheque_purpose']);

        $rawLines = $v['lines'] ?? [];
        unset($v['lines']);
        $lines = [];

        if ($purpose === 'non_hutang') {
            // Penerima pihak ketiga (bebas) — tanpa relasi hutang, referensi invoice diabaikan.
            if (empty($v['payee'])) {
                return back()->withInput()->with('error', 'Nama penerima (pihak ketiga) wajib diisi.');
            }
            $v['supplier_id'] = null;
            $v['payee_type']  = 'pihak_ketiga';
            $rawLines = [];
        } else {
            // Atas pelunasan hutang → penerima = Supplier/Vendor; nama penerima diisi dari master supplier.
            if (empty($v['supplier_id'])) {
                return back()->withInput()->with('error', 'Pilih Penerima (Supplier/Vendor) untuk BG/Cek atas pelunasan hutang.');
            }
            $v['payee']      = (string) BsSupplier::whereKey($v['supplier_id'])->value('name');
            $v['payee_type'] = 'supplier';
        }

        // Bila ada baris referensi invoice → validasi (milik supplier & ≤ sisa hutang) + kunci nominal = total.
        if (array_filter($rawLines, fn ($l) => ! empty($l['ap_invoice_id']) && (float) ($l['amount'] ?? 0) > 0)) {
            try {
                [$lines, $total] = $this->buildSettlementLines($rawLines, isset($v['supplier_id']) ? (int) $v['supplier_id'] : null);
            } catch (BusinessRuleException $e) {
                return back()->withInput()->with('error', $e->getMessage());
            }
            $v['amount'] = $total; // nominal warkat = total invoice yang dilunasi
        }

        // Cek biasa tidak punya bank/rekening penerima → null-kan agar tidak ada nilai sisa.
        if ($v['cheque_type'] === 'cek') {
            $v['recipient_bank'] = null;
            $v['recipient_account_no'] = null;
        }

        $cheque = DB::transaction(function () use ($v, $lines, $request) {
            // #70 fill-or-insert: bila nomor ini sudah ada sebagai stock KOSONG (available, amount null)
            // pada bank yang sama → isi baris itu (konsumsi pool). Selain itu buat baris baru.
            $existing = BsOutgoingCheque::where('status', 'available')
                ->where('bank_account_id', $v['bank_account_id'])
                ->where('cheque_number', $v['cheque_number'])
                ->whereNull('amount')
                ->first();

            if ($existing) {
                $existing->update($v); // status tetap 'available' (terisi, belum diserahkan)
                $cheque = $existing;
            } else {
                $v['status'] = 'available';
                $v['created_by'] = $request->user()->id;
                $cheque = BsOutgoingCheque::create($v);
            }

            // Tulis ulang baris referensi invoice (belum dilunasi sampai diserahkan).
            $cheque->lines()->whereNull('payment_id')->delete();
            foreach ($lines as $ln) {
                $cheque->lines()->create([
                    'tenant_id'     => $cheque->tenant_id,
                    'ap_invoice_id' => (int) $ln['ap_invoice_id'],
                    'amount'        => round((float) $ln['amount'], 2),
                ]);
            }

            return $cheque;
        });

        return redirect()->route('bs.cheque-out.show', $cheque)->with('success', "BG/Cek {$cheque->cheque_number} tercatat.");
    }

    public function show(Request $request, BsOutgoingCheque $cheque): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        return Inertia::render('BusinessSupport/CashBook/OutgoingCheque/Show', [
            'cheque'   => $cheque->load([
                'bankAccount:id,code,name',
                'supplier:id,code,name',
                // Baris invoice hutang yang direferensikan untuk dilunasi (+ status pelunasan).
                'lines:id,outgoing_cheque_id,ap_invoice_id,amount,payment_id',
                'lines.invoice:id,invoice_number,description,supplier_id,total,paid_amount,net_payable,status',
                // Nomor jurnal GL (serah / cair / void) agar mudah ditelusuri.
                'handoverJournal:id,journal_number', 'clearJournal:id,journal_number', 'voidJournal:id,journal_number',
            ]),
            'types'    => BsOutgoingCheque::TYPES,
            'statuses' => BsOutgoingCheque::STATUSES,
            // Pilih supplier + invoice hutang saat penyerahan — hanya bila warkat masih 'available'
            // & belum mereferensikan invoice (yang dibuat lewat form sudah punya baris sendiri).
            'suppliers'    => $cheque->status === 'available' && $cheque->lines->isEmpty() ? $this->activeSuppliers() : [],
            'openInvoices' => $cheque->status === 'available' && $cheque->lines->isEmpty() ? $this->openInvoicesPayload() : [],
            // Struktur jurnal penyerahan untuk warkat PIHAK KETIGA: daftar akun debit (postable) + default Beban Pokok Penjualan.
            'debitAccounts'      => $cheque->status === 'available' && $cheque->payee_type === 'pihak_ketiga'
                ? BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name'])
                : [],
            'defaultDebitAccount' => $cheque->payee_type === 'pihak_ketiga'
                ? BsAccount::where('code', config('business_support.gl_accounts.outgoing_cheque_expense'))->value('id')
                : null,
            'liabilityAccount'    => BsAccount::where('code', config('business_support.gl_accounts.outgoing_cheque_liability'))
                ->first(['id', 'code', 'name']),
        ]);
    }

    // ── #68 IMPORT data BG/Cek (stock dimiliki) + template ──────────────────────

    public function importForm(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        return Inertia::render('BusinessSupport/CashBook/OutgoingCheque/Import', [
            'bankAccounts' => $this->bankAccounts(),
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);
        $request->validate(['file' => ['required', 'file', 'max:2048']]);

        // #85 hanya Excel .xlsx. Cek via ekstensi klien (deteksi MIME xlsx kadang keliru jadi application/zip).
        $upload = $request->file('file');
        if (strtolower((string) $upload->getClientOriginalExtension()) !== 'xlsx') {
            return back()->with('error', 'File harus berformat Excel (.xlsx). Unduh template untuk format yang benar.');
        }

        try {
            $lines = SimpleXlsx::rows($upload->getRealPath());
        } catch (\Throwable $e) {
            return back()->with('error', 'File Excel tidak dapat dibaca: '.$e->getMessage());
        }
        if (empty($lines)) {
            return back()->with('error', 'File Excel kosong.');
        }

        $header = array_map(fn ($h) => strtoupper(trim((string) $h)), array_shift($lines));
        $idx    = array_flip($header);
        foreach (['NO_BG_CEK', 'BANK', 'JENIS'] as $col) {
            if (! isset($idx[$col])) {
                return back()->with('error', "Kolom wajib '{$col}' tidak ditemukan. Unduh template untuk format yang benar.");
            }
        }

        $banks  = $this->bankAccounts();
        $byCode = $banks->keyBy('code');
        $byName = $banks->keyBy(fn ($b) => strtolower((string) $b->name));

        // Dedupe key = bank_account_id|cheque_number (nomor warkat unik per bank).
        $existing = BsOutgoingCheque::query()->get(['cheque_number', 'bank_account_id'])
            ->mapWithKeys(fn ($r) => [$r->bank_account_id.'|'.$r->cheque_number => true])->all();

        $inserted = 0;
        $skipped  = 0;
        $errors   = [];

        DB::beginTransaction();
        try {
            foreach ($lines as $i => $r) {
                $rowNo = $i + 2; // baris 1 = header
                $num   = trim((string) ($r[$idx['NO_BG_CEK']] ?? ''));
                $bank  = trim((string) ($r[$idx['BANK']] ?? ''));
                $type  = strtolower(trim((string) ($r[$idx['JENIS']] ?? 'cek')));
                if ($num === '' && $bank === '') {
                    continue; // baris kosong
                }
                // Normalisasi jenis.
                if (in_array($type, ['bg', 'bilyet giro', 'bilyet'], true)) {
                    $type = 'bg';
                } elseif (in_array($type, ['cek', 'cheque'], true)) {
                    $type = 'cek';
                }
                $acc = $byCode->get($bank) ?? $byName->get(strtolower($bank));

                if ($num === '') {
                    $errors[] = "Baris {$rowNo}: No BG/Cek kosong.";

                    continue;
                }
                if (! $acc) {
                    $errors[] = "Baris {$rowNo}: Bank '{$bank}' tidak dikenal (gunakan kode rekening).";

                    continue;
                }
                if (! in_array($type, ['cek', 'bg'], true)) {
                    $errors[] = "Baris {$rowNo}: Jenis '{$type}' tidak valid (isi cek / bg).";

                    continue;
                }
                $key = $acc->id.'|'.$num;
                if (isset($existing[$key])) {
                    $skipped++;

                    continue;
                }
                BsOutgoingCheque::create([
                    'cheque_number'   => $num,
                    'cheque_type'     => $type,
                    'bank_account_id' => $acc->id,
                    'status'          => 'available',
                    'amount'          => null,
                    'cheque_date'     => null,
                    'created_by'      => $request->user()->id,
                ]);
                $existing[$key] = true;
                $inserted++;
            }

            if (! empty($errors)) {
                DB::rollBack();

                return back()->with('error', 'Import dibatalkan — perbaiki dahulu: '.implode(' ', array_slice($errors, 0, 10)));
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return back()->with('error', 'Gagal import: '.$e->getMessage());
        }

        $msg = "Import selesai — {$inserted} BG/Cek ditambahkan".($skipped > 0 ? ", {$skipped} dilewati (duplikat)." : '.');

        return redirect()->route('bs.cheque-out.index')->with('success', $msg);
    }

    public function importTemplate(Request $request): HttpResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $bin = SimpleXlsx::toString([
            ['NO_BG_CEK', 'BANK', 'JENIS'],
            ['BC-0000001', '1010102001', 'cek'],
            ['BG-0000001', '1010102001', 'bg'],
        ]);

        return response($bin, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="template_import_bg_cek.xlsx"',
            'Content-Length'      => (string) strlen($bin),
            'Cache-Control'       => 'no-store, no-cache',
        ]);
    }

    /** Serahkan ke supplier/vendor → status beredar. #153 jurnal otomatis: Dr Hutang / Cr BG-Cek Beredar. */
    public function handover(Request $request, BsOutgoingCheque $cheque, OutgoingChequeService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);
        $v = $request->validate([
            'handover_date'          => ['required', 'date'],
            // Pelunasan hutang dipilih saat penyerahan (opsional) — supplier + beberapa invoice.
            'supplier_id'            => ['nullable', 'integer', Rule::exists('bs_suppliers', 'id')->where('is_active', true)],
            'lines'                  => ['nullable', 'array'],
            'lines.*.ap_invoice_id'  => ['required_with:lines', 'integer', 'exists:bs_ap_invoices,id'],
            'lines.*.amount'         => ['required_with:lines', 'numeric', 'min:0.01'],
            // Warkat "tidak berhubungan hutang" (pihak ketiga): akun debit struktur jurnal (default Beban Pokok Penjualan).
            'debit_account_id'       => ['nullable', 'integer', Rule::exists('bs_accounts', 'id')->where('is_postable', true)],
        ]);

        if ($cheque->status !== 'available') {
            return back()->with('error', 'BG/Cek sudah diserahkan / dicairkan / void.');
        }

        // Bila user memilih invoice di sini & warkat belum punya baris → lunasi saat penyerahan.
        $settleHere = (bool) array_filter($v['lines'] ?? [], fn ($l) => ! empty($l['ap_invoice_id']) && (float) ($l['amount'] ?? 0) > 0)
            && $cheque->lines->isEmpty();

        if (! $settleHere && $cheque->amount === null) {
            return back()->with('error', 'BG/Cek belum diterbitkan (nominal kosong). Isi nominal lewat Tambah BG/Cek dulu.');
        }

        // Pihak ketiga (tanpa pelunasan hutang): debit = akun yang dipilih, default Beban Pokok Penjualan.
        $debitAccountId = null;
        if (! $settleHere && $cheque->payee_type === 'pihak_ketiga') {
            $debitAccountId = $v['debit_account_id']
                ?? BsAccount::where('code', config('business_support.gl_accounts.outgoing_cheque_expense'))->value('id');
            $debitAccountId = $debitAccountId ? (int) $debitAccountId : null;
        }

        try {
            // SATU transaksi: simpan baris + set supplier + kunci nominal = total + posting jurnal pelunasan.
            // Bila jurnal gagal (mis. periode CLOSED / saldo berubah) seluruhnya di-rollback — nominal asli
            // & status warkat tidak berubah (tidak ada commit parsial).
            DB::transaction(function () use ($cheque, $settleHere, $v, $svc, $request, $debitAccountId) {
                if ($settleHere) {
                    [$lines, $total] = $this->buildSettlementLines($v['lines'], isset($v['supplier_id']) ? (int) $v['supplier_id'] : null);
                    $cheque->update(['supplier_id' => $v['supplier_id'], 'amount' => $total]);
                    foreach ($lines as $ln) {
                        $cheque->lines()->create([
                            'tenant_id'     => $cheque->tenant_id,
                            'ap_invoice_id' => $ln['ap_invoice_id'],
                            'amount'        => $ln['amount'],
                        ]);
                    }
                    $cheque->load('lines');
                }
                $svc->handover($cheque, $v['handover_date'], $request->user()->id, $debitAccountId);
            });
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'BG/Cek ditandai sudah diserahkan ke supplier (beredar) — jurnal otomatis terbentuk.');
    }

    /** Dicairkan oleh supplier/vendor → status cair. #153 jurnal otomatis: Dr BG-Cek Beredar / Cr Bank. */
    public function clear(Request $request, BsOutgoingCheque $cheque, OutgoingChequeService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);
        $v = $request->validate(['cleared_date' => ['required', 'date']]);

        if ($cheque->status === 'cleared') {
            return back()->with('error', 'BG/Cek sudah dicairkan.');
        }
        if ($cheque->status === 'void') {
            return back()->with('error', 'BG/Cek sudah void, tidak dapat dicairkan.');
        }

        try {
            $svc->clear($cheque, $v['cleared_date'], $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'BG/Cek ditandai sudah dicairkan — jurnal otomatis terbentuk.');
    }

    /** #74 VOID — batalkan warkat. #153 bila sempat beredar, pembalik jurnal penyerahan diposting otomatis. */
    public function void(Request $request, BsOutgoingCheque $cheque, OutgoingChequeService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);
        $v = $request->validate(['void_reason' => ['nullable', 'string', 'max:255']]);

        if ($cheque->status === 'cleared') {
            return back()->with('error', 'BG/Cek sudah dicairkan, tidak dapat di-void.');
        }
        if ($cheque->status === 'void') {
            return back()->with('error', 'BG/Cek sudah void.');
        }

        try {
            $svc->void($cheque, $v['void_reason'] ?? null, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'BG/Cek ditandai VOID (dibatalkan) — jurnal otomatis menyesuaikan.');
    }

    /** #73 Alat cetak BG/Cek — mode: with (default) / without (tanpa tanggal) / dateonly (hanya tanggal). */
    public function print(Request $request, BsOutgoingCheque $cheque): HttpResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);
        $cheque->load('bankAccount:id,code,name');

        $mode = in_array($request->query('mode'), ['with', 'without', 'dateonly'], true)
            ? (string) $request->query('mode') : 'with';

        return response($this->renderCheque($cheque, $mode))->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function bankAccounts()
    {
        // Rekening bank perusahaan (di bawah BANK 1010102…).
        return BsAccount::where('is_active', true)->where('is_postable', true)
            ->where('code', 'like', '1010102%')->orderBy('code')->get(['id', 'code', 'name']);
    }

    /** Supplier aktif untuk picker referensi pelunasan. */
    private function activeSuppliers()
    {
        return BsSupplier::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);
    }

    /** Invoice hutang terbuka (sisa hutang > 0) untuk picker pelunasan — id, nomor, supplier, sisa. */
    private function openInvoicesPayload()
    {
        return BsApInvoice::where('status', 'open')->with('supplier:id,code,name')->get()
            ->map(fn ($i) => [
                'id' => $i->id, 'invoice_number' => $i->invoice_number, 'supplier_id' => $i->supplier_id,
                'description' => $i->description, 'outstanding' => (float) $i->outstanding,
                'due_date' => $i->due_date?->format('Y-m-d'),
            ])->filter(fn ($i) => $i['outstanding'] > 0.009)->values();
    }

    /**
     * Validasi baris referensi invoice: supplier wajib, tiap invoice milik supplier itu & ≤ sisa hutang.
     *
     * @param  array<int,array{ap_invoice_id?:mixed, amount?:mixed}>  $rawLines
     * @return array{0: array<int,array{ap_invoice_id:int, amount:float}>, 1: float}  [lines, total]
     *
     * @throws BusinessRuleException
     */
    private function buildSettlementLines(array $rawLines, ?int $supplierId): array
    {
        if (empty($supplierId)) {
            throw new BusinessRuleException('Pilih supplier terlebih dahulu untuk mereferensikan invoice hutang.');
        }
        // Gabungkan baris dengan invoice yang sama → cegah pelunasan GANDA atas satu invoice
        // (validasi sisa hutang divalidasi atas total kumulatif per invoice, bukan per-baris).
        $byInvoice = [];
        foreach ($rawLines as $l) {
            if (empty($l['ap_invoice_id']) || (float) ($l['amount'] ?? 0) <= 0) {
                continue;
            }
            $id = (int) $l['ap_invoice_id'];
            $byInvoice[$id] = round(($byInvoice[$id] ?? 0) + (float) $l['amount'], 2);
        }
        $invoices = BsApInvoice::whereIn('id', array_keys($byInvoice))->get()->keyBy('id');

        $lines = [];
        $total = 0.0;
        foreach ($byInvoice as $id => $amt) {
            $inv = $invoices->get($id);
            if (! $inv || (int) $inv->supplier_id !== (int) $supplierId) {
                throw new BusinessRuleException('Salah satu invoice bukan milik supplier yang dipilih.');
            }
            if ($amt > (float) $inv->outstanding + 0.01) {
                throw new BusinessRuleException("Nominal pelunasan {$inv->invoice_number} melebihi sisa hutang.");
            }
            $lines[] = ['ap_invoice_id' => $id, 'amount' => $amt];
            $total += $amt;
        }

        return [$lines, round($total, 2)];
    }

    /**
     * Template cetak BG/Cek. Ukuran default ~190mm × 90mm (umum warkat bank ID) —
     * posisi field memakai mm absolut agar staf finance bisa kalibrasi ke warkat aslinya.
     *
     * #71 baris info "No · jenis · bank" dihapus; penerima kosong dicetak BLANK (bukan "—").
     * #73 $mode mengatur tampil/tidaknya tanggal vs isi warkat.
     */
    private function renderCheque(BsOutgoingCheque $cheque, string $mode = 'with'): string
    {
        $e    = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp   = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
        $kota = (string) config('business_support.company.city', 'Sidoarjo');
        $tgl  = optional($cheque->cheque_date)->translatedFormat('d F Y');

        $hasAmount = $cheque->amount !== null && (float) $cheque->amount > 0;
        $amountStr = $hasAmount ? $rp($cheque->amount) : '';
        $terbilang = $hasAmount ? ucwords($this->terbilang((int) round((float) $cheque->amount))).' Rupiah' : '';

        $showBody = $mode !== 'dateonly'; // penerima / terbilang / nominal / penandatangan
        $showDate = $mode !== 'without';  // tempat & tanggal

        $blocks = '';
        if ($showDate) {
            $blocks .= '<div class="f" style="top:9mm;right:10mm">'.$e($kota).', '.$e($tgl).'</div>';
        }
        if ($showBody) {
            // #71 penerima kosong → BLANK
            $blocks .= '<div class="f" style="top:24mm;left:34mm">'.$e($cheque->payee ?? '').'</div>';
            $blocks .= '<div class="f" style="top:36mm;left:34mm;max-width:120mm;white-space:normal">'.$e($terbilang).'</div>';
            $blocks .= '<div class="f" style="top:50mm;right:12mm;font-weight:bold">'.$e($amountStr).'</div>';

            // #67 dua penandatangan (kanan bawah). Bila penandatangan 2 kosong → tampilkan satu (data lama).
            $s1 = (string) ($cheque->signer_name ?? '');
            $s2 = (string) ($cheque->signer_name_2 ?? '');
            if ($s2 !== '') {
                $blocks .= '<div class="f" style="bottom:10mm;left:26mm;width:45mm;text-align:center">'.$e($s1).'</div>';
                $blocks .= '<div class="f" style="bottom:10mm;right:14mm;width:45mm;text-align:center">'.$e($s2).'</div>';
            } else {
                $blocks .= '<div class="f" style="bottom:10mm;right:16mm;text-align:center">'.$e($s1).'</div>';
            }
        }

        return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>'.$e($cheque->cheque_number).'</title><style>
            @page{size:190mm 90mm;margin:0}
            *{box-sizing:border-box}
            html,body{margin:0;padding:0}
            .sheet{position:relative;width:190mm;height:90mm;font-family:"Courier New",monospace;font-size:12pt;color:#111}
            .f{position:absolute;white-space:nowrap}
            .small{font-size:9pt;color:#555}
            .guide{position:absolute;inset:0;border:1px dashed #bbb}
            @media print{.guide,.toolbar{display:none}}
            .toolbar{font-family:Arial,sans-serif;padding:8px 12px;background:#f3f4f6;border-bottom:1px solid #ddd}
            .toolbar button{padding:6px 14px;margin-right:8px}
        </style></head><body>
        <div class="toolbar">
            <button onclick="window.print()">🖨️ Cetak BG/Cek</button>
            <label><input type="checkbox" id="g" checked onchange="document.getElementById(\'gd\').style.display=this.checked?\'block\':\'none\'"> tampilkan garis bantu (tidak ikut tercetak)</label>
            <span style="color:#6b7280;margin-left:10px">Atur margin printer ke 0 / "Actual size". Kalibrasi posisi di CSS bila perlu.</span>
        </div>
        <div class="sheet">
            <div id="gd" class="guide"></div>
            '.$blocks.'
        </div>
        <script>window.onload=function(){setTimeout(function(){window.print()},400)}</script>
        </body></html>';
    }

    /** Angka → terbilang (Bahasa Indonesia). */
    private function terbilang(int $n): string
    {
        if ($n < 0) {
            return 'minus '.$this->terbilang(-$n);
        }
        $satuan = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
        if ($n < 12) {
            return $satuan[$n];
        }
        if ($n < 20) {
            return $this->terbilang($n - 10).' belas';
        }
        if ($n < 100) {
            return trim($this->terbilang(intdiv($n, 10)).' puluh '.$this->terbilang($n % 10));
        }
        if ($n < 200) {
            return trim('seratus '.$this->terbilang($n - 100));
        }
        if ($n < 1000) {
            return trim($this->terbilang(intdiv($n, 100)).' ratus '.$this->terbilang($n % 100));
        }
        if ($n < 2000) {
            return trim('seribu '.$this->terbilang($n - 1000));
        }
        if ($n < 1000000) {
            return trim($this->terbilang(intdiv($n, 1000)).' ribu '.$this->terbilang($n % 1000));
        }
        if ($n < 1000000000) {
            return trim($this->terbilang(intdiv($n, 1000000)).' juta '.$this->terbilang($n % 1000000));
        }
        if ($n < 1000000000000) {
            return trim($this->terbilang(intdiv($n, 1000000000)).' miliar '.$this->terbilang($n % 1000000000));
        }

        return trim($this->terbilang(intdiv($n, 1000000000000)).' triliun '.$this->terbilang($n % 1000000000000));
    }
}
