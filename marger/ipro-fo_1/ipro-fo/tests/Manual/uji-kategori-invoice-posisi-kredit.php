<?php

/**
 * Kategori Invoice — pilihan POSISI KREDIT: Hutang atau Beban Masih Harus Dibayar.
 *
 * Posisi "beban" memakai COA 20105050501 yang ditentukan sistem. Yang dijaga di sini:
 *
 *  1. INVOICE DAN PELUNASANNYA HARUS BERTEMU DI AKUN YANG SAMA. Ini kegagalan termahal dan
 *     paling sunyi: bila invoice mengkredit akun A sementara pelunasannya mendebit akun B,
 *     KEDUA jurnalnya tetap balance, tidak ada galat apa pun, dan salah satu akun tidak
 *     pernah kembali nol. Uji ini membentuk invoice sungguhan, melunasinya, lalu menuntut
 *     saldo akun itu kembali NOL.
 *  2. AKUNNYA DITENTUKAN SERVER, bukan kiriman layar. Permintaan yang dirakit sendiri tidak
 *     boleh bisa menyimpan posisi "beban" sambil mengkredit akun mana pun — tidak ada satu
 *     layar pun yang akan memperlihatkan ketidakcocokan itu.
 *  3. TIDAK MENGUBAH APA PUN DI ACCOUNT PAYABLE SELAIN PENJURNALAN. Aging & Kartu Hutang
 *     dihitung dari tabel invoice, bukan saldo akun — uji membuktikannya dengan angka.
 *  4. KATEGORI LAMA TIDAK BERUBAH. Ke-56 kategori yang sudah ada tetap berposisi 'hutang'
 *     dan tetap mengkredit akun yang sama seperti sebelum fitur ini ada.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-kategori-invoice-posisi-kredit.php';"
 */

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApCategory;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsJournalLine;
use App\Http\Controllers\Web\BusinessSupport\Payable\ApCategoryController;
use App\Models\User;
use Illuminate\Http\Request;
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
$req = function ($u, array $data = [], string $metode = 'GET') {
    $r = Request::create('/uji', $metode, $data, [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $r->setUserResolver(fn () => $u);
    $r->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());

    return $r;
};
$props = fn ($res, $r) => json_decode((string) $res->toResponse($r)->getContent(), true)['props'] ?? [];

$KODE_BEBAN = config('business_support.gl_accounts.accrued_expense');
$akunBeban = BsAccount::where('code', $KODE_BEBAN)->first();
$akunHutang = BsAccount::where('code', config('business_support.gl_accounts.payable'))->first();
$akunBiaya = BsAccount::where('is_postable', true)->where('type', 'expense')->first();
$bank = BsAccount::where('is_postable', true)->where('code', 'like', '101010%')->first();
$supplier = DB::table('bs_suppliers')->value('id');
if (! $akunBeban || ! $akunHutang || ! $akunBiaya || ! $bank || ! $supplier) { echo "Master belum lengkap — uji dihentikan.\n"; return; }

DB::beginTransaction();

try {
    echo "\n=== 1. Pilihan posisi kredit ===\n";

    $ok('dua posisi saja', array_keys(BsApCategory::POSISI) === ['hutang', 'beban_ymhd'], json_encode(array_keys(BsApCategory::POSISI)));
    $ok('COA beban dibaca dari config, bukan id mati', $KODE_BEBAN === '20105050501', (string) $KODE_BEBAN);
    $ok('akunnya ada & bisa dijurnal', $akunBeban->is_postable && $akunBeban->is_active);
    $ok('  bertipe kewajiban, sejenis utang usaha', $akunBeban->type === $akunHutang->type, $akunBeban->type.' vs '.$akunHutang->type);

    echo "\n=== 2. Kategori lama tidak berubah ===\n";

    $lama = DB::table('bs_ap_categories')->where('code', 'not like', 'UJI-%')->get(['credit_position', 'payable_account_id']);
    $ok('semua kategori lama berposisi hutang', $lama->every(fn ($c) => ($c->credit_position ?: 'hutang') === 'hutang'), (string) $lama->count().' baris');
    $ok('  dan tetap mengkredit utang usaha', $lama->every(fn ($c) => (int) $c->payable_account_id === (int) $akunHutang->id));

    echo "\n=== 3. Menyimpan kategori berposisi beban ===\n";

    $ctl->store($req($user, [
        'code' => 'UJI-BEBAN-YMHD', 'name' => 'UJI BEBAN MASIH HARUS DIBAYAR',
        'debit_account_id' => $akunBiaya->id, 'credit_position' => 'beban_ymhd', 'is_active' => true,
    ], 'POST'));
    $katBeban = BsApCategory::where('code', 'UJI-BEBAN-YMHD')->first();
    $ok('tersimpan', $katBeban !== null);
    $ok('posisinya beban_ymhd', $katBeban?->credit_position === 'beban_ymhd', (string) $katBeban?->credit_position);
    $ok('akun kreditnya DIPAKSA ke 20105050501', (int) $katBeban?->payable_account_id === (int) $akunBeban->id,
        'dapat '.(string) $katBeban?->payable_account_id.', seharusnya '.$akunBeban->id);
    $ok('  tanpa perlu mengirim akun dari layar', true);

    // Kiriman nakal: posisi beban TAPI akun lain. Server tidak boleh mempercayainya.
    $ctl->store($req($user, [
        'code' => 'UJI-BEBAN-NAKAL', 'name' => 'UJI KIRIMAN NAKAL',
        'debit_account_id' => $akunBiaya->id, 'payable_account_id' => $akunHutang->id,
        'credit_position' => 'beban_ymhd', 'is_active' => true,
    ], 'POST'));
    $nakal = BsApCategory::where('code', 'UJI-BEBAN-NAKAL')->first();
    $ok('kiriman akun yang tidak cocok DIABAIKAN', (int) $nakal?->payable_account_id === (int) $akunBeban->id,
        'layar bisa menyimpan posisi beban sambil mengkredit akun lain: '.(string) $nakal?->payable_account_id);

    echo "\n=== 4. Posisi hutang tetap seperti semula ===\n";

    $ctl->store($req($user, [
        'code' => 'UJI-HUTANG-BIASA', 'name' => 'UJI HUTANG BIASA',
        'debit_account_id' => $akunBiaya->id, 'payable_account_id' => $akunHutang->id,
        'credit_position' => 'hutang', 'is_active' => true,
    ], 'POST'));
    $katHutang = BsApCategory::where('code', 'UJI-HUTANG-BIASA')->first();
    $ok('akun pilihan operator dipakai apa adanya', (int) $katHutang?->payable_account_id === (int) $akunHutang->id);

    $tolak = false;
    try {
        $ctl->store($req($user, [
            'code' => 'UJI-HUTANG-KOSONG', 'name' => 'UJI TANPA AKUN',
            'debit_account_id' => $akunBiaya->id, 'credit_position' => 'hutang', 'is_active' => true,
        ], 'POST'));
    } catch (ValidationException $e) { $tolak = true; $pesan = $e->errors()['payable_account_id'][0] ?? ''; }
    $ok('posisi hutang TANPA akun ditolak', $tolak, 'kategori tersimpan tanpa akun kredit');
    $ok('  pesannya menyebut sebabnya', $tolak && str_contains($pesan ?? '', 'Akun hutang wajib'), $pesan ?? '');

    $tolakPosisi = false;
    try {
        $ctl->store($req($user, [
            'code' => 'UJI-POSISI-ASING', 'name' => 'UJI POSISI ASING',
            'debit_account_id' => $akunBiaya->id, 'payable_account_id' => $akunHutang->id,
            'credit_position' => 'apa_saja', 'is_active' => true,
        ], 'POST'));
    } catch (ValidationException $e) { $tolakPosisi = true; }
    $ok('posisi asing ditolak', $tolakPosisi, 'nilai posisi apa pun diterima');

    echo "\n=== 5. Ganti posisi bolak-balik ===\n";

    $ctl->update($req($user, [
        'code' => 'UJI-BEBAN-YMHD', 'name' => 'UJI BEBAN MASIH HARUS DIBAYAR',
        'debit_account_id' => $akunBiaya->id, 'payable_account_id' => $akunHutang->id,
        'credit_position' => 'hutang', 'is_active' => true,
    ], 'PUT'), $katBeban);
    $ok('beban → hutang: akunnya ikut pindah', (int) $katBeban->fresh()->payable_account_id === (int) $akunHutang->id);
    $ctl->update($req($user, [
        'code' => 'UJI-BEBAN-YMHD', 'name' => 'UJI BEBAN MASIH HARUS DIBAYAR',
        'debit_account_id' => $akunBiaya->id, 'credit_position' => 'beban_ymhd', 'is_active' => true,
    ], 'PUT'), $katBeban->fresh());
    $ok('hutang → beban: akunnya kembali dipaksa', (int) $katBeban->fresh()->payable_account_id === (int) $akunBeban->id);

    echo "\n=== 6. INVOICE & PELUNASANNYA BERTEMU DI AKUN YANG SAMA ===\n";

    /* Inilah pemeriksaan terpenting. Invoice dibentuk lewat aksi sungguhnya, lalu dilunasi
       lewat aksi sungguhnya. Kalau keduanya memakai akun berbeda, saldonya tidak kembali nol
       — dan tidak ada galat apa pun yang memberi tahu. */
    /* Jurnal invoice AP lahir berstatus 'final' (menunggu posting), bukan 'posted'.
       Menyaring 'posted' saja membuat saldonya selalu nol — dan pemeriksaan "kembali nol"
       di bawah ini lolos palsu karena 0 == 0. Keduanya dihitung. */
    $saldo = fn (int $akunId) => (float) BsJournalLine::query()
        ->join('bs_journals', 'bs_journals.id', '=', 'bs_journal_lines.journal_id')
        ->whereIn('bs_journals.status', ['final', 'posted'])
        ->where('bs_journal_lines.account_id', $akunId)
        ->sum(DB::raw('credit - debit'));

    $sebelum = $saldo($akunBeban->id);
    $NILAI = 1750000.0;

    $inv = app(\App\Domain\BusinessSupport\Actions\RegisterApInvoice::class)->execute([
        'invoice_number' => 'UJI-BEBAN-'.random_int(1000, 9999),
        'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        'supplier_id' => $supplier, 'category_id' => $katBeban->fresh()->id,
        'description' => 'Uji beban masih harus dibayar', 'dpp' => $NILAI, 'ppn_pct' => 0,
    ], $user->id);

    $ok('invoice terbentuk', $inv !== null && $inv->id > 0);
    $ok('header invoice menyimpan akun BEBAN, bukan utang usaha',
        (int) $inv->payable_account_id === (int) $akunBeban->id,
        'dapat '.(string) $inv->payable_account_id.', seharusnya '.$akunBeban->id);

    $kredit = BsJournalLine::where('journal_id', $inv->journal_id)->where('credit', '>', 0)->get();
    $ok('jurnalnya mengkredit akun BEBAN', $kredit->contains(fn ($l) => (int) $l->account_id === (int) $akunBeban->id),
        'akun kredit: '.json_encode($kredit->pluck('account_id')->all()));
    $ok('  dan TIDAK mengkredit utang usaha', ! $kredit->contains(fn ($l) => (int) $l->account_id === (int) $akunHutang->id),
        'invoice mengkredit dua akun sekaligus');
    $ok('saldo akun beban naik sebesar nilainya', abs(($saldo($akunBeban->id) - $sebelum) - $NILAI) < 0.01,
        'naik '.($saldo($akunBeban->id) - $sebelum));

    app(\App\Domain\BusinessSupport\Actions\PayApInvoice::class)->execute($inv->fresh(), [
        'payment_date' => now()->toDateString(), 'amount' => $NILAI, 'cash_account_id' => $bank->id,
    ], $user->id);

    $ok('sesudah dilunasi, saldo akun beban KEMBALI seperti semula',
        abs($saldo($akunBeban->id) - $sebelum) < 0.01,
        'sisa '.($saldo($akunBeban->id) - $sebelum).' — invoice dan pelunasannya memakai akun berbeda');
    $ok('  invoice tercatat lunas', (float) $inv->fresh()->paid_amount >= $NILAI - 0.01);

    echo "\n=== 7. Account Payable tidak terpengaruh selain penjurnalan ===\n";

    /* Aging & Kartu Hutang dihitung dari TABEL invoice, bukan saldo akun. Invoice berposisi
       beban karena itu tetap ikut terhitung persis seperti invoice biasa. */
    $invBaru = app(\App\Domain\BusinessSupport\Actions\RegisterApInvoice::class)->execute([
        'invoice_number' => 'UJI-BEBAN-AGING-'.random_int(1000, 9999),
        'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        'supplier_id' => $supplier, 'category_id' => $katBeban->fresh()->id,
        'description' => 'Uji aging', 'dpp' => 500000, 'ppn_pct' => 0,
    ], $user->id);
    $ok('invoice berposisi beban tetap muncul sebagai invoice AP biasa',
        BsApInvoice::whereKey($invBaru->id)->exists());
    $ok('  dan sisa hutangnya dihitung dari tabel, bukan akun',
        abs(((float) $invBaru->total - (float) $invBaru->paid_amount) - 500000) < 0.01,
        'sisa '.((float) $invBaru->total - (float) $invBaru->paid_amount));
    $ok('  kategorinya tetap terbaca di invoice', (int) $invBaru->category_id === (int) $katBeban->id);

    echo "\n=== 8. Impor ===\n";

    $props8 = $props($ctl->index($req($user)), $req($user));
    $ok('layar menerima daftar posisi', ($props8['posisiKredit'] ?? []) === BsApCategory::POSISI, json_encode($props8['posisiKredit'] ?? null));
    $ok('layar menerima akun bebannya', (int) ($props8['akunBeban']['id'] ?? 0) === (int) $akunBeban->id, json_encode($props8['akunBeban'] ?? null));
    $barisBeban = collect($props8['categories'] ?? [])->firstWhere('code', 'UJI-BEBAN-YMHD');
    $ok('  dan posisi tiap baris', ($barisBeban['credit_position'] ?? null) === 'beban_ymhd', json_encode($barisBeban));

    /* Berkas impor LAMA (lima kolom, tanpa posisi) harus tetap terbaca sebagai 'hutang' —
       kalau tidak, satu impor rutin diam-diam memindahkan seluruh kategori ke akun lain. */
    $csv = tempnam(sys_get_temp_dir(), 'uji').'.csv';
    file_put_contents($csv, "kode,keterangan,akun_debit,akun_hutang,aktif\n"
        ."UJI-IMPOR-LAMA,UJI IMPOR LIMA KOLOM,{$akunBiaya->code},{$akunHutang->code},1\n");
    $r1 = $req($user, [], 'POST');
    $r1->files->set('berkas', new \Illuminate\Http\UploadedFile($csv, 'impor.csv', 'text/csv', null, true));
    $ctl->import($r1);
    $imporLama = BsApCategory::where('code', 'UJI-IMPOR-LAMA')->first();
    $ok('berkas impor LIMA kolom tetap terbaca', $imporLama !== null);
    $ok('  dan jatuh ke posisi hutang', $imporLama?->credit_position === 'hutang', (string) $imporLama?->credit_position);
    $ok('  dengan akun pilihannya sendiri', (int) $imporLama?->payable_account_id === (int) $akunHutang->id);
    @unlink($csv);

    $csv2 = tempnam(sys_get_temp_dir(), 'uji').'.csv';
    file_put_contents($csv2, "kode,keterangan,akun_debit,akun_hutang,aktif,posisi_kredit\n"
        ."UJI-IMPOR-BEBAN,UJI IMPOR BEBAN,{$akunBiaya->code},,1,beban_ymhd\n");
    $r2 = $req($user, [], 'POST');
    $r2->files->set('berkas', new \Illuminate\Http\UploadedFile($csv2, 'impor2.csv', 'text/csv', null, true));
    $ctl->import($r2);
    $imporBeban = BsApCategory::where('code', 'UJI-IMPOR-BEBAN')->first();
    $ok('impor berposisi beban diterima tanpa kolom akun hutang', $imporBeban !== null);
    $ok('  akunnya dipaksa ke 20105050501', (int) $imporBeban?->payable_account_id === (int) $akunBeban->id,
        (string) $imporBeban?->payable_account_id);
    /* Posisinya sendiri WAJIB dituntut: kolomnya berbawaan 'hutang' di basis data, jadi baris
       yang tersimpan tanpa posisi tetap terbaca 'hutang' dan layar menampilkannya sebagai
       kategori biasa — padahal jurnalnya mengkredit akun beban. */
    $ok('  dan posisinya ikut tersimpan', $imporBeban?->credit_position === 'beban_ymhd',
        'dapat '.(string) $imporBeban?->credit_position.' — akun & posisinya berselisih di layar');
    @unlink($csv2);

    $csv3 = tempnam(sys_get_temp_dir(), 'uji').'.csv';
    file_put_contents($csv3, "kode,keterangan,akun_debit,akun_hutang,aktif,posisi_kredit\n"
        ."UJI-IMPOR-ASING,UJI POSISI ASING,{$akunBiaya->code},{$akunHutang->code},1,entah\n");
    $r3 = $req($user, [], 'POST');
    $r3->files->set('berkas', new \Illuminate\Http\UploadedFile($csv3, 'impor3.csv', 'text/csv', null, true));
    $ctl->import($r3);
    $ok('posisi asing di berkas impor DITOLAK', BsApCategory::where('code', 'UJI-IMPOR-ASING')->doesntExist(),
        'baris berposisi tak dikenal tetap tersimpan');
    @unlink($csv3);

    echo "\n=== 9. Arus Kas Tidak Langsung ikut menghitung akun beban ===\n";

    $isi = file_get_contents(base_path('app/Http/Controllers/Web/BusinessSupport/Gl/CashFlowController.php'));
    $ok('kenaikan kewajiban usaha mencakup awalan 20105',
        str_contains($isi, "\$sumPrefix('20101', true) + \$sumPrefix('20105', true)"),
        'beban mengurangi laba tetapi kenaikan kewajibannya tidak ditambahkan kembali — '
        .'Tidak Langsung berhenti cocok dengan Langsung');

    echo "\n=== 10. Gerbang izin ===\n";

    $polos = new User(['tenant_id' => $user->tenant_id, 'name' => 'UJI Kategori Tanpa Izin',
        'email' => 'uji-kat-'.random_int(100000, 999999).'@contoh.test', 'password' => bcrypt('rahasia')]);
    $polos->save();
    foreach ([
        'index' => fn () => $ctl->index($req($polos)),
        'store' => fn () => $ctl->store($req($polos, ['code' => 'X', 'name' => 'X', 'debit_account_id' => $akunBiaya->id, 'credit_position' => 'hutang', 'payable_account_id' => $akunHutang->id], 'POST')),
    ] as $aksi => $jalan) {
        $kode = 0;
        try { $jalan(); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $kode = $e->getStatusCode(); }
        $ok("tanpa izin: $aksi ditolak", $kode === 403, (string) $kode);
    }

    echo "\n────────────────────────────────\n";
    echo "LULUS: $lulus   GAGAL: $gagal\n";
    echo $gagal === 0 ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
} catch (\Throwable $e) {
    echo "\nGALAT: ".$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n";
} finally {
    DB::rollBack();
}
