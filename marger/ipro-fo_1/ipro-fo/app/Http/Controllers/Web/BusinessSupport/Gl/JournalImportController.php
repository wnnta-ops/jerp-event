<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Gl;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Import Jurnal (CSV) → posting massal ke General Ledger.
 * Format kolom: TANGGAL, KODE_AKUN, DEBIT, KREDIT, KETERANGAN, REFERENSI
 * Baris dengan REFERENSI sama dikelompokkan menjadi satu jurnal (harus balance).
 */
class JournalImportController extends Controller
{
    public function form(Request $request): Response
    {
        abort_unless($request->user()?->canModule('gl'), 403);

        return Inertia::render('BusinessSupport/Gl/JournalImport', [
            'template' => "TANGGAL,KODE_AKUN,DEBIT,KREDIT,KETERANGAN,REFERENSI\n2026-06-30,50101010101,1000000,0,Beban contoh,JU-001\n2026-06-30,10101010101,0,1000000,Kas keluar,JU-001",
        ]);
    }

    public function store(Request $request, JournalService $journal): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $request->validate([
            'file'    => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $rows = array_map('str_getcsv', file($request->file('file')->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $header = array_map(fn ($h) => strtoupper(trim((string) $h)), array_shift($rows) ?? []);
        $idx = array_flip($header);
        foreach (['TANGGAL', 'KODE_AKUN', 'DEBIT', 'KREDIT', 'REFERENSI'] as $col) {
            if (! isset($idx[$col])) {
                return back()->with('error', "Kolom '{$col}' tidak ada di CSV. Header wajib: TANGGAL, KODE_AKUN, DEBIT, KREDIT, KETERANGAN, REFERENSI.");
            }
        }

        $accByCode = BsAccount::pluck('id', 'code');
        $groups = [];
        $errors = [];
        foreach ($rows as $i => $r) {
            $ref  = trim((string) ($r[$idx['REFERENSI']] ?? ''));
            $code = trim((string) ($r[$idx['KODE_AKUN']] ?? ''));
            if ($ref === '' || $code === '') {
                continue;
            }
            if (! isset($accByCode[$code])) {
                $errors[] = 'Baris '.($i + 2).": akun {$code} tidak ditemukan.";
                continue;
            }
            $groups[$ref]['date'] ??= trim((string) ($r[$idx['TANGGAL']] ?? ''));
            $groups[$ref]['lines'][] = [
                'account_id'  => (int) $accByCode[$code],
                'debit'       => round((float) str_replace([',', ' '], '', (string) ($r[$idx['DEBIT']] ?? 0)), 2),
                'credit'      => round((float) str_replace([',', ' '], '', (string) ($r[$idx['KREDIT']] ?? 0)), 2),
                'description' => isset($idx['KETERANGAN']) ? trim((string) ($r[$idx['KETERANGAN']] ?? '')) : null,
            ];
        }

        if ($errors) {
            return back()->with('error', implode(' ', array_slice($errors, 0, 5)).(count($errors) > 5 ? ' …' : ''));
        }
        if (! $groups) {
            return back()->with('error', 'Tidak ada baris jurnal valid di CSV.');
        }

        if ($request->boolean('dry_run')) {
            return back()->with('success', 'Dry-run OK: '.count($groups).' jurnal siap diimport ('.array_sum(array_map(fn ($g) => count($g['lines']), $groups)).' baris). Hapus centang dry-run untuk memproses.');
        }

        $posted = 0;
        try {
            foreach ($groups as $ref => $g) {
                $journal->post([
                    'journal_date' => $g['date'], 'source' => 'import', 'reference' => $ref,
                    'description'  => 'Import Jurnal '.$ref, 'created_by' => $request->user()->id,
                ], $g['lines']);
                $posted++;
            }
        } catch (BusinessRuleException $e) {
            return back()->with('error', "Gagal di jurnal ke-{$posted}+1: ".$e->getMessage());
        }

        return redirect()->route('bs.gl.journal.index')->with('success', "{$posted} jurnal berhasil diimport ke General Ledger.");
    }
}
