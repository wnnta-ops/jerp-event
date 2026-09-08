<?php

/**
 * Pengajuan Bon Sementara — Cost Center WAJIB dan tertaut ke Budget Control.
 *
 * KENAPA DIJAGA KERAS. Cost center adalah dimensi yang dibaca BudgetRealizationService untuk
 * mencocokkan realisasi dengan plafon per (akun, cost center). Bon tanpa cost center tetap
 * tersimpan, jurnalnya tetap terbentuk, dan bebannya tetap masuk GL — tetapi TIDAK PERNAH
 * muncul sebagai realisasi di Budget Control. Tidak ada galat apa pun: hanya angka yang kurang,
 * dan biasanya baru ketahuan berbulan kemudian saat anggaran direkonsiliasi. Karena itu
 * kewajibannya ditegakkan di DUA lapis — validasi request dan service — dan cost centernya
 * ditelusuri sampai ke bs_journal_lines pada SELURUH jurnal yang lahir dari bon itu.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-bon-cost-center-budget.php';"
 */

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCashAdvance;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsJournalLine;
use App\Domain\BusinessSupport\Services\CashAdvanceService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Web\BusinessSupport\CashBook\CashAdvanceController;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

$user = User::all()->first(fn ($u) => (bool) $u->canModule('cashbook')) ?? User::first();
auth()->setUser($user);
$svc = app(CashAdvanceService::class);
$ctl = app(CashAdvanceController::class);

$cc1 = BsCostCenter::where('is_active', true)->orderBy('code')->first(['id', 'code', 'name']);
$cc2 = BsCostCenter::where('is_active', true)->where('id', '!=', $cc1?->id)->orderBy('code')->first(['id', 'code', 'name']);
$bank = BsAccount::where('is_postable', true)->where('code', 'like', '10101%')->orderBy('code')->first(['id', 'code']);
$beban = BsAccount::where('is_postable', true)->where('type', 'expense')->first(['id', 'code']);

echo "\n=== PRASYARAT ===\n";
$ok('kolom cost_center_id ada di bs_cash_advances', Schema::hasColumn('bs_cash_advances', 'cost_center_id'));
$ok('kolomnya NULLABLE (bon lama tidak dipaksa mengaku punya)',
    (DB::selectOne("SELECT IS_NULLABLE n FROM information_schema.columns
        WHERE table_schema=DATABASE() AND table_name='bs_cash_advances' AND column_name='cost_center_id'")->n ?? '') === 'YES');
$ok('ada dua cost center aktif', $cc1 && $cc2);
$ok('ada akun bank & beban', $bank && $beban);
if (! $cc1 || ! $cc2 || ! $bank || ! $beban) { echo "\nPrasyarat kurang.\n"; return; }

DB::beginTransaction();
try {

echo "\n=== 1. SERVICE MENOLAK BON TANPA COST CENTER ===\n";
// Lapis terakhir: pemanggilan service langsung (job, artisan, kode baru) tidak lewat validasi request.
$dasar = ['advance_date' => now()->toDateString(), 'holder' => 'UJI Pemegang', 'purpose' => 'UJI cost center', 'amount' => 1_000_000];
$kode = null;
try { $svc->issue($dasar, $user?->id); }
catch (BusinessRuleException $e) { $kode = $e->errorCode(); }
$ok('ditolak dengan ADV_NO_COST_CENTER', $kode === 'ADV_NO_COST_CENTER', (string) $kode);

$kode2 = null;
try { $svc->issue($dasar + ['cost_center_id' => ''], $user?->id); }
catch (BusinessRuleException $e) { $kode2 = $e->errorCode(); }
$ok('untai kosong juga ditolak', $kode2 === 'ADV_NO_COST_CENTER', (string) $kode2);

echo "\n=== 2. BON BERCOST CENTER TERSIMPAN ===\n";
$bon = $svc->issue($dasar + ['cost_center_id' => $cc1->id], $user?->id);
$ok('bonnya tersimpan', (bool) $bon->id);
$ok('cost centernya tersimpan', (int) $bon->cost_center_id === (int) $cc1->id, (string) $bon->cost_center_id);
$ok('relasi costCenter() terbaca', $bon->costCenter?->code === $cc1->code, (string) $bon->costCenter?->code);

echo "\n=== 3. VALIDASI REQUEST MEWAJIBKANNYA ===\n";
$kirim = function (array $isian) use ($ctl, $user, $svc) {
    $r = \Illuminate\Http\Request::create('/uji/advance', 'POST', $isian);
    $r->setUserResolver(fn () => $user);
    $r->setLaravelSession(app('session.store'));
    app('session.store')->flush();
    try {
        $ctl->store($r, $svc);

        return [];
    } catch (\Illuminate\Validation\ValidationException $e) {
        return $e->errors();
    }
};
$isianDasar = ['advance_date' => now()->toDateString(), 'holder' => 'UJI HTTP', 'purpose' => 'UJI', 'amount' => 500000, 'approval_mode' => 'offline'];
$e1 = $kirim($isianDasar);
$ok('tanpa cost_center_id ditolak validasi', isset($e1['cost_center_id']), json_encode(array_keys($e1)));
$ok('pesannya menyebut Budget Control',
    str_contains(implode(' ', $e1['cost_center_id'] ?? []), 'Budget Control'), json_encode($e1['cost_center_id'] ?? []));

// Cost center NONAKTIF tidak boleh diterima — ia tidak muncul di dropdown, jadi menerimanya
// berarti menyimpan dimensi yang tidak bisa dipilih ulang.
$cc2->update(['is_active' => false]);
$e2 = $kirim($isianDasar + ['cost_center_id' => $cc2->id]);
$ok('cost center NONAKTIF ditolak', isset($e2['cost_center_id']), json_encode(array_keys($e2)));
$cc2->update(['is_active' => true]);

$e3 = $kirim($isianDasar + ['cost_center_id' => $cc1->id]);
$ok('cost center aktif diterima', ! isset($e3['cost_center_id']), json_encode($e3));

echo "\n=== 4. COST CENTER TURUN KE JURNAL PEMBAYARAN ===\n";
$svc->pay($bon, ['paid_date' => now()->toDateString(), 'cash_account_id' => $bank->id, 'payment_method' => 'reguler'], $user?->id);
$bon->refresh();
$barisBayar = BsJournalLine::where('journal_id', $bon->journal_id)->get(['account_id', 'cost_center_id', 'debit', 'credit']);
$ok('jurnal pembayaran terbentuk', $barisBayar->count() === 2, (string) $barisBayar->count());
$ok('SELURUH barisnya bercost center bon',
    $barisBayar->every(fn ($l) => (int) $l->cost_center_id === (int) $cc1->id),
    $barisBayar->pluck('cost_center_id')->toJson());

echo "\n=== 5. COST CENTER TURUN KE JURNAL REALISASI ===\n";
// Baris beban memakai cost center PER BARIS (boleh berbeda — satu bon bisa dibelanjakan untuk
// beberapa cost center); baris lawannya memakai cost center bon.
$svc->realize($bon, [
    'realized_date' => now()->toDateString(), 'defer_settlement' => true,
    'lines' => [['expense_account_id' => $beban->id, 'cost_center_id' => $cc2->id,
        'description' => 'uji realisasi', 'amount' => 400000]],
], $user?->id);
$bon->refresh();
$barisReal = BsJournalLine::where('journal_id', $bon->realize_journal_id)->get(['account_id', 'cost_center_id', 'debit', 'credit']);
$ok('jurnal realisasi terbentuk', $barisReal->count() >= 2, (string) $barisReal->count());
$ok('baris beban memakai cost center barisnya',
    (int) ($barisReal->firstWhere('account_id', $beban->id)?->cost_center_id ?? 0) === (int) $cc2->id);
$ok('TIDAK ADA baris realisasi tanpa cost center',
    $barisReal->every(fn ($l) => $l->cost_center_id !== null),
    $barisReal->pluck('cost_center_id')->toJson());

echo "\n=== 6. COST CENTER TURUN KE JURNAL REFUND/REIMBURSE ===\n";
$sisa = round((float) $bon->amount - (float) $bon->realized_amount, 2);
$ok('ada sisa untuk di-refund', $sisa > 0, (string) $sisa);
$svc->refund($bon, now()->toDateString(), $user?->id, (int) $bank->id);
$bon->refresh();
$barisSettle = BsJournalLine::where('journal_id', $bon->settle_journal_id)->get(['account_id', 'cost_center_id']);
$ok('jurnal penyelesaian terbentuk', $barisSettle->count() === 2, (string) $barisSettle->count());
$ok('SELURUH barisnya bercost center bon',
    $barisSettle->every(fn ($l) => (int) $l->cost_center_id === (int) $cc1->id),
    $barisSettle->pluck('cost_center_id')->toJson());

echo "\n=== 7. TERBACA BUDGET CONTROL (per akun + cost center) ===\n";
/* Inilah tautannya yang sesungguhnya: BudgetRealizationService merekap bs_journal_lines per
   (tahun, akun, cost center). Kalau baris bon tak bercost center, ia tidak akan pernah masuk
   hitungan itu — jadi yang diperiksa di sini bukan sekadar "kolomnya terisi", melainkan
   barisnya benar-benar ikut terjaring saat direkap dengan cara yang sama. */
$rekap = BsJournalLine::whereIn('journal_id', array_filter([$bon->journal_id, $bon->realize_journal_id, $bon->settle_journal_id]))
    ->selectRaw('cost_center_id, COUNT(*) n')->groupBy('cost_center_id')->pluck('n', 'cost_center_id');
$ok('tidak ada satu pun baris bon tanpa cost center', ! $rekap->has(''), $rekap->toJson());
$ok('baris beban terjaring pada cost center realisasinya',
    BsJournalLine::where('journal_id', $bon->realize_journal_id)
        ->where('account_id', $beban->id)->where('cost_center_id', $cc2->id)->exists());
$ok('baris panjar terjaring pada cost center bon',
    BsJournalLine::where('journal_id', $bon->journal_id)->where('cost_center_id', $cc1->id)->count() === 2);

echo "\n=== 8. BON LAMA (cost center NULL) TIDAK IKUT RUSAK ===\n";
// Kolomnya nullable justru untuk ini: bon yang sudah ada sebelum aturan ini tetap bisa dibaca
// dan dibayar. Yang ditutup hanya jalur PEMBUATAN baru.
$lama = BsCashAdvance::create([
    'advance_number' => 'UJI-LAMA-'.substr((string) microtime(true), -6),
    'advance_date' => now()->toDateString(), 'holder' => 'UJI Lama', 'purpose' => 'bon lama',
    'amount' => 250000, 'status' => 'requested', 'approval_mode' => 'offline',
    'advance_account_id' => BsAccount::where('code', config('business_support.gl_accounts.advance_op'))->value('id'),
    'created_by' => $user?->id,
]);
$ok('bon lama tanpa cost center tetap bisa dibuat langsung di DB', $lama->cost_center_id === null);
$svc->pay($lama, ['paid_date' => now()->toDateString(), 'cash_account_id' => $bank->id, 'payment_method' => 'reguler'], $user?->id);
$ok('bon lama tetap bisa dibayar', $lama->refresh()->status === 'outstanding', (string) $lama->status);

echo "\n=== 9. LAYAR ===\n";
$rq = \Illuminate\Http\Request::create('/business-support/cash-advances/create', 'GET');
$rq->setUserResolver(fn () => $user);
$rq->headers->set('X-Inertia', 'true');
$rq->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
$props = json_decode((string) $ctl->create($rq)->toResponse($rq)->getContent(), true)['props'] ?? [];
$ok('daftar cost center dikirim ke layar', count($props['costCenters'] ?? []) > 0, (string) count($props['costCenters'] ?? []));
$ok('labelnya "kode — nama"',
    (bool) collect($props['costCenters'] ?? [])->first(fn ($c) => str_contains($c['label'], ' — ')));

$vue = file_get_contents(resource_path('js/Pages/BusinessSupport/CashBook/Advances/Form.vue')) ?: '';
$ok('form mengirim cost_center_id', str_contains($vue, "cost_center_id: ''"));
$ok('kolomnya bertanda bintang merah',
    (bool) preg_match('/Cost Center <span class="text-danger-500">\*<\/span>/', $vue));
$ok('tombol Simpan tertahan saat kosong', str_contains($vue, 'form.processing || ccKurang'));

} finally {
    DB::rollBack();
}

echo "\n=== HASIL ===\n";
echo "  lulus: $lulus · gagal: $gagal\n";
echo $gagal === 0 ? "  SEMUA LULUS\n" : "  ADA YANG GAGAL\n";
