<?php

/**
 * Kategori Invoice dipakai di BEBERAPA modul, posisi kredit per modul (diatur user).
 *
 *  1. Satu kategori: Invoice Hutang → Hutang, Reimburse → Beban Masih Harus Dibayar.
 *  2. Invoice yang terbit mengkredit akun milik MODULNYA, dan posisinya dibekukan di invoice.
 *  3. Penyaring Query AP: invoice baru dibaca dari posisi bekunya; invoice lama (posisi kosong)
 *     tetap terbaca, dan jumlah(Semua) = jumlah(Hutang) + jumlah(BYMHD).
 *  4. Validasi: minimal satu modul; posisi Hutang wajib akun; modul yang tak dicentang lagi lepas.
 *  5. Impor: dua baris berkode sama = satu kategori di dua modul.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-kategori-banyak-pemakaian.php';"
 */

use App\Domain\BusinessSupport\Actions\RegisterApInvoice;
use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApCategory;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsJournalLine;
use App\Domain\BusinessSupport\Support\PosisiHutang;
use App\Http\Controllers\Web\BusinessSupport\Payable\ApCategoryController;
use App\Models\User;
use App\Support\Xlsx;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

$user = User::all()->first(fn ($u) => $u->can('businesssupport.setup.manage')) ?? User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
auth()->setUser($user);
app()->instance('tenant.id', (int) $user->tenant_id);

$ctl = new ApCategoryController;
$req = function (array $data = [], string $metode = 'POST', array $berkas = []) use ($user) {
    $r = Request::create('/uji', $metode, $data, [], $berkas);
    app()->instance('request', $r);
    $r->setUserResolver(fn () => $user);

    return $r;
};

$akunHutang = BsAccount::where('code', config('business_support.gl_accounts.payable'))->first();
$akunBiaya = BsAccount::where('is_postable', true)->where('type', 'expense')->first();
$supplier = DB::table('bs_suppliers')->value('id');
if (! $akunHutang || ! $akunBiaya || ! $supplier) { echo "Master belum lengkap — uji dihentikan.\n"; return; }

DB::beginTransaction();

try {
    // COA lokal bisa belum punya akun BYMHD; dibuat di dalam transaksi uji (ikut di-rollback).
    $akunBeban = BsAccount::where('code', config('business_support.gl_accounts.accrued_expense'))->first();
    if (! $akunBeban) {
        $akunBeban = $akunHutang->replicate();
        $akunBeban->code = (string) config('business_support.gl_accounts.accrued_expense');
        $akunBeban->name = 'BEBAN MASIH HARUS DIBAYAR (UJI)';
        $akunBeban->save();
        echo "  (akun BYMHD dibuat sementara untuk uji)\n";
    }

    echo "\n=== 1. Satu kategori, dua modul, posisi berbeda ===\n";

    $ctl->store($req([
        'code' => 'UJI-DUA-MODUL', 'name' => 'UJI DUA MODUL', 'debit_account_id' => $akunBiaya->id, 'is_active' => true,
        'pemakaian' => [
            ['jenis' => 'invoice', 'credit_position' => 'hutang', 'payable_account_id' => $akunHutang->id],
            // Kiriman nakal: posisi beban dengan akun lain — server wajib memakai akun beban.
            ['jenis' => 'reimburse', 'credit_position' => 'beban_ymhd', 'payable_account_id' => $akunHutang->id],
        ],
    ]));
    $kat = BsApCategory::where('code', 'UJI-DUA-MODUL')->first();
    $ok('tersimpan', $kat !== null);
    $ok('dua pemakaian', $kat?->usages()->count() === 2, (string) $kat?->usages()->count());
    $ok('Invoice Hutang → Hutang, akun utang usaha', $kat?->pemakaian('invoice')?->credit_position === 'hutang'
        && (int) $kat->pemakaian('invoice')->payable_account_id === (int) $akunHutang->id);
    $ok('Reimburse → BYMHD, akun DIPAKSA ke akun beban', $kat?->pemakaian('reimburse')?->credit_position === 'beban_ymhd'
        && (int) $kat->pemakaian('reimburse')->payable_account_id === (int) $akunBeban->id,
        (string) $kat?->pemakaian('reimburse')?->payable_account_id);
    $ok('tidak dipakai di Deposit', $kat?->pemakaian('deposit') === null);
    $ok('muncul di daftar kategori Invoice Hutang', BsApCategory::dipakaiDi('invoice')->where('id', $kat->id)->exists());
    $ok('muncul di daftar kategori Reimburse', BsApCategory::dipakaiDi('reimburse')->where('id', $kat->id)->exists());
    $ok('TIDAK muncul di daftar kategori Deposit', ! BsApCategory::dipakaiDi('deposit')->where('id', $kat->id)->exists());
    $ok('kolom lama mengikuti Invoice Hutang', $kat->jenis === 'invoice' && $kat->credit_position === 'hutang');

    echo "\n=== 2. Invoice mengkredit akun milik modulnya ===\n";

    $daftar = fn (string $jenis) => app(RegisterApInvoice::class)->execute([
        'jenis' => $jenis, 'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        'supplier_id' => $supplier, 'category_id' => $kat->id,
        'description' => 'Uji '.$jenis, 'dpp' => 1000000, 'ppn_pct' => 0,
    ], $user->id);
    $kreditJurnal = fn (BsApInvoice $i) => BsJournalLine::where('journal_id', $i->journal_id)->where('credit', '>', 0)->pluck('account_id')->map(fn ($x) => (int) $x)->all();

    $invHutang = $daftar('invoice');
    $ok('Invoice Hutang: akun utang usaha', (int) $invHutang->payable_account_id === (int) $akunHutang->id);
    $ok('  posisi beku = hutang', $invHutang->credit_position === 'hutang', (string) $invHutang->credit_position);
    $ok('  jurnal mengkredit utang usaha', in_array((int) $akunHutang->id, $kreditJurnal($invHutang), true), json_encode($kreditJurnal($invHutang)));

    $invRmb = $daftar('reimburse');
    $ok('Reimburse: akun beban', (int) $invRmb->payable_account_id === (int) $akunBeban->id);
    $ok('  posisi beku = beban_ymhd', $invRmb->credit_position === 'beban_ymhd', (string) $invRmb->credit_position);
    $ok('  jurnal mengkredit akun beban, bukan utang usaha',
        in_array((int) $akunBeban->id, $kreditJurnal($invRmb), true) && ! in_array((int) $akunHutang->id, $kreditJurnal($invRmb), true),
        json_encode($kreditJurnal($invRmb)));

    echo "\n=== 3. Penyaring Query AP ===\n";

    $ids = [$invHutang->id, $invRmb->id];
    $saring = fn (string $p) => PosisiHutang::saring(BsApInvoice::whereIn('id', $ids), $p)->pluck('id')->all();
    $ok('Hutang Usaha hanya invoice hutang', $saring(PosisiHutang::HUTANG) === [$invHutang->id], json_encode($saring(PosisiHutang::HUTANG)));
    $ok('BYMHD hanya reimburse', $saring(PosisiHutang::BEBAN) === [$invRmb->id], json_encode($saring(PosisiHutang::BEBAN)));

    // Invoice LAMA (posisi kosong) harus tetap jatuh di salah satu pilihan.
    $semua = PosisiHutang::saring(BsApInvoice::query(), PosisiHutang::SEMUA)->count();
    $h = PosisiHutang::saring(BsApInvoice::query(), PosisiHutang::HUTANG)->count();
    $b = PosisiHutang::saring(BsApInvoice::query(), PosisiHutang::BEBAN)->count();
    $ok('Semua = Hutang + BYMHD (tidak ada invoice yang hilang)', $semua === $h + $b, "$semua vs $h + $b");
    $lama = BsApInvoice::whereNull('credit_position')->count();
    $ok('  termasuk invoice lama tanpa posisi beku', $lama === 0 || PosisiHutang::saring(BsApInvoice::whereNull('credit_position'), PosisiHutang::HUTANG)->count()
        + PosisiHutang::saring(BsApInvoice::whereNull('credit_position'), PosisiHutang::BEBAN)->count() === $lama, (string) $lama);

    echo "\n=== 4. Ubah setup tidak memindahkan invoice yang sudah terbit ===\n";

    $ctl->update($req([
        'code' => 'UJI-DUA-MODUL', 'name' => 'UJI DUA MODUL', 'debit_account_id' => $akunBiaya->id, 'is_active' => true,
        'pemakaian' => [['jenis' => 'invoice', 'credit_position' => 'beban_ymhd']],
    ], 'PUT'), $kat);
    $kat->refresh();
    $ok('Reimburse dilepas (tidak dicentang lagi)', $kat->pemakaian('reimburse') === null);
    $ok('Invoice Hutang kini BYMHD', $kat->pemakaian('invoice')?->credit_position === 'beban_ymhd');
    $ok('invoice hutang lama tetap di Hutang Usaha', $saring(PosisiHutang::HUTANG) === [$invHutang->id], json_encode($saring(PosisiHutang::HUTANG)));

    echo "\n=== 5. Validasi ===\n";

    $galat = function (array $data) use ($ctl, $req) {
        try { $ctl->store($req($data)); } catch (ValidationException $e) { return $e->errors(); }

        return [];
    };
    $e1 = $galat(['code' => 'UJI-TANPA-MODUL', 'name' => 'X', 'debit_account_id' => $akunBiaya->id, 'pemakaian' => []]);
    $ok('tanpa modul ditolak', isset($e1['pemakaian']), json_encode($e1));
    $e2 = $galat(['code' => 'UJI-TANPA-AKUN', 'name' => 'X', 'debit_account_id' => $akunBiaya->id,
        'pemakaian' => [['jenis' => 'reimburse', 'credit_position' => 'hutang']]]);
    $ok('posisi Hutang tanpa akun ditolak', isset($e2['pemakaian.0.payable_account_id']), json_encode($e2));
    $e3 = $galat(['code' => 'UJI-DOBEL', 'name' => 'X', 'debit_account_id' => $akunBiaya->id, 'pemakaian' => [
        ['jenis' => 'invoice', 'credit_position' => 'beban_ymhd'], ['jenis' => 'invoice', 'credit_position' => 'beban_ymhd']]]);
    $ok('modul dobel ditolak', $e3 !== [], json_encode($e3));

    echo "\n=== 6. Impor: dua baris berkode sama ===\n";

    $berkas = tempnam(sys_get_temp_dir(), 'kat').'.xlsx';
    file_put_contents($berkas, Xlsx::tulis(
        ['kode', 'keterangan', 'akun_debit', 'akun_hutang', 'aktif', 'posisi_kredit', 'dipakai_di'],
        [
            ['UJI-IMPOR-DUA', 'UJI IMPOR DUA', $akunBiaya->code, $akunHutang->code, 1, 'hutang', 'invoice'],
            ['UJI-IMPOR-DUA', 'UJI IMPOR DUA', $akunBiaya->code, '', 1, 'beban_ymhd', 'Reimburse Biaya Karyawan'],
            ['UJI-IMPOR-LAMA', 'UJI IMPOR LAMA', $akunBiaya->code, $akunHutang->code, 1],
        ],
        'Kategori Invoice',
    ));
    $up = new UploadedFile($berkas, 'kategori.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    $res = $ctl->import($req([], 'POST', ['berkas' => $up]));
    $pesan = (string) session('success').(string) session('error');
    $dua = BsApCategory::where('code', 'UJI-IMPOR-DUA')->first();
    $ok('impor sukses', $dua !== null, $pesan);
    $ok('  satu kategori, dua modul', $dua?->usages()->count() === 2, (string) $dua?->usages()->count());
    $ok('  Reimburse (ditulis dengan namanya) → BYMHD', $dua?->pemakaian('reimburse')?->credit_position === 'beban_ymhd'
        && (int) $dua->pemakaian('reimburse')->payable_account_id === (int) $akunBeban->id);
    $lamaImpor = BsApCategory::where('code', 'UJI-IMPOR-LAMA')->first();
    $ok('berkas berkolom lima tetap jatuh ke Invoice Hutang · Hutang', $lamaImpor?->pemakaian('invoice')?->credit_position === 'hutang');
} finally {
    DB::rollBack();
}

echo "\nHasil: {$lulus} lulus, {$gagal} gagal.\n";
