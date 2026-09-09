<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Budget;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Upload Budget (BO: BudgetUpload2.aspx) — impor anggaran bulanan dari CSV.
 * Kolom: KodeAccount, NamaAccount, Januari..Desember (12 nilai bulanan).
 */
class BudgetUploadController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('budget.upload.view'), 403);

        return Inertia::render('BusinessSupport/Budget/Upload', [
            'year'        => (int) now()->year,
            'costCenters' => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'header'      => array_merge(['KodeAccount', 'NamaAccount'], BsBudget::MONTHS),
        ]);
    }

    /** Unduh template CSV. */
    public function template(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('budget.upload.view'), 403);
        $cols = array_merge(['KodeAccount', 'NamaAccount'], ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember']);
        $csv  = implode(',', $cols)."\n".'50302020219,BIAYA PERLENGKAPAN KANTOR,1000000,1000000,1000000,1000000,1000000,1000000,1000000,1000000,1000000,1000000,1000000,1000000'."\n";

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="Template-Upload-Budget.csv"');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        $v = $request->validate([
            'year'           => ['required', 'integer', 'min:2000', 'max:2100'],
            'project'        => ['nullable', 'string', 'max:30'],
            'cost_center_id' => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            'file'           => ['required', 'file', 'mimes:csv,txt', 'max:4096'],
        ]);

        $rows = array_map('str_getcsv', file($request->file('file')->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        if (count($rows) === 0) {
            return back()->with('error', 'File kosong.');
        }

        // Lewati baris header bila kolom pertama bukan kode akun yang dikenal.
        $first = trim((string) ($rows[0][0] ?? ''));
        if (! BsAccount::where('code', $first)->exists()) {
            array_shift($rows);
        }

        $ok = 0;
        $failed = [];
        DB::transaction(function () use ($rows, $v, $request, &$ok, &$failed): void {
            foreach ($rows as $i => $r) {
                $code = trim((string) ($r[0] ?? ''));
                if ($code === '') {
                    continue;
                }
                $acc = BsAccount::where('code', $code)->first();
                if (! $acc) {
                    $failed[] = 'Baris '.($i + 1).": COA $code tidak ditemukan";
                    continue;
                }
                $months = [];
                $sum    = 0.0;
                for ($m = 1; $m <= 12; $m++) {
                    $raw = $r[$m + 1] ?? 0; // kolom 0=kode,1=nama,2..13=bulan
                    // toleran format: buang pemisah ribuan & karakter non-angka.
                    $val = round((float) preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string) $raw)), 2);
                    $months['m'.$m] = $val;
                    $sum += $val;
                }

                BsBudget::updateOrCreate(
                    ['tenant_id' => tenant_id(), 'year' => $v['year'], 'cost_center_id' => ($v['cost_center_id'] ?? null) ?: null, 'account_id' => $acc->id],
                    array_merge($months, [
                        'doc_no'     => BsBudget::nextDocNo((int) $v['year'], $v['project'] ?? null),
                        'project'    => $v['project'] ?? null,
                        'amount'     => $sum,
                        'status'     => 'submitted',
                        'submitted_at' => now(),
                        'created_by' => $request->user()->id,
                    ]),
                );
                $ok++;
            }
        });

        $msg = "$ok baris anggaran terupload (menunggu approval).";
        if ($failed) {
            return back()->with('error', $msg.' Gagal: '.implode('; ', array_slice($failed, 0, 10)));
        }

        return back()->with('success', $msg);
    }
}
