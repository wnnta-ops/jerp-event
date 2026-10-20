<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\CashBook;

use App\Domain\BusinessSupport\Actions\RecordCashTransaction;
use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCashTransaction;
use App\Domain\BusinessSupport\Models\BsFiscalPeriod;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Http\Controllers\Controller;
use App\Support\Xlsx\SimpleXlsx;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cash Book → Saldo Awal Rekening: set saldo awal tiap rekening kas/bank saat migrasi.
 * Memakai RecordCashTransaction (jurnal otomatis): Dr rekening / Cr akun lawan (mis. Modal/Saldo Awal).
 * Ditandai reference = 'SALDO-AWAL'.
 */
class CashOpeningController extends Controller
{
    /** Kolom template/import (urutan tetap; pencocokan saat import toleran terhadap variasi judul). */
    public const IMPORT_HEADER = ['Kode Rekening', 'Kode Akun Lawan', 'Tgl', 'Saldo Awal', 'Keterangan'];

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('cashbook.opening.view'), 403);

        $rows = BsCashTransaction::query()
            ->where('reference', 'SALDO-AWAL')
            ->with(['cashAccount:id,code,name', 'counterAccount:id,code,name'])
            ->latest('trx_date')->latest('id')
            ->paginate(25)->withQueryString();

        return Inertia::render('BusinessSupport/CashBook/Opening', [
            'openings'     => $rows,
            'cashAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)->where('code', 'like', '101010%')->orderBy('code')->get(['id', 'code', 'name']),
            'accounts'     => BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name']),
            'summary'      => [
                'count' => BsCashTransaction::where('reference', 'SALDO-AWAL')->count(),
                'total' => round((float) BsCashTransaction::where('reference', 'SALDO-AWAL')->sum('amount'), 2),
            ],
        ]);
    }

    public function store(Request $request, RecordCashTransaction $action): RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.opening.create'), 403);

        $v = $request->validate([
            'cash_account_id'    => ['required', 'integer', 'exists:bs_accounts,id'],
            'counter_account_id' => ['required', 'integer', 'different:cash_account_id', 'exists:bs_accounts,id'],
            'trx_date'           => ['required', 'date'],
            'amount'             => ['required', 'numeric', 'not_in:0'],
            'description'        => ['nullable', 'string', 'max:255'],
        ]);

        $amount = round((float) $v['amount'], 2);
        $action->execute([
            'trx_type'        => $amount >= 0 ? 'in' : 'out',     // saldo debit → Dr rekening; kredit → Cr rekening
            'trx_date'        => $v['trx_date'],
            'cash_account_id' => $v['cash_account_id'],
            'lines'           => [['account_id' => $v['counter_account_id'], 'amount' => abs($amount), 'description' => 'Saldo Awal Rekening']],
            'description'     => $v['description'] ?? 'Saldo Awal Rekening',
            'reference'       => 'SALDO-AWAL',
        ], $request->user()->id);

        return back()->with('success', 'Saldo awal rekening tercatat — jurnal otomatis terbentuk.');
    }

    /** Tambah massal saldo awal rekening (banyak baris sekaligus; tiap baris = 1 jurnal otomatis). */
    public function bulkStore(Request $request, RecordCashTransaction $action): RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.opening.create'), 403);

        $v = $request->validate([
            'rows'                      => ['required', 'array', 'min:1'],
            'rows.*.cash_account_id'    => ['required', 'integer', 'exists:bs_accounts,id'],
            'rows.*.counter_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            'rows.*.trx_date'           => ['required', 'date'],
            'rows.*.amount'             => ['required', 'numeric', 'not_in:0'],
            'rows.*.description'        => ['nullable', 'string', 'max:255'],
        ]);

        foreach ($v['rows'] as $i => $row) {
            if ((int) $row['cash_account_id'] === (int) $row['counter_account_id']) {
                return back()->with('error', 'Baris '.($i + 1).': Rekening dan Akun Lawan tidak boleh sama.');
            }
        }

        $userId  = $request->user()->id;
        $created = 0;
        DB::transaction(function () use ($v, $action, $userId, &$created): void {
            foreach ($v['rows'] as $row) {
                $amount = round((float) $row['amount'], 2);
                $action->execute([
                    'trx_type'        => $amount >= 0 ? 'in' : 'out',
                    'trx_date'        => $row['trx_date'],
                    'cash_account_id' => $row['cash_account_id'],
                    'lines'           => [['account_id' => $row['counter_account_id'], 'amount' => abs($amount), 'description' => 'Saldo Awal Rekening']],
                    'description'     => $row['description'] ?? 'Saldo Awal Rekening',
                    'reference'       => 'SALDO-AWAL',
                ], $userId);
                $created++;
            }
        });

        return back()->with('success', "{$created} saldo awal rekening tercatat — jurnal otomatis terbentuk.");
    }

    /** Unduh template Excel (.xlsx) untuk import massal saldo awal rekening. */
    public function template(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($request->user()?->can('cashbook.opening.view'), 403);

        $rek   = BsAccount::where('is_postable', true)->where('code', 'like', '101010%')->orderBy('code')->first();
        $lawan = BsAccount::where('is_postable', true)->where('type', 'equity')->orderBy('code')->first()
            ?? BsAccount::where('is_postable', true)->where('id', '!=', $rek?->id)->orderBy('code')->first();

        $example = [
            $rek?->code ?? '10101010101',
            $lawan?->code ?? '30101010101',
            now()->startOfYear()->toDateString(),
            '1000000',
            'CONTOH — hapus baris ini sebelum mengisi data Anda',
        ];
        $bin = SimpleXlsx::toString([self::IMPORT_HEADER, $example]);

        return response($bin, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Template-Saldo-Awal-Rekening.xlsx"',
        ]);
    }

    /**
     * Import massal saldo awal rekening dari .xlsx/.xls/.csv. Rekening & akun lawan diresolusi dari KODE.
     * Rekening yang sudah punya saldo awal dilewati (cegah dobel saat re-import).
     */
    public function import(Request $request, RecordCashTransaction $action): RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.opening.create'), 403);
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

        $iRek   = $find(fn ($h) => (str_contains($h, 'REKENING') || str_contains($h, 'KAS') || str_contains($h, 'BANK')) && ! str_contains($h, 'LAWAN'));
        $iLawan = $find(fn ($h) => str_contains($h, 'LAWAN') || str_contains($h, 'MODAL') || (str_contains($h, 'AKUN') && ! str_contains($h, 'REKENING')));
        $iDate  = $find(fn ($h) => str_contains($h, 'TGL') || str_contains($h, 'TANGGAL') || str_contains($h, 'DATE'));
        $iAmt   = $find(fn ($h) => str_contains($h, 'SALDO') || str_contains($h, 'NOMINAL') || str_contains($h, 'JUMLAH') || str_contains($h, 'NILAI') || str_contains($h, 'AMOUNT'));
        $iDesc  = $find(fn ($h) => str_contains($h, 'KETERANGAN') || str_contains($h, 'DESC') || str_contains($h, 'CATATAN'));

        $missing = [];
        if ($iRek === null) { $missing[] = 'Kode Rekening'; }
        if ($iLawan === null) { $missing[] = 'Kode Akun Lawan'; }
        if ($iDate === null) { $missing[] = 'Tgl'; }
        if ($iAmt === null) { $missing[] = 'Saldo Awal'; }
        if ($missing) {
            return back()->with('error', 'Kolom wajib tidak ditemukan: '.implode(', ', $missing).'. Unduh template untuk format yang benar.');
        }

        $cashByCode = BsAccount::where('is_postable', true)->where('code', 'like', '101010%')->get(['id', 'code'])
            ->keyBy(fn ($a) => strtoupper(trim((string) $a->code)));
        $accByCode  = BsAccount::where('is_postable', true)->get(['id', 'code'])
            ->keyBy(fn ($a) => strtoupper(trim((string) $a->code)));
        $existing   = BsCashTransaction::where('reference', 'SALDO-AWAL')->pluck('cash_account_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])->all();

        $created  = 0;
        $skipped  = 0;
        $errors   = [];
        $payloads = [];

        foreach ($rows as $n => $r) {
            $rowNo     = $n + 2;
            $get       = fn (?int $i) => $i === null ? '' : trim((string) ($r[$i] ?? ''));
            $rekCode   = $get($iRek);
            $lawanCode = $get($iLawan);
            $dateRaw   = $get($iDate);
            $amtRaw    = $get($iAmt);
            $desc      = $get($iDesc);

            if ($rekCode === '' && $lawanCode === '' && $amtRaw === '') {
                continue;
            }

            $rek = $cashByCode->get(strtoupper($rekCode));
            if (! $rek) {
                $errors[] = "Baris {$rowNo}: rekening kas/bank '{$rekCode}' tidak ditemukan.";
                $skipped++;

                continue;
            }
            $lawan = $accByCode->get(strtoupper($lawanCode));
            if (! $lawan) {
                $errors[] = "Baris {$rowNo}: akun lawan '{$lawanCode}' tidak ditemukan.";
                $skipped++;

                continue;
            }
            if ((int) $rek->id === (int) $lawan->id) {
                $errors[] = "Baris {$rowNo}: rekening & akun lawan tidak boleh sama.";
                $skipped++;

                continue;
            }
            $date = $this->parseImportDate($dateRaw);
            if ($date === null) {
                $errors[] = "Baris {$rowNo}: Tgl '{$dateRaw}' tidak valid (pakai format YYYY-MM-DD).";
                $skipped++;

                continue;
            }
            $amount = $this->parseImportAmount($amtRaw);
            if ($amount === null || abs($amount) < 0.01) {
                $errors[] = "Baris {$rowNo}: Saldo Awal '{$amtRaw}' tidak valid.";
                $skipped++;

                continue;
            }
            if (isset($existing[(int) $rek->id])) {
                $errors[] = "Baris {$rowNo}: rekening {$rek->code} sudah punya saldo awal (dilewati).";
                $skipped++;

                continue;
            }
            $existing[(int) $rek->id] = true; // cegah duplikat dalam file yang sama

            $payloads[] = [
                'rek'    => (int) $rek->id,
                'lawan'  => (int) $lawan->id,
                'date'   => $date,
                'amount' => round($amount, 2),
                'desc'   => $desc !== '' ? $desc : 'Saldo Awal Rekening',
            ];
            $created++;
        }

        if ($created === 0) {
            return back()->with('error', 'Tidak ada baris yang diimpor. '.implode(' ', array_slice($errors, 0, 8)));
        }

        $userId = $request->user()->id;
        DB::transaction(function () use ($payloads, $action, $userId): void {
            foreach ($payloads as $p) {
                $action->execute([
                    'trx_type'        => $p['amount'] >= 0 ? 'in' : 'out',
                    'trx_date'        => $p['date'],
                    'cash_account_id' => $p['rek'],
                    'lines'           => [['account_id' => $p['lawan'], 'amount' => abs($p['amount']), 'description' => 'Saldo Awal Rekening']],
                    'description'     => $p['desc'],
                    'reference'       => 'SALDO-AWAL',
                ], $userId);
            }
        });

        $msg  = "{$created} saldo awal rekening diimpor".($skipped ? ", {$skipped} baris dilewati/gagal" : '').'.';
        $resp = back()->with('success', $msg);
        if ($errors) {
            $resp->with('warning', implode(' · ', array_slice($errors, 0, 8)).(count($errors) > 8 ? ' … (+'.(count($errors) - 8).' lainnya)' : ''));
        }

        return $resp;
    }

    /** Parse tanggal import: YYYY-MM-DD, DD/MM/YYYY, DD-MM-YYYY, serial Excel. Null jika tak valid. */
    private function parseImportDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
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

    /** Parse nominal import (boleh negatif): toleran format Indonesia (1.000.000,50) & internasional + simbol 'Rp'. */
    private function parseImportAmount(string $raw): ?float
    {
        $raw = preg_replace('/[^\d.,\-]/', '', trim($raw)) ?? '';
        if ($raw === '' || $raw === '-') {
            return null;
        }
        $hasComma = str_contains($raw, ',');
        $hasDot   = str_contains($raw, '.');
        if ($hasComma && $hasDot) {
            $raw = str_replace(['.', ','], ['', '.'], $raw);
        } elseif ($hasComma) {
            $raw = str_replace(',', '.', $raw);
        } elseif ($hasDot) {
            $parts = explode('.', $raw);
            if (count($parts) > 2 || (count($parts) === 2 && strlen(end($parts)) === 3)) {
                $raw = str_replace('.', '', $raw);
            }
        }

        return is_numeric($raw) ? round((float) $raw, 2) : null;
    }

    public function destroy(Request $request, BsCashTransaction $cashTransaction): RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.opening.delete'), 403);
        abort_unless($cashTransaction->reference === 'SALDO-AWAL', 422, 'Hanya entri saldo awal yang bisa dihapus di sini.');

        if ($cashTransaction->journal_id) {
            $j = BsJournal::find($cashTransaction->journal_id);
            if ($j) {
                $p = BsFiscalPeriod::where('year', $j->period_year)->where('month', $j->period_month)->first();
                abort_if($p && $p->status === 'closed', 422, "Periode {$j->period_month}/{$j->period_year} sudah ditutup.");
                $j->lines()->delete();
                $j->delete();
            }
        }
        $cashTransaction->lines()->delete();
        $cashTransaction->delete();

        return back()->with('success', 'Saldo awal rekening dihapus.');
    }
}
