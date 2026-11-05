<?php
// Langkah Struktur Registrasi Invoice Hutang: akun wajib-anggaran tanpa anggaran dianggap anggaran 0 →
// wajib Alokasi / Koreksi; Master Budget nol dibuat saat diajukan. Lampiran Alokasi punya ringkasan
// Budget awal · ±Nilai alokasi · Setelah alokasi.
// Jalankan: php artisan tinker tests/Manual/uji-budget-nol.php </dev/null
use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Services\BudgetGuardService;
use App\Domain\BusinessSupport\Services\BudgetNol;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

$gagal = 0; $lulus = 0;
$cek = function (string $nama, bool $ok, string $info = '') use (&$gagal, &$lulus) { $ok ? $lulus++ : $gagal++; echo ($ok ? '  ✓ ' : '  ✗ GAGAL ').$nama.($info !== '' && ! $ok ? " — {$info}" : '')."\n"; };
DB::beginTransaction();
try {
    auth()->setUser(\App\Models\User::findOrFail(1));
    $guard = app(BudgetGuardService::class);
    $tgl = now()->toDateString();
    $tahun = now()->year;
    $bebas = array_map('strval', (array) config('business_support.budget.coa_tanpa_anggaran', []));
    $cc = BsCostCenter::query()->orderBy('id')->firstOrFail();
    $akun = BsAccount::query()->where('is_postable', 1)->where('is_active', 1)->where('cost_center_optional', 0)
        ->whereNotIn('code', $bebas ?: ['-'])->where('type', 'expense')
        ->whereNotExists(fn ($q) => $q->from('bs_budgets')->whereColumn('bs_budgets.account_id', 'bs_accounts.id')->where('year', $tahun)
            ->where(fn ($w) => $w->where('cost_center_id', $cc->id)->orWhereNull('cost_center_id')))
        ->firstOrFail();
    $kas = BsAccount::query()->where('cost_center_optional', 1)
        ->whereNotExists(fn ($q) => $q->from('bs_budgets')->whereColumn('bs_budgets.account_id', 'bs_accounts.id')->where('year', $tahun))->firstOrFail();

    $cek('akun biaya tanpa anggaran = wajib anggaran', $guard->wajibAnggaran($akun->id));
    $cek('akun cost_center_optional (mis. kas/hutang) tidak wajib', ! $guard->wajibAnggaran($kas->id));
    $cek('sisaBulan asli = null (belum ada anggaran)', $guard->sisaBulan($akun->id, $cc->id, $tgl) === null);

    // Endpoint sisa-budget: anggaran 0, budget_id negatif.
    $res = app(\App\Http\Controllers\Web\BusinessSupport\Payable\ApInvoiceController::class)
        ->sisaBudget(tap(\Illuminate\Http\Request::create('/x', 'GET', ['date' => $tgl, 'pairs' => [$akun->id.'|'.$cc->id, $kas->id.'|']]),
            fn ($r) => $r->setUserResolver(fn () => auth()->user())), $guard);
    $sisa = $res->getData(true)['sisa'];
    $s = $sisa[$akun->id.'|'.$cc->id] ?? null;
    $cek('sisa-budget: dianggap anggaran 0 (baru, id negatif)', $s && $s['baru'] === true && (float) $s['anggaran'] === 0.0 && $s['budget_id'] === BudgetNol::kunci($akun->id, $cc->id));
    $cek('sisa-budget: akun tidak wajib tetap null', array_key_exists($kas->id.'|', $sisa) && $sisa[$kas->id.'|'] === null, json_encode(array_keys($sisa)));
    $cek('kunci bisa diurai balik', BudgetNol::urai($s['budget_id']) === [$akun->id, $cc->id]);

    // Diajukan → Master Budget nol dibuat (approved), id diganti.
    $nol = app(BudgetNol::class);
    $data = $nol->siapkan(['invoice_date' => $tgl, 'koreksi' => [['budget_id' => $s['budget_id'], 'account_id' => $akun->id, 'cost_center_id' => $cc->id, 'nilai' => 1000]]], 1);
    $b = BsBudget::find($data['koreksi'][0]['budget_id']);
    $cek('Master Budget nol dibuat (approved, 0)', $b && $b->status === 'approved' && (float) $b->amount === 0.0 && (int) $b->account_id === $akun->id && (int) $b->cost_center_id === $cc->id);
    $data2 = $nol->siapkan(['invoice_date' => $tgl, 'alokasi' => [['budget_id' => $s['budget_id'], 'dari_budget_id' => 1, 'nilai' => 5]]], 1);
    $cek('diajukan lagi → budget yang sama dipakai', (int) $data2['alokasi'][0]['budget_id'] === $b->id && BsBudget::where('account_id', $akun->id)->where('cost_center_id', $cc->id)->where('year', $tahun)->count() === 1);
    $cek('id positif tidak diubah', $nol->siapkan(['koreksi' => [['budget_id' => 7]]])['koreksi'][0]['budget_id'] === 7);

    // Penjaga budget: tanpa koreksi → ditolak (anggaran 0); dengan koreksi cukup → lolos.
    $baris = [['account_id' => $akun->id, 'cost_center_id' => $cc->id, 'debit' => 1000, 'credit' => 0]];
    try { $guard->assertWithinBudget($baris, null, $tgl); $cek('tanpa alokasi/koreksi → ditolak', false); }
    catch (BusinessRuleException $e) { $cek('tanpa alokasi/koreksi → ditolak', true); }
    try { $guard->assertWithinBudget($baris, null, $tgl, null, [$b->id => 1000 + (float) $s['terpakai']]); $cek('koreksi menutup kekurangan → lolos', true); }
    catch (BusinessRuleException $e) { $cek('koreksi menutup kekurangan → lolos', false, $e->getMessage()); }

    // Budget yang masih menunggu Approval Budget → ditolak jelas.
    $b->update(['status' => 'submitted']);
    try { $nol->siapkan(['koreksi' => [['budget_id' => $s['budget_id']]]]); $cek('budget menunggu approval → ditolak', false); }
    catch (BusinessRuleException $e) { $cek('budget menunggu approval → ditolak', $e->errorCode() === 'BUDGET_BELUM_APPROVED'); }

    // Lampiran Alokasi: ringkasan Dari (−) / Ke (+).
    $a = \App\Domain\BusinessSupport\Models\BsBudgetAllocation::query()->whereNotNull('to_budget_id')->latest('id')->first();
    if ($a) {
        $html = view('pdf.lampiran-perubahan-budget', app(\App\Domain\BusinessSupport\Services\LampiranPerubahanBudget::class)->alokasi($a))->render();
        $cek('lampiran alokasi: tabel Ringkasan Alokasi', str_contains($html, 'Ringkasan Alokasi') && str_contains($html, 'Setelah alokasi'));
        $cek('lampiran alokasi: Dari bertanda −, Ke bertanda +, ada garis pembatas', str_contains($html, '<b>Dari</b>') && str_contains($html, '<b>Ke</b>') && str_contains($html, 'lpb-batas') && preg_match('/lpb-min"><b>-/', $html) === 1 && preg_match('/lpb-plus"><b>\+/', $html) === 1);
        $k = \App\Domain\BusinessSupport\Models\BsBudgetCorrection::query()->latest('id')->first();
        $cek('lampiran koreksi: tanpa ringkasan alokasi', ! $k || ! str_contains(view('pdf.lampiran-perubahan-budget', app(\App\Domain\BusinessSupport\Services\LampiranPerubahanBudget::class)->koreksi($k))->render(), 'Ringkasan Alokasi'));
    }
} finally {
    DB::rollBack();
}
echo $gagal ? "ADA YANG GAGAL ({$gagal})\n" : "SEMUA LULUS ({$lulus})\n";
exit(0);
