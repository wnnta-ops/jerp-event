<?php

declare(strict_types=1);

/*
 * Uji keputusan meeting Budget Control 3 Nov 2026.
 *
 * Yang dijaga:
 *   1. Realisasi = sisi pengeluaran saja: kredit di akun beban tidak mengurangi realisasi, tidak
 *      membuka ruang di penjaga budget.
 *   2. Koreksi Budget per bulan (layar Koreksi): isian nilai baru per bulan → selisihnya saja yang
 *      diajukan, masuk Approval Inbox (satu tahap Finance), disetujui → hanya bulan itu yang berubah.
 *      Budget lama tanpa rincian bulanan dirinci dulu (tidak hilang).
 *   3. Alokasi = pindah anggaran A → B per bulan, langsung berlaku; tidak boleh melebihi sisa bulan
 *      itu di A; asal = tujuan ditolak.
 *   4. Rincian per bulan (ikon mata): anggaran, realisasi, sisa per bulan + koreksi & alokasi.
 *   5. Invoice hutang: alokasi dari langkah Struktur menutup kekurangan bulan itu; Memo Urgent yang
 *      sudah disetujui Direktur bisa ditarik (satu memo, tidak bisa dipakai invoice lain); status
 *      memo tampil di daftar invoice.
 *
 * Data DB DIBATALKAN (rollback).
 *
 * Jalankan:  php tests/Manual/uji-budget-meeting-0311.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Approval\Actions\ActOnApproval;
use App\Domain\Approval\Models\Approval;
use App\Domain\ApprovalInbox\Actions\SubmitMemoInternal;
use App\Domain\ApprovalInbox\Models\MemoInternal;
use App\Domain\ApprovalInbox\Services\MemoUntukInvoice;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsBudgetAllocation;
use App\Domain\BusinessSupport\Models\BsBudgetCorrection;
use App\Domain\BusinessSupport\Services\BudgetAllocationService;
use App\Domain\BusinessSupport\Services\BudgetGuardService;
use App\Domain\BusinessSupport\Services\BudgetRealizationService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Web\BusinessSupport\Budget\BudgetController;
use App\Http\Controllers\Web\BusinessSupport\Budget\BudgetCorrectionController;
use App\Http\Controllers\Web\BusinessSupport\Payable\ApInvoiceController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$tenantId = (int) DB::table('tenants')->orderBy('id')->value('id');
app()->instance('tenant.id', $tenantId);

$lulus = 0;
$gagal = 0;
$cek = function (string $nama, bool $ok, string $ket = '') use (&$lulus, &$gagal) {
    if ($ok) {
        $lulus++;
        echo "  OK    {$nama}\n";
    } else {
        $gagal++;
        echo "  GAGAL {$nama}".($ket !== '' ? " — {$ket}" : '')."\n";
    }
};
$sama = fn ($a, $b) => abs((float) $a - (float) $b) < 0.01;
$kode = function (callable $fn): ?string {
    try {
        $fn();
    } catch (BusinessRuleException $e) {
        return $e->errorCode();
    }

    return null;
};

DB::beginTransaction();

try {
    $buatUser = function (string $nama, array $roles) use ($tenantId): User {
        $u = User::create([
            'tenant_id' => $tenantId, 'name' => $nama, 'username' => 'u'.Str::random(5),
            'email' => Str::slug($nama).'.'.Str::random(6).'@uji.test',
            'password' => bcrypt(Str::random(16)), 'is_active' => true,
        ]);
        $u->syncRoles($roles);

        return $u->fresh();
    };
    $admin = User::where('email', '1234@jerp.test')->firstOrFail();
    $accounting = $buatUser('Accounting Meeting Uji', ['Accounting']);
    $finance = $buatUser('Finance Meeting Uji', ['Finance']);
    $gm = $buatUser('GM Meeting Uji', ['GM']);
    $direktur = $buatUser('Direktur Meeting Uji', ['Direktur']);
    $sebagai = function (User $u) { auth()->setUser($u); };
    $minta = function (string $method, array $isi = []) {
        $r = Request::create('/uji', $method, $isi, [], [], ['HTTP_REFERER' => url('/business-support/budget')]);
        $r->setLaravelSession(app('session.store'));
        $r->setUserResolver(fn () => auth()->user());
        app()->instance('request', $r);

        return $r;
    };
    $sesi = function (string $k) {
        $v = app('session.store')->get($k);
        app('session.store')->forget($k);

        return (string) $v;
    };

    $tahun = 2039;
    $akunBeban = DB::table('bs_accounts')->where('type', 'expense')->where('is_postable', 1)->where('cost_center_optional', 0)->orderBy('code')->limit(2)->pluck('id')->all();
    [$akunA, $akunB] = array_map('intval', $akunBeban);
    $cc = (int) DB::table('bs_cost_centers')->whereNull('deleted_at')->orderBy('id')->value('id');
    $bulanan = fn (float $v) => array_combine(array_map(fn ($m) => 'm'.$m, range(1, 12)), array_fill(0, 12, $v));
    $budgetA = BsBudget::create(['doc_no' => 'UJI-MT-A', 'year' => $tahun, 'account_id' => $akunA, 'cost_center_id' => $cc,
        'amount' => 12_000_000, 'status' => 'approved'] + $bulanan(1_000_000));
    $budgetB = BsBudget::create(['doc_no' => 'UJI-MT-B', 'year' => $tahun, 'account_id' => $akunB, 'cost_center_id' => $cc,
        'amount' => 6_000_000, 'status' => 'approved'] + $bulanan(500_000));

    $seqJ = 0;
    $jurnal = function (string $tgl, int $akun, float $debit, float $kredit) use (&$seqJ, $tenantId, $tahun, $cc): void {
        $seqJ++;
        $jid = 950000 + $seqJ;
        DB::table('bs_journals')->insert([
            'id' => $jid, 'tenant_id' => $tenantId, 'journal_number' => 'UJI-MT-JV-'.$seqJ, 'journal_date' => $tgl,
            'period_year' => $tahun, 'period_month' => (int) substr($tgl, 5, 2), 'source' => 'manual', 'description' => 'Uji meeting',
            'status' => 'final', 'total_debit' => $debit, 'total_credit' => $kredit, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('bs_journal_lines')->insert([
            'id' => $jid * 10, 'journal_id' => $jid, 'account_id' => $akun, 'cost_center_id' => $cc, 'description' => 'uji',
            'debit' => $debit, 'credit' => $kredit, 'currency' => 'IDR', 'kurs' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    echo "\n=== 1. Realisasi = pengeluaran saja ===\n";
    $jurnal("{$tahun}-03-05", $akunA, 600_000, 0);
    $jurnal("{$tahun}-03-20", $akunA, 0, 250_000);   // kredit pembatalan
    $real = app(BudgetRealizationService::class);
    $cek('realisasi Maret = debit saja (kredit tidak mengurangi)', $sama($real->total($tahun, $akunA, $cc, "{$tahun}-03-01", "{$tahun}-03-31"), 600_000));
    $cek('kontribusi baris kredit di akun beban = 0', $sama($real->contribution($akunA, 0, 250_000), 0));
    $guard = app(BudgetGuardService::class);
    $cek('baris debit + kredit di dokumen yang sama tidak saling menutup (sisa 400 rb, debit 500 rb → OVER_BUDGET)',
        $kode(fn () => $guard->assertWithinBudget([
            ['account_id' => $akunA, 'cost_center_id' => $cc, 'debit' => 500_000, 'credit' => 0],
            ['account_id' => $akunA, 'cost_center_id' => $cc, 'debit' => 0, 'credit' => 200_000],
        ], null, "{$tahun}-03-25")) === 'OVER_BUDGET');

    echo "\n=== 2. Koreksi Budget per bulan ===\n";
    // Chain Koreksi & Alokasi (satu tahap Finance) diarahkan ke Finance uji — ikut dibatalkan bersama transaksi.
    DB::table('approval_chain_steps')->whereIn('approval_chain_id', DB::table('approval_chains')->whereIn('code', ['KB-DEFAULT', 'AL-DEFAULT'])->pluck('id'))
        ->update(['approver_type' => 'user', 'approver_value' => (string) $finance->id]);
    $sebagai($admin);
    $ctlKor = app(BudgetCorrectionController::class);
    app()->call([$ctlKor, 'store'], ['request' => $minta('POST', [
        'budget_id' => $budgetA->id, 'corr_date' => "{$tahun}-03-01",
        'months' => [3 => 1_800_000, 4 => 1_000_000, 5 => 700_000], 'note' => 'Uji koreksi per bulan',
    ])]);
    $k = BsBudgetCorrection::where('budget_id', $budgetA->id)->latest('id')->first();
    $cek('koreksi tercatat dengan selisih per bulan (Mar +800 rb, Mei −300 rb; Apr tidak berubah → tidak ikut)',
        $k && $sama($k->months[3] ?? null, 800_000) && $sama($k->months[5] ?? null, -300_000) && ! isset($k->months[4]), json_encode($k?->months));
    $cek('nilai baru = tahunan + total selisih', $k && $sama($k->new_amount, 12_500_000));
    $a = $k ? Approval::with('steps')->find($k->approval_id) : null;
    $cek('masuk Approval Inbox (1 tahap Finance)', $a && $a->steps->count() === 1);
    $cek('label periode "Mar, Mei 2039"', $k && BudgetCorrectionController::labelPeriode($k) === "Mar, Mei {$tahun}");
    $cek('belum disetujui → budget belum berubah', $sama($budgetA->fresh()->m3, 1_000_000));
    $act = app(ActOnApproval::class);
    // Chain memo per user (System › Approval Chain) diarahkan ke approver uji — ikut dibatalkan bersama transaksi.
    $arahkanChainMemo = function (array $labelKeUser): void {
        $chains = DB::table('approval_chains')->where('document_type', 'memo_internal')->pluck('id');
        foreach ($labelKeUser as $label => $u) {
            DB::table('approval_chain_steps')->whereIn('approval_chain_id', $chains)->where('label', $label)
                ->update(['approver_type' => 'user', 'approver_value' => (string) $u->id]);
        }
    };
    $arahkanChainMemo(['Accounting' => $accounting, 'Finance' => $finance, 'GM' => $gm, 'Direktur' => $direktur, 'MW / BA' => $admin]);
    $sebagai($finance);
    $act->execute(Approval::with('steps')->find($a->id), 'approve');
    $b = $budgetA->fresh();
    $cek('disetujui → Mar 1,8 jt, Mei 700 rb, Apr tetap', $sama($b->m3, 1_800_000) && $sama($b->m5, 700_000) && $sama($b->m4, 1_000_000), "{$b->m3} {$b->m4} {$b->m5}");
    $cek('anggaran tahunan = 12,5 jt', $sama($b->amount, 12_500_000));
    $sebagai($admin);
    app()->call([$ctlKor, 'store'], ['request' => $minta('POST', ['budget_id' => $budgetA->id, 'corr_date' => "{$tahun}-03-01", 'months' => [3 => 1_800_000]])]);
    $cek('tanpa bulan yang berubah → ditolak', str_contains($sesi('error'), 'Belum ada bulan'));

    $tanpaRinci = BsBudget::create(['doc_no' => 'UJI-MT-C', 'year' => $tahun + 1, 'account_id' => $akunB, 'cost_center_id' => null,
        'amount' => 1_200_000, 'status' => 'approved']);
    $tanpaRinci->tambahBulan([2 => 50_000]);
    $cek('budget tanpa rincian bulanan → dirinci 100 rb/bulan dulu, lalu Feb +50 rb', $sama($tanpaRinci->m1, 100_000) && $sama($tanpaRinci->m2, 150_000) && $sama($tanpaRinci->m12, 100_000) && $sama($tanpaRinci->amount, 1_250_000));

    echo "\n=== 3. Alokasi A → B per bulan ===\n";
    $svc = app(BudgetAllocationService::class);
    $cek('sisa Maret A = 1,8 jt − 600 rb', $sama($svc->sisaBulan($budgetA->fresh(), 3), 1_200_000));
    $al = DB::transaction(fn () => $svc->pindah($budgetA->id, $budgetB->id, [3 => 700_000, 6 => 400_000], "{$tahun}-03-10", 'Pindah COA', $admin->id));
    $a2 = $budgetA->fresh();
    $b2 = $budgetB->fresh();
    $cek('A: Mar 1,1 jt, Jun 600 rb, tahunan −1,1 jt', $sama($a2->m3, 1_100_000) && $sama($a2->m6, 600_000) && $sama($a2->amount, 11_400_000), "{$a2->m3} {$a2->m6} {$a2->amount}");
    $cek('B: Mar 1,2 jt, Jun 900 rb, tahunan +1,1 jt', $sama($b2->m3, 1_200_000) && $sama($b2->m6, 900_000) && $sama($b2->amount, 7_100_000));
    $cek('alokasi langsung approved, tercatat asal → tujuan + per bulan', $al->status === 'approved' && (int) $al->to_budget_id === $budgetB->id && $sama($al->amount, 1_100_000) && $sama($al->months[6] ?? null, 400_000));
    $cek('melebihi sisa bulan asal → ALLOC_OVER', $kode(fn () => $svc->pindah($budgetA->id, $budgetB->id, [3 => 600_000], "{$tahun}-03-10", null, $admin->id)) === 'ALLOC_OVER');
    $cek('asal = tujuan → ALLOC_SAME', $kode(fn () => $svc->pindah($budgetA->id, $budgetA->id, [3 => 1], "{$tahun}-03-10", null, $admin->id)) === 'ALLOC_SAME');
    $cek('tanpa nilai → ALLOC_EMPTY', $kode(fn () => $svc->pindah($budgetA->id, $budgetB->id, [3 => 0], "{$tahun}-03-10", null, $admin->id)) === 'ALLOC_EMPTY');

    echo "\n=== 4. Rincian per bulan (ikon mata) ===\n";
    $ctlBud = app(BudgetController::class);
    $pb = $ctlBud->perBulan($budgetA->fresh());
    $cek('Maret: anggaran 1,1 jt · realisasi 600 rb · sisa 500 rb', $sama($pb['anggaran'][2], 1_100_000) && $sama($pb['realisasi'][2], 600_000) && $sama($pb['sisa'][2], 500_000), json_encode([$pb['anggaran'][2], $pb['realisasi'][2], $pb['sisa'][2]]));
    $rin = $ctlBud->rincian($minta('GET', ['year' => $tahun, 'account_id' => $akunA, 'cc' => (string) $cc, 'sd' => 3, 'budget_id' => $budgetA->id]))->getData(true);
    $d = $rin['budgets'][0] ?? [];
    $cek('rincian memuat koreksi (+500 rb) & alokasi keluar (−1,1 jt)', $sama(collect($d['koreksi'] ?? [])->sum('nilai'), 500_000) && $sama(collect($d['alokasi'] ?? [])->sum('nilai'), -1_100_000), json_encode([$d['koreksi'] ?? null, $d['alokasi'] ?? null]));
    $cek('jurnal realisasi s.d. Mar tanpa baris kredit', count($rin['jurnal']) === 1 && $sama($rin['jurnal'][0]['nilai'], 600_000));

    echo "\n=== 5. Memo Urgent & alokasi dari invoice hutang ===\n";
    $sebagai($admin);
    $memo = app(SubmitMemoInternal::class)->execute(['jenis' => 'reguler', 'memo_date' => "{$tahun}-03-01", 'department' => 'Uji', 'subject' => 'Memo uji meeting', 'body' => 'Isi', 'amount' => 900_000]);
    $pilihan = app(MemoUntukInvoice::class);
    $cek('memo baru (belum sampai Direktur) belum bisa ditarik', ! $pilihan->boleh($memo->id));
    foreach ([$accounting, $finance, $gm, $direktur] as $u) {
        $sebagai($u);
        $act->execute(Approval::with('steps')->find($memo->approval_id), 'approve');
    }
    $memo->refresh()->load('approval.steps');
    $cek('setelah Direktur menyetujui → bisa ditarik', $pilihan->boleh($memo->id));
    $cek('status memo = "Menunggu MW / BA"', MemoUntukInvoice::status($memo) === 'Menunggu MW / BA', MemoUntukInvoice::status($memo));

    // Memo menyebut supplier: wajib di form; invoice yang menarik memo harus ke supplier yang sama.
    $supIds = DB::table('bs_suppliers')->where('is_active', 1)->whereNull('deleted_at')
        ->where(fn ($q) => $q->whereNull('category')->orWhereNotIn('category', \App\Domain\BusinessSupport\Services\PenerimaHutang::KATEGORI))
        ->orderBy('id')->limit(2)->pluck('id')->map(fn ($v) => (int) $v)->all();
    $sebagai($admin);
    try {
        app()->call([app(\App\Http\Controllers\Web\ApprovalInbox\ApprovalInboxController::class), 'store'],
            ['request' => $minta('POST', ['memo_date' => "{$tahun}-03-01", 'cost_center_id' => DB::table('bs_cost_centers')->value('id'), 'subject' => 'x', 'body' => 'x', 'amount' => 1000])]);
        $tolak = false;
    } catch (\Illuminate\Validation\ValidationException $e) {
        $tolak = isset($e->errors()['supplier_id']);
    }
    $cek('form Memo Urgent menolak tanpa supplier', $tolak);
    $memoSup = app(SubmitMemoInternal::class)->execute(['memo_date' => "{$tahun}-03-01", 'department' => 'Uji', 'supplier_id' => $supIds[0], 'subject' => 'Memo uji supplier', 'body' => 'Isi', 'amount' => 500_000]);
    $ringkas = app(MemoUntukInvoice::class)->ringkas($memoSup->load(['approval.steps', 'supplier']));
    $cek('ringkasan memo membawa supplier', $ringkas['supplier_id'] === $supIds[0] && $ringkas['supplier'] !== null, json_encode($ringkas));
    $cek('supplier sama → cocok; beda → tidak', $pilihan->supplierCocok($memoSup->id, $supIds[0]) && ! $pilihan->supplierCocok($memoSup->id, $supIds[1]));
    $cek('memo lama tanpa supplier cocok ke supplier mana pun', $pilihan->supplierCocok($memo->id, $supIds[1]));

    // Simpan invoice (jenis Reimburse — alurnya sama, tanpa wajib berkas) dengan alokasi + memo.
    $sebagai($admin);
    // Kategori "dipakai di" Reimburse disiapkan di dalam transaksi uji (data lokal hanya punya Invoice Hutang).
    $pakai = DB::table('bs_ap_category_usages')->where('jenis', 'invoice')->first();
    if ($pakai && ! DB::table('bs_ap_category_usages')->where('jenis', 'reimburse')->where('category_id', $pakai->category_id)->exists()) {
        DB::table('bs_ap_category_usages')->insert(['tenant_id' => $pakai->tenant_id, 'category_id' => $pakai->category_id, 'jenis' => 'reimburse',
            'credit_position' => $pakai->credit_position, 'payable_account_id' => $pakai->payable_account_id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $kategori = $pakai?->category_id;
    $karyawan = DB::table('bs_employees')->whereNull('deleted_at')->where('is_active', 1)->value('id');
    $hutang = (int) ($pakai?->payable_account_id ?? 0);
    if (! $kategori || ! $karyawan || ! $hutang) {
        $cek('data contoh reimburse tersedia (kategori, karyawan, akun hutang)', false);
    } else {
        $isi = [
            'employee_id' => $karyawan, 'category_id' => $kategori, 'supplier_invoice_no' => 'UJI-MT-1', 'invoice_date' => "{$tahun}-03-15",
            'term_days' => 7, 'description' => 'Uji alokasi dari invoice', 'dpp' => 1_400_000, 'ppn_mode' => 'none', 'pph_mode' => 'none',
            'journal_lines' => [
                ['account_id' => $akunB, 'cost_center_id' => $cc, 'debit' => 1_400_000, 'credit' => 0, 'description' => 'beban'],
                ['account_id' => $hutang, 'cost_center_id' => null, 'debit' => 0, 'credit' => 1_400_000, 'description' => 'hutang'],
            ],
            'memo_internal_id' => $memo->id,
        ];
        $ctlInv = app(ApInvoiceController::class);
        // Sisa Maret B = 1,2 jt → kurang 200 rb. Tanpa alokasi/koreksi ditolak.
        $sebelum = BsApInvoice::count();
        app()->call([$ctlInv, 'storeJenis'], ['request' => $minta('POST', $isi), 'jenis' => 'reimburse']);
        $tolak = $sesi('error').' '.json_encode(app('session.store')->pull('budgetError'));
        $cek('melebihi sisa bulan tanpa alokasi/koreksi → ditolak (OVER_BUDGET)', BsApInvoice::count() === $sebelum && str_contains($tolak, 'Melebihi anggaran'), $tolak);

        // Alokasi WAJIB disetujui Finance dulu (Pengajuan Budget Invoice) — simpan langsung ditolak.
        app()->call([$ctlInv, 'storeJenis'], ['request' => $minta('POST', $isi + ['alokasi' => [
            ['budget_id' => $budgetB->id, 'dari_budget_id' => $budgetA->id, 'nilai' => 200_000],
        ]]), 'jenis' => 'reimburse']);
        $cek('simpan dengan alokasi tanpa pengajuan disetujui → ditolak', BsApInvoice::count() === $sebelum && str_contains($sesi('error'), 'disetujui'));
        $ajukan = fn (array $alokasi, ?int $ulang = null) => app()->call([$ctlInv, 'ajukanBudget'], ['request' => $minta('POST',
            ['jenis' => 'reimburse', 'data' => $isi + ['alokasi' => $alokasi], 'pengajuan_id' => $ulang])]);
        $r = $ajukan([['budget_id' => $budgetB->id, 'dari_budget_id' => $budgetA->id, 'nilai' => 100_000]]);
        $cek('ajukan alokasi kurang (100 rb) → ditolak, anggaran A/B tidak berubah', $r->getStatusCode() === 422 && str_contains((string) ($r->getData(true)['message'] ?? ''), 'Melebihi anggaran')
            && $sama($budgetA->fresh()->m3, 1_100_000) && $sama($budgetB->fresh()->m3, 1_200_000), json_encode($r->getData(true)));
        $r = $ajukan([['budget_id' => $budgetB->id, 'dari_budget_id' => $budgetA->id, 'nilai' => 200_000, 'keterangan' => 'tutup kurang']]);
        $req = App\Domain\BusinessSupport\Models\BsApBudgetRequest::find($r->getData(true)['id'] ?? 0);
        $cek('ajukan approval → pengajuan menunggu, nomor invoice dipesan, belum ada invoice', $req && $req->status === 'menunggu' && str_starts_with((string) $req->invoice_number, 'AP.') && BsApInvoice::count() === $sebelum, json_encode($r->getData(true)));
        $alInv = BsBudgetAllocation::where('source_type', App\Domain\BusinessSupport\Models\BsApBudgetRequest::SOURCE)->where('source_id', $req?->id)->first();
        // Pengajuan = SATU approval ber-chain Invoice Hutang; alokasinya turunan (tanpa approval sendiri).
        $cek('alokasi tercatat (Mar, 200 rb, menunggu, turunan approval pengajuan), anggaran A/B belum berubah', $alInv && $alInv->status === 'baru' && ! $alInv->approval_id && $req->approval_id && $sama($alInv->months[3] ?? null, 200_000)
            && str_contains((string) $alInv->notes, (string) $req->invoice_number) && $sama($budgetB->fresh()->m3, 1_200_000) && $sama($budgetA->fresh()->m3, 1_100_000));
        app()->call([$ctlInv, 'storeJenis'], ['request' => $minta('POST', $isi + ['pengajuan_id' => $req->id]), 'jenis' => 'reimburse']);
        $cek('selama menunggu approval → invoice belum bisa disimpan', BsApInvoice::count() === $sebelum && str_contains($sesi('error'), 'menunggu approval'));
        $ctlAl = app(App\Http\Controllers\Web\BusinessSupport\Budget\BudgetAllocationController::class);
        $daftar = (fn () => $this->props)->call(app()->call([$ctlAl, 'approvals'], ['request' => $minta('GET')]))['rows'];
        $cek('tampil di Approval Alokasi Budget — lihat saja, ikut approval invoice', ($rw = $daftar->firstWhere('id', $alInv->id)) && $rw['invoice'] === $req->invoice_number && $rw['to_ref'] === $budgetB->doc_no
            && $rw['ikut_invoice'] && ! $rw['bisa'] && str_starts_with((string) $rw['tahap'], 'Ikut approval invoice'), json_encode($rw ?? null));
        $cek('sisa bulan A sudah dipesan alokasi yang menunggu', $sama(app(App\Domain\BusinessSupport\Services\BudgetAllocationService::class)->sisaBulan($budgetA->fresh(), 3),
            app(App\Domain\BusinessSupport\Services\BudgetAllocationService::class)->sisaBulan($budgetA->fresh(), 3, null, $alInv->id) - 200_000));
        app()->call([$ctlAl, 'approveBatch'], ['request' => $minta('POST', ['ids' => [$alInv->id], 'decision' => 'approve'])]);
        $cek('tidak bisa diputuskan terpisah dari Budget Control', $alInv->fresh()->status === 'baru' && str_contains($sesi('error'), 'ikut approval invoice'));
        // Disetujui lewat chain Invoice Hutang (approver uji per label tahap).
        $chainInv = DB::table('approval_chains')->where('document_type', 'ap_invoice')->where('is_active', true)->pluck('id');
        $apReq = Approval::with('steps')->find($req->approval_id);
        foreach ($apReq->steps->sortBy('level') as $s) {
            $peran = ['MW / BA' => 'Owner'][$s->label] ?? $s->label;
            $approver = $buatUser($s->label.' Inv Meeting Uji', [$peran]);
            DB::table('approval_steps')->where('id', $s->id)->update(['approver_type' => 'user', 'approver_value' => (string) $approver->id]);
            $sebagai($approver);
            $act->execute(Approval::with('steps')->find($req->approval_id), 'approve');
        }
        $sebagai($admin);
        $cek('approval disetujui penuh → budget B Maret +200 rb, A berkurang, pengajuan disetujui', $alInv->fresh()->status === 'approved' && $sama($budgetB->fresh()->m3, 1_400_000) && $sama($budgetA->fresh()->m3, 900_000)
            && $req->fresh()->status === 'disetujui', $sesi('error').$sesi('warning').' '.$req->fresh()->status);
        $cek('pengaju dapat notifikasi "disetujui"', DB::table('notifications')->where('notifiable_id', $admin->id)->where('type', 'budget.pengajuan_invoice')->where('data', 'like', '%disetujui%')->exists());
        app()->call([$ctlInv, 'storeJenis'], ['request' => $minta('POST', $isi + ['pengajuan_id' => $req->id, 'alokasi' => [
            ['budget_id' => $budgetB->id, 'dari_budget_id' => $budgetA->id, 'nilai' => 200_000],
        ]]), 'jenis' => 'reimburse']);
        $inv = BsApInvoice::latest('id')->first();
        $galat = $sesi('error').' '.json_encode(app('session.store')->pull('budgetError')).' '.json_encode(app('session.store')->get('errors')?->all());
        $cek('disetujui → Selesai: invoice tersimpan dengan nomor yang dipesan, pengajuan dipakai', BsApInvoice::count() === $sebelum + 1 && $inv?->invoice_number === $req->invoice_number
            && $req->fresh()->status === 'dipakai' && (int) $req->fresh()->ap_invoice_id === (int) $inv->id, $galat);
        $cek('alokasi tidak dibuat dua kali saat simpan', BsBudgetAllocation::where('to_budget_id', $budgetB->id)->where('status', '!=', 'batal')->whereIn('source_type', [BsBudgetAllocation::SOURCE_INVOICE, App\Domain\BusinessSupport\Models\BsApBudgetRequest::SOURCE])->count() === 1);
        // Invoice BIASA (tanpa Memo Urgent): alokasi tidak menahan — invoice langsung tersimpan, alokasinya
        // diajukan ke Finance di belakang.
        $isi2 = array_diff_key($isi, ['memo_internal_id' => 1]) + ['supplier_invoice_no' => 'UJI-MT-2', 'description' => 'Uji tanpa memo'];
        $isi2['supplier_invoice_no'] = 'UJI-MT-2'; $isi2['description'] = 'Uji tanpa memo';
        $isi2['journal_lines'][0]['debit'] = 100_000; $isi2['journal_lines'][1]['credit'] = 100_000; $isi2['dpp'] = 100_000;
        $nAl = BsBudgetAllocation::count();
        app()->call([$ctlInv, 'storeJenis'], ['request' => $minta('POST', $isi2 + ['alokasi' => [
            ['budget_id' => $budgetB->id, 'dari_budget_id' => $budgetA->id, 'nilai' => 100_000, 'keterangan' => 'tanpa memo'],
        ]]), 'jenis' => 'reimburse']);
        $inv2 = BsApInvoice::latest('id')->first();
        $galat2 = $sesi('error').' '.json_encode(app('session.store')->pull('budgetError'));
        $al2 = BsBudgetAllocation::where('source_type', BsBudgetAllocation::SOURCE_INVOICE)->where('source_id', $inv2?->id)->first();
        $cek('invoice tanpa Memo Urgent + alokasi → langsung tersimpan (tidak ditahan Finance)', $inv2?->description === 'Uji tanpa memo', $galat2);
        $cek('…alokasinya punya approval sendiri (reimburse tanpa approval invoice) & anggaran belum pindah', $al2 && $al2->status === 'baru' && $al2->approval_id && $sama($budgetB->fresh()->m3, 1_400_000));
        $cek('memo tertaut ke invoice', (int) $inv?->memo_internal_id === $memo->id);
        $cek('Memo Urgent → jatuh tempo hari itu juga (0 hari)', (int) $inv->term_days === 0 && $inv->due_date?->toDateString() === ($inv->receive_date ?? $inv->invoice_date)?->toDateString(),
            $inv->term_days.' '.$inv->due_date?->toDateString());
        $cek('memo yang sudah dipakai tidak bisa ditarik invoice lain', ! $pilihan->boleh($memo->id));
        $cek('…tetapi tetap boleh untuk invoice itu sendiri (edit)', $pilihan->boleh($memo->id, $inv->id));

        $resp = app()->call([$ctlInv, 'indexJenis'] , ['request' => $minta('GET', []), 'jenis' => 'reimburse']);
        $props = (fn () => $this->props)->call($resp);
        $baris = collect($props['invoices']->items())->firstWhere('id', $inv->id);
        $cek('daftar invoice menampilkan status memo', ($baris['memo']['number'] ?? null) === $memo->memo_number && ($baris['memo']['status'] ?? null) === 'Menunggu MW / BA', json_encode($baris['memo'] ?? null));
    }

    echo "\n".str_repeat('─', 78)."\n";
    echo $gagal === 0 ? "SEMUA LULUS ({$lulus})\n" : "GAGAL: {$gagal}, lulus: {$lulus}\n";
} catch (Throwable $e) {
    echo "\nGALAT: ".$e->getMessage()."\n  di ".$e->getFile().':'.$e->getLine()."\n";
    $gagal++;
} finally {
    DB::rollBack();
    echo "(data uji dibatalkan)\n";
}

exit($gagal === 0 ? 0 : 1);
