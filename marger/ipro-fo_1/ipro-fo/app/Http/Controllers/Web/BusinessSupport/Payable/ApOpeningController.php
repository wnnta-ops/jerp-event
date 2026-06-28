<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Payable;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Http\Controllers\Controller;
use App\Support\Xlsx\SimpleXlsx;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AP → Saldo Awal Hutang: input saldo hutang awal per supplier (sub-ledger, tanpa jurnal GL;
 * sisi jurnal GL ditangani Saldo Awal GL agar tidak dobel). trx_type = 'saldo_awal'.
 */
class ApOpeningController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        $rows = BsApInvoice::query()
            ->where('trx_type', 'saldo_awal')
            ->with('supplier:id,code,name')
            ->latest('invoice_date')->latest('id')
            ->paginate(50)->withQueryString();

        return Inertia::render('BusinessSupport/Payable/Opening', [
            'openings'  => $rows,
            'suppliers' => BsSupplier::orderBy('name')->get(['id', 'code', 'name']),
            'accounts'  => BsAccount::where('is_postable', true)->where('type', 'liability')->orderBy('code')->get(['id', 'code', 'name']),
            'summary'   => [
                'count' => BsApInvoice::where('trx_type', 'saldo_awal')->count(),
                'total' => round((float) BsApInvoice::where('trx_type', 'saldo_awal')->sum('total'), 2),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        $v = $request->validate([
            'invoice_number'     => ['required', 'string', 'max:64'],
            'invoice_date'       => ['required', 'date'],
            'due_date'           => ['nullable', 'date'],
            'supplier_id'        => ['required', 'integer', 'exists:bs_suppliers,id'],
            'payable_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            'total'              => ['required', 'numeric', 'min:0.01'],
            'description'        => ['nullable', 'string', 'max:255'],
        ]);

        BsApInvoice::create([
            'invoice_number'     => $v['invoice_number'],
            'invoice_date'       => $v['invoice_date'],
            'due_date'           => $v['due_date'] ?? $v['invoice_date'],
            'supplier_id'        => $v['supplier_id'],
            'payable_account_id' => $v['payable_account_id'],
            'expense_account_id' => $v['payable_account_id'], // tak dipakai (tanpa jurnal); isi non-null
            'description'        => $v['description'] ?? 'Saldo Awal Hutang',
            'trx_type'           => 'saldo_awal',
            'status'             => 'open',
            'total'              => $v['total'],
            'net_payable'        => $v['total'],
            'dpp'                => $v['total'],
            'paid_amount'        => 0,
            'kurs'               => 1,
            'created_by'         => $request->user()->id,
        ]);

        return back()->with('success', 'Saldo awal hutang ditambahkan.');
    }

    public function bulkStore(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        $v = $request->validate([
            'rows'                      => ['required', 'array', 'min:1'],
            'rows.*.supplier_id'        => ['required', 'integer', 'exists:bs_suppliers,id'],
            'rows.*.invoice_number'     => ['required', 'string', 'max:64'],
            'rows.*.invoice_date'       => ['required', 'date'],
            'rows.*.due_date'           => ['nullable', 'date'],
            'rows.*.payable_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            'rows.*.total'              => ['required', 'numeric', 'min:0.01'],
            'rows.*.description'        => ['nullable', 'string', 'max:255'],
        ]);

        $userId = $request->user()->id;
        $created = 0;

        DB::transaction(function () use ($v, $userId, &$created): void {
            foreach ($v['rows'] as $row) {
                BsApInvoice::create([
                    'invoice_number'     => $row['invoice_number'],
                    'invoice_date'       => $row['invoice_date'],
                    'due_date'           => $row['due_date'] ?? $row['invoice_date'],
                    'supplier_id'        => $row['supplier_id'],
                    'payable_account_id' => $row['payable_account_id'],
                    'expense_account_id' => $row['payable_account_id'], // tak dipakai (tanpa jurnal); isi non-null
                    'description'        => $row['description'] ?? 'Saldo Awal Hutang',
                    'trx_type'           => 'saldo_awal',
                    'status'             => 'open',
                    'total'              => $row['total'],
                    'net_payable'        => $row['total'],
                    'dpp'                => $row['total'],
                    'paid_amount'        => 0,
                    'kurs'               => 1,
                    'created_by'         => $userId,
                ]);
                $created++;
            }
        });

        return back()->with('success', "{$created} saldo awal hutang ditambahkan.");
    }

    /** Kolom template/import (urutan tetap; pencocokan saat import toleran terhadap variasi judul). */
    public const IMPORT_HEADER = ['Kode Supplier', 'No Invoice', 'Tgl Invoice', 'Jatuh Tempo', 'Kode Akun Hutang', 'Total', 'Keterangan'];

    /**
     * Unduh template Excel (.xlsx) untuk import massal saldo awal hutang.
     * Berisi baris header + 1 baris contoh (memakai kode supplier/akun nyata bila ada).
     */
    public function template(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        $supplier = BsSupplier::orderBy('code')->first();
        $account  = BsAccount::where('is_postable', true)->where('type', 'liability')->orderBy('code')->first();

        $example = [
            $supplier?->code ?? 'SUP-001',
            'SALDO-AWAL/001',
            now()->startOfYear()->toDateString(),
            now()->startOfYear()->addDays(30)->toDateString(),
            $account?->code ?? '2101',
            '1000000',
            'CONTOH — hapus baris ini sebelum mengisi data Anda',
        ];

        $bin = SimpleXlsx::toString([self::IMPORT_HEADER, $example]);

        return response($bin, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Template-Saldo-Awal-Hutang.xlsx"',
        ]);
    }

    /**
     * Import massal saldo awal hutang dari file .xlsx/.xls/.csv.
     * Header dicocokkan secara toleran; supplier & akun diresolusi dari KODE (fallback nama supplier,
     * fallback akun liability default). Baris duplikat (supplier+no invoice yang sudah ada) dilewati.
     */
    public function import(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ap'), 403);
        $request->validate(['file' => ['required', 'file', 'max:8192']]);

        $upload = $request->file('file');
        $ext    = strtolower((string) $upload->getClientOriginalExtension());

        try {
            if (in_array($ext, ['xlsx', 'xls'], true)) {
                $rows = SimpleXlsx::rows($upload->getRealPath());
            } elseif (in_array($ext, ['csv', 'txt'], true)) {
                $rows = array_map('str_getcsv', file($upload->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
            } else {
                return back()->with('error', 'Format file harus .xlsx, .xls, atau .csv. Unduh template untuk format yang benar.');
            }
        } catch (\Throwable $e) {
            return back()->with('error', 'File tidak dapat dibaca: '.$e->getMessage());
        }

        // Buang baris yang seluruh selnya kosong.
        $rows = array_values(array_filter((array) $rows, fn ($r) => is_array($r) && trim(implode('', array_map('strval', $r))) !== ''));
        if (count($rows) < 2) {
            return back()->with('error', 'File kosong atau hanya berisi baris header.');
        }

        $header = array_map(fn ($h) => strtoupper(trim((string) $h)), array_shift($rows));
        $find   = function (callable $pred) use ($header): ?int {
            foreach ($header as $i => $h) {
                if ($pred($h)) {
                    return $i;
                }
            }

            return null;
        };

        $iSup   = $find(fn ($h) => str_contains($h, 'SUPPLIER') || str_contains($h, 'VENDOR'));
        $iDate  = $find(fn ($h) => (str_contains($h, 'TGL') || str_contains($h, 'TANGGAL')) && (str_contains($h, 'INVOICE') || str_contains($h, 'DOK')));
        $iDue   = $find(fn ($h) => str_contains($h, 'JATUH') || str_contains($h, 'TEMPO') || $h === 'JT');
        $iInv   = $find(fn ($h) => (str_contains($h, 'INVOICE') || str_contains($h, 'DOKUMEN') || str_contains($h, 'NOMOR'))
            && ! str_contains($h, 'TGL') && ! str_contains($h, 'TANGGAL') && ! str_contains($h, 'JATUH') && ! str_contains($h, 'TEMPO') && ! str_contains($h, 'AKUN'));
        $iAcc   = $find(fn ($h) => str_contains($h, 'AKUN') || str_contains($h, 'ACCOUNT') || str_contains($h, 'COA'));
        $iTotal = $find(fn ($h) => str_contains($h, 'TOTAL') || str_contains($h, 'NILAI') || str_contains($h, 'JUMLAH') || str_contains($h, 'NOMINAL') || str_contains($h, 'SALDO'));
        $iDesc  = $find(fn ($h) => str_contains($h, 'KETERANGAN') || str_contains($h, 'DESC') || str_contains($h, 'CATATAN'));

        $missing = [];
        if ($iSup === null) { $missing[] = 'Kode Supplier'; }
        if ($iInv === null) { $missing[] = 'No Invoice'; }
        if ($iDate === null) { $missing[] = 'Tgl Invoice'; }
        if ($iTotal === null) { $missing[] = 'Total'; }
        if ($missing) {
            return back()->with('error', 'Kolom wajib tidak ditemukan: '.implode(', ', $missing).'. Unduh template untuk format yang benar.');
        }

        $suppliers      = BsSupplier::get(['id', 'code', 'name']);
        $suppliersByCode = $suppliers->keyBy(fn ($s) => strtoupper(trim((string) $s->code)));
        $suppliersByName = $suppliers->keyBy(fn ($s) => strtolower(trim((string) $s->name)));
        $accountsByCode  = BsAccount::where('is_postable', true)->where('type', 'liability')->get(['id', 'code', 'name'])
            ->keyBy(fn ($a) => strtoupper(trim((string) $a->code)));
        $defaultAccount  = $accountsByCode->first();

        $existing = BsApInvoice::where('trx_type', 'saldo_awal')->get(['supplier_id', 'invoice_number'])
            ->mapWithKeys(fn ($r) => [$r->supplier_id.'|'.strtoupper(trim((string) $r->invoice_number)) => true])->all();

        $created  = 0;
        $skipped  = 0;
        $errors   = [];
        $payloads = [];

        foreach ($rows as $n => $r) {
            $rowNo   = $n + 2; // baris 1 = header
            $get     = fn (?int $i) => $i === null ? '' : trim((string) ($r[$i] ?? ''));
            $supCode = $get($iSup);
            $invNo   = $get($iInv);
            $dateRaw = $get($iDate);
            $dueRaw  = $get($iDue);
            $accCode = $get($iAcc);
            $totalRaw = $get($iTotal);
            $desc    = $get($iDesc);

            if ($supCode === '' && $invNo === '' && $totalRaw === '') {
                continue; // baris kosong
            }

            $supplier = $suppliersByCode->get(strtoupper($supCode)) ?? $suppliersByName->get(strtolower($supCode));
            if (! $supplier) {
                $errors[] = "Baris {$rowNo}: supplier '{$supCode}' tidak ditemukan.";
                $skipped++;

                continue;
            }
            if ($invNo === '') {
                $errors[] = "Baris {$rowNo}: No Invoice kosong.";
                $skipped++;

                continue;
            }
            $date = $this->parseImportDate($dateRaw);
            if ($date === null) {
                $errors[] = "Baris {$rowNo}: Tgl Invoice '{$dateRaw}' tidak valid (pakai format YYYY-MM-DD).";
                $skipped++;

                continue;
            }
            $due   = $dueRaw !== '' ? $this->parseImportDate($dueRaw) : null;
            $total = $this->parseImportAmount($totalRaw);
            if ($total === null || $total < 0.01) {
                $errors[] = "Baris {$rowNo}: Total '{$totalRaw}' tidak valid.";
                $skipped++;

                continue;
            }
            $account = $accCode !== '' ? $accountsByCode->get(strtoupper($accCode)) : null;
            $account ??= $defaultAccount;
            if (! $account) {
                $errors[] = "Baris {$rowNo}: Akun hutang '{$accCode}' tidak ditemukan & tak ada akun liability default.";
                $skipped++;

                continue;
            }

            $key = $supplier->id.'|'.strtoupper($invNo);
            if (isset($existing[$key])) {
                $errors[] = "Baris {$rowNo}: saldo awal '{$invNo}' untuk supplier {$supplier->code} sudah ada (dilewati).";
                $skipped++;

                continue;
            }
            $existing[$key] = true; // cegah duplikat dalam file yang sama

            $payloads[] = [
                'invoice_number'     => $invNo,
                'invoice_date'       => $date,
                'due_date'           => $due ?? $date,
                'supplier_id'        => $supplier->id,
                'payable_account_id' => $account->id,
                'description'        => $desc !== '' ? $desc : 'Saldo Awal Hutang',
                'total'              => $total,
            ];
            $created++;
        }

        if ($created === 0) {
            return back()->with('error', 'Tidak ada baris yang diimpor. '.implode(' ', array_slice($errors, 0, 8)));
        }

        $userId = $request->user()->id;
        DB::transaction(function () use ($payloads, $userId): void {
            foreach ($payloads as $p) {
                BsApInvoice::create([
                    'invoice_number'     => $p['invoice_number'],
                    'invoice_date'       => $p['invoice_date'],
                    'due_date'           => $p['due_date'],
                    'supplier_id'        => $p['supplier_id'],
                    'payable_account_id' => $p['payable_account_id'],
                    'expense_account_id' => $p['payable_account_id'], // tak dipakai (tanpa jurnal); isi non-null
                    'description'        => $p['description'],
                    'trx_type'           => 'saldo_awal',
                    'status'             => 'open',
                    'total'              => $p['total'],
                    'net_payable'        => $p['total'],
                    'dpp'                => $p['total'],
                    'paid_amount'        => 0,
                    'kurs'               => 1,
                    'created_by'         => $userId,
                ]);
            }
        });

        $msg = "{$created} saldo awal hutang diimpor".($skipped ? ", {$skipped} baris dilewati/gagal" : '').'.';
        $resp = back()->with('success', $msg);
        if ($errors) {
            $resp->with('warning', implode(' · ', array_slice($errors, 0, 8)).(count($errors) > 8 ? ' … (+'.(count($errors) - 8).' lainnya)' : ''));
        }

        return $resp;
    }

    /** Parse tanggal import: dukung YYYY-MM-DD, DD/MM/YYYY, DD-MM-YYYY, serial Excel. Null jika tak valid. */
    private function parseImportDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        // Serial Excel (angka murni, mis. 46184 ≈ 2026) — epoch 1899-12-30.
        if (preg_match('/^\d{4,6}$/', $raw)) {
            $serial = (int) $raw;
            if ($serial > 1 && $serial < 80000) {
                return Carbon::create(1899, 12, 30)->addDays($serial)->toDateString();
            }
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d', 'm/d/Y'] as $fmt) {
            try {
                $d = Carbon::createFromFormat('!'.$fmt, $raw);
                if ($d !== false) {
                    return $d->toDateString();
                }
            } catch (\Throwable) {
                // coba format berikutnya
            }
        }
        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Parse nominal import: toleran format Indonesia (1.000.000,50) & internasional (1000000.50) + simbol 'Rp'. */
    private function parseImportAmount(string $raw): ?float
    {
        $raw = preg_replace('/[^\d.,\-]/', '', trim($raw)) ?? '';
        if ($raw === '' || $raw === '-') {
            return null;
        }
        $hasComma = str_contains($raw, ',');
        $hasDot   = str_contains($raw, '.');
        if ($hasComma && $hasDot) {
            // "1.000.000,50" → titik ribuan, koma desimal
            $raw = str_replace(['.', ','], ['', '.'], $raw);
        } elseif ($hasComma) {
            $raw = str_replace(',', '.', $raw); // koma = desimal
        } elseif ($hasDot) {
            // Titik tunggal/ganda: jika lebih dari satu titik atau grup akhir 3 digit → ribuan.
            $parts = explode('.', $raw);
            if (count($parts) > 2 || (count($parts) === 2 && strlen(end($parts)) === 3)) {
                $raw = str_replace('.', '', $raw);
            }
        }

        return is_numeric($raw) ? round((float) $raw, 2) : null;
    }

    public function destroy(Request $request, BsApInvoice $invoice): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ap'), 403);
        abort_unless($invoice->trx_type === 'saldo_awal' && (float) $invoice->paid_amount < 0.01, 422, 'Hanya saldo awal yang belum dibayar yang bisa dihapus.');

        $invoice->delete();

        return back()->with('success', 'Saldo awal hutang dihapus.');
    }
}
