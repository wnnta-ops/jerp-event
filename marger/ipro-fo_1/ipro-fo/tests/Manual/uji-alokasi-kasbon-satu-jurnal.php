<?php

/**
 * ALOKASI KASBON — satu jurnal untuk satu TANGGAL & satu BANK.
 *
 * Alokasi yang tanggal dan bank pengeluarannya sama kini menumpang SATU jurnal: beberapa baris
 * DEBIT Uang Muka Operasional (masing-masing berketerangan sendiri) dan SATU baris KREDIT bank
 * sebesar totalnya. Tanggal atau bank yang berbeda tetap melahirkan jurnal sendiri.
 *
 * APA YANG PERLU DIJAGA DAN KENAPA.
 *   1. Dulu tiap alokasi melahirkan jurnalnya sendiri, sehingga tiga pengeluaran dari bank yang
 *      sama pada hari yang sama tampil sebagai tiga jurnal berbeda di Detail Transaksi Jurnal.
 *   2. BARIS LAMA TIDAK BOLEH DIBONGKAR saat alokasi baru menempel. JournalService::update()
 *      menulis ulang seluruh baris — baris lama terhapus permanen, dan bs_ledger_cf_tags serta
 *      bs_ledger_cluster_splits ber-FK cascade ke journal_line_id. Memakainya berarti setiap
 *      penambahan alokasi menghapus klasifikasi arus kas alokasi sebelumnya tanpa satu galat pun.
 *      Karena itu uji ini menegaskan id baris lama TETAP.
 *   3. hapus() dulu menghapus SELURUH jurnal lewat journal_id. Pada jurnal yang ditumpangi
 *      beberapa alokasi, itu akan melenyapkan pengeluaran alokasi yang masih ada — nominalnya
 *      tetap terhitung totalAlokasi() sementara barisnya hilang dari buku besar, dan realisasi
 *      lalu berhitung terhadap panjar yang di buku besar tidak sebesar itu.
 *   4. Jurnalnya harus TETAP BALANCE pada setiap langkah.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-alokasi-kasbon-satu-jurnal.php';"
 */

use App\Domain\BusinessSupport\Models\BsCashAdvance;
use App\Domain\BusinessSupport\Models\BsCashAdvanceAllocation;
use App\Domain\BusinessSupport\Models\BsCreditCard;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Services\CashAdvanceAllocationService;
use App\Domain\BusinessSupport\Services\CashAdvanceService;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};
$rp = fn ($v) => (int) round((float) $v);
$tolak = function (callable $fn): ?string {
    try { $fn(); } catch (BusinessRuleException $e) { return $e->errorCode(); }

    return null;
};

$user = User::all()->first(fn ($u) => $u->canModule('cashbook')) ?? User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
auth()->setUser($user);

$svc = app(CashAdvanceService::class);
$alokSvc = app(CashAdvanceAllocationService::class);

$akunUmOp = DB::table('bs_accounts')->where('code', config('business_support.gl_accounts.advance_op'))->value('id');
if (! $akunUmOp) { echo "Akun Uang Muka Operasional tidak ada — uji dihentikan.\n"; return; }

$bank = DB::table('bs_accounts')->where('is_postable', 1)->where('code', 'like', '101010%')
    ->whereNotIn('id', [$akunUmOp])->orderBy('code')->limit(2)->pluck('id')->all();
if (count($bank) < 2) { echo "Rekening bank tidak cukup — uji dihentikan.\n"; return; }
[$BANK_A, $BANK_B] = $bank;

$cc = DB::table('bs_cost_centers')->where('is_active', 1)->value('id');
if (! $cc) { echo "Cost center tidak ada — uji dihentikan.\n"; return; }

$TAHUN = 2035;
$BULAN = 4;
$TGL1  = sprintf('%04d-%02d-10', $TAHUN, $BULAN);
$TGL2  = sprintf('%04d-%02d-11', $TAHUN, $BULAN);

DB::beginTransaction();

try {
    $js = app(\App\Domain\BusinessSupport\Services\JournalService::class);
    $js->ensurePeriod($TAHUN, $BULAN);

    $kartu = BsCreditCard::create([
        'account_id' => $BANK_B, 'card_number' => 'UJI-GAB-1', 'name' => 'KARTU UJI GABUNG',
        'is_active' => true, 'balance' => 0, 'expired_date' => sprintf('%04d-12-31', $TAHUN),
        'created_by' => $user->id,
    ]);

    $bon = $svc->issue([
        'advance_date'   => $TGL1,
        'holder'         => 'UJI Dewi Anggraini',
        'purpose'        => 'Uji gabung jurnal alokasi',
        'amount'         => 10000000,
        'cost_center_id' => $cc,
        'approval_mode'  => 'offline',
    ], $user->id);
    $svc->pay($bon, [
        'paid_date' => $TGL1, 'payment_method' => 'debit_va',
        'va_expired_date' => sprintf('%04d-12-31', $TAHUN), 'credit_card_id' => $kartu->id,
    ], $user->id);

    $alok = fn (string $tgl, int $bankId, string $ket, float $nilai) => $alokSvc->tambah($bon, [
        'alloc_date' => $tgl, 'description' => $ket, 'amount' => $nilai, 'cash_account_id' => $bankId,
    ], $user->id);

    $jurnalDari = fn (BsCashAdvanceAllocation $a) => BsJournal::whereKey($a->journal_id)->first();

    echo "\n=== 1. TANGGAL & BANK SAMA → SATU JURNAL ===\n";

    $a1 = $alok($TGL1, $BANK_A, 'JHVJVKNB', 3000000);
    $j1 = $jurnalDari($a1);
    $ok('alokasi pertama membentuk jurnal', $j1 !== null);
    $idBarisAwal = $j1->lines()->orderBy('id')->pluck('id')->all();
    $nomorAwal = $j1->journal_number;

    $a2 = $alok($TGL1, $BANK_A, 'NHJKBKJVBNK', 2000000);
    $a3 = $alok($TGL1, $BANK_A, 'GMVKVKN', 1000000);

    $ok('ketiganya menunjuk jurnal yang SAMA',
        $a1->journal_id === $a2->journal_id && $a2->journal_id === $a3->journal_id,
        json_encode([$a1->journal_id, $a2->journal_id, $a3->journal_id]));

    $j1->refresh();
    $ok('nomor jurnalnya tidak berubah', $j1->journal_number === $nomorAwal, $j1->journal_number.' vs '.$nomorAwal);

    $debit = $j1->lines()->where('debit', '>', 0)->orderBy('id')->get();
    $kredit = $j1->lines()->where('credit', '>', 0)->get();

    $ok('ada TIGA baris debit', $debit->count() === 3, (string) $debit->count());
    $ok('hanya SATU baris kredit', $kredit->count() === 1, (string) $kredit->count());
    $ok('baris kredit = bank yang dipilih', (int) $kredit->first()->account_id === $BANK_A, (string) $kredit->first()->account_id);
    $ok('nilai kredit = total ketiganya (6.000.000)', $rp($kredit->first()->credit) === 6000000, (string) $kredit->first()->credit);
    $ok('seluruh baris debit memakai akun Uang Muka Operasional',
        $debit->every(fn ($l) => (int) $l->account_id === (int) $bon->advance_account_id), json_encode($debit->pluck('account_id')));

    /* Inilah "beberapa keterangan pada posisi debit": tiap alokasi tetap bisa dikenali sendiri. */
    $ketDebit = $debit->pluck('description')->implode(' | ');
    foreach (['JHVJVKNB', 'NHJKBKJVBNK', 'GMVKVKN'] as $ket) {
        $ok("keterangan baris debit menyebut $ket", str_contains($ketDebit, $ket), $ketDebit);
    }

    $ok('jurnalnya balance', $rp($j1->lines()->sum('debit')) === $rp($j1->lines()->sum('credit')),
        json_encode([$j1->lines()->sum('debit'), $j1->lines()->sum('credit')]));
    $ok('total di kepala jurnal ikut diperbarui',
        $rp($j1->total_debit) === 6000000 && $rp($j1->total_credit) === 6000000,
        json_encode([$j1->total_debit, $j1->total_credit]));

    /* Baris lama HARUS tetap hidup: klasifikasi arus kas (bs_ledger_cf_tags) menggantung pada
       id baris, dan menulis ulang jurnal akan menghapusnya permanen lewat cascade. */
    $idSekarang = $j1->lines()->orderBy('id')->pluck('id')->all();
    $ok('id baris lama TIDAK berubah — klasifikasi arus kasnya tidak ikut terhapus',
        count(array_intersect($idBarisAwal, $idSekarang)) === count($idBarisAwal),
        json_encode([$idBarisAwal, $idSekarang]));

    echo "\n=== 2. TANGGAL BERBEDA → JURNAL TERPISAH ===\n";

    $a4 = $alok($TGL2, $BANK_A, 'Hari berikutnya', 500000);
    $ok('tanggal berbeda melahirkan jurnal sendiri', $a4->journal_id !== $a1->journal_id,
        json_encode([$a4->journal_id, $a1->journal_id]));
    $j4 = $jurnalDari($a4);
    $ok('jurnalnya dua baris saja', $j4->lines()->count() === 2, (string) $j4->lines()->count());

    echo "\n=== 3. BANK BERBEDA → JURNAL TERPISAH ===\n";

    $a5 = $alok($TGL1, $BANK_B, 'Bank lain', 700000);
    $ok('bank berbeda melahirkan jurnal sendiri walau tanggalnya sama',
        $a5->journal_id !== $a1->journal_id, json_encode([$a5->journal_id, $a1->journal_id]));
    $j5 = $jurnalDari($a5);
    $ok('baris kreditnya bank yang itu', (int) $j5->lines()->where('credit', '>', 0)->first()->account_id === $BANK_B);

    /* Bank yang sama pada tanggal yang sama menempel LAGI — bukan hanya alokasi kedua. */
    $a6 = $alok($TGL1, $BANK_B, 'Bank lain lagi', 300000);
    $ok('alokasi berikutnya di bank itu ikut menumpang jurnal yang sama',
        $a6->journal_id === $a5->journal_id, json_encode([$a6->journal_id, $a5->journal_id]));

    echo "\n=== 4. TOTAL ALOKASI & JUMLAH JURNAL ===\n";

    $ok('total alokasi = 7.500.000', $rp($alokSvc->totalAlokasi($bon)) === 7500000, (string) $alokSvc->totalAlokasi($bon));
    $jurnalUnik = BsCashAdvanceAllocation::where('cash_advance_id', $bon->id)->distinct()->pluck('journal_id');
    $ok('enam alokasi hanya membentuk TIGA jurnal', $jurnalUnik->count() === 3, json_encode($jurnalUnik->all()));

    echo "\n=== 5. HAPUS SATU ALOKASI DARI JURNAL GABUNGAN ===\n";

    /* Yang dulu tidak ada ujinya sama sekali — dan justru di sinilah penggabungan paling mudah
       merusak: menghapus jurnalnya akan ikut melenyapkan alokasi saudaranya. */
    $idJ1 = $j1->id;
    $alokSvc->hapus($a2->refresh(), $user->id);

    $j1 = BsJournal::whereKey($idJ1)->first();
    $ok('jurnalnya TIDAK ikut terhapus', $j1 !== null);
    $ok('a1 & a3 masih menunjuk jurnal itu',
        BsCashAdvanceAllocation::whereKey($a1->id)->value('journal_id') === $idJ1
        && BsCashAdvanceAllocation::whereKey($a3->id)->value('journal_id') === $idJ1);
    $ok('tinggal DUA baris debit', $j1->lines()->where('debit', '>', 0)->count() === 2,
        (string) $j1->lines()->where('debit', '>', 0)->count());
    $ok('baris NHJKBKJVBNK yang hilang',
        ! str_contains($j1->lines()->pluck('description')->implode(' | '), 'NHJKBKJVBNK'),
        $j1->lines()->pluck('description')->implode(' | '));
    $ok('kredit menyusut menjadi 4.000.000',
        $rp($j1->lines()->where('credit', '>', 0)->first()->credit) === 4000000,
        (string) $j1->lines()->where('credit', '>', 0)->first()->credit);
    $ok('jurnalnya tetap balance',
        $rp($j1->lines()->sum('debit')) === $rp($j1->lines()->sum('credit')),
        json_encode([$j1->lines()->sum('debit'), $j1->lines()->sum('credit')]));

    /* Total di KEPALA jurnal dibaca rekap tanpa menjumlah barisnya lagi; kepala yang tertinggal
       membuat rekap berbeda dari buku besar tanpa satu pun galat. */
    $ok('total di kepala jurnal ikut turun', $rp($j1->total_debit) === 4000000 && $rp($j1->total_credit) === 4000000,
        json_encode([$j1->total_debit, $j1->total_credit]));

    $ok('total alokasi ikut turun menjadi 5.500.000',
        $rp($alokSvc->totalAlokasi($bon)) === 5500000, (string) $alokSvc->totalAlokasi($bon));

    echo "\n=== 5b. KETERANGAN KEMBAR, NOMINAL BERBEDA — yang dicabut harus yang benar ===\n";

    /* Baris debit dikenali dari keterangan DAN nominalnya. Bila nominalnya diabaikan, dua alokasi
       berketerangan sama akan membuat baris yang salah tercabut — jurnalnya tetap balance dan
       totalnya tetap benar, jadi tidak ada yang memberi tahu; yang tertinggal hanya rincian yang
       tidak lagi cocok dengan daftar alokasinya.

       Yang dihapus sengaja alokasi yang LEBIH MUDA. Bila yang tertua yang dihapus, pencarian
       tanpa saringan nominal pun kebetulan menemukan baris yang benar (ia yang pertama), sehingga
       hilangnya saringan itu tidak akan terasa. */
    $k1 = $alok($TGL1, $BANK_A, 'Kembar', 250000);
    $k2 = $alok($TGL1, $BANK_A, 'Kembar', 750000);
    $alokSvc->hapus($k2->refresh(), $user->id);

    $j1->refresh();
    $sisaKembar = $j1->lines()->where('debit', '>', 0)
        ->get()->filter(fn ($l) => str_contains((string) $l->description, 'Kembar'))->values();
    $ok('tinggal SATU baris "Kembar"', $sisaKembar->count() === 1, (string) $sisaKembar->count());
    $ok('yang tersisa nominalnya 250.000 — bukan yang 750.000',
        $sisaKembar->count() === 1 && $rp($sisaKembar->first()->debit) === 250000,
        json_encode($sisaKembar->pluck('debit')));
    $ok('jurnalnya tetap balance', $rp($j1->lines()->sum('debit')) === $rp($j1->lines()->sum('credit')));
    $alokSvc->hapus($k1->refresh(), $user->id);

    echo "\n=== 6. HAPUS ALOKASI TERAKHIR → JURNALNYA BARU IKUT TERHAPUS ===\n";

    $idJ4 = $j4->id;
    $alokSvc->hapus($a4->refresh(), $user->id);
    $ok('jurnal yang tidak lagi ditumpangi siapa pun ikut terhapus',
        BsJournal::whereKey($idJ4)->first() === null);

    echo "\n=== 7. BATAS ALOKASI TETAP DIJAGA ===\n";

    /* Penggabungan tidak boleh melonggarkan batas: yang melebihi sisa bon tetap ditolak, dan
       yang ditolak tidak boleh meninggalkan baris apa pun di jurnal grupnya. */
    $barisSebelum = $j1->lines()->count();
    $kode = $tolak(fn () => $alok($TGL1, $BANK_A, 'Kelebihan', 99000000));
    $ok('alokasi melebihi sisa bon ditolak (ALLOC_EXCEEDS)', $kode === 'ALLOC_EXCEEDS', (string) $kode);
    $ok('penolakan tidak meninggalkan baris di jurnal grupnya',
        $j1->refresh()->lines()->count() === $barisSebelum,
        json_encode([$barisSebelum, $j1->lines()->count()]));
    $ok('jurnalnya tetap balance sesudah penolakan',
        $rp($j1->lines()->sum('debit')) === $rp($j1->lines()->sum('credit')));

    echo "\n=== 8. DATA LAMA: SATU GRUP YANG TERLANJUR PUNYA BEBERAPA JURNAL ===\n";

    /* Sebelum penggabungan, tiap alokasi punya jurnalnya sendiri — jadi satu grup (tanggal & bank
       sama) bisa menunjuk beberapa jurnal sekaligus. Jurnal lama itu sengaja TIDAK digabung surut:
       menghapusnya berarti melubangi nomor jurnal selamanya dan melenyapkan klasifikasi arus kas
       barisnya. Alokasi BARU harus menempel ke jurnal TERTUA di grup itu, bukan yang termuda —
       yang tertua itulah yang sudah dirujuk laporan & rekonsiliasi sebelumnya. */
    $bonLama = $svc->issue([
        'advance_date'   => $TGL1, 'holder' => 'UJI Data Lama', 'purpose' => 'Uji grup jurnal lama',
        'amount'         => 5000000, 'cost_center_id' => $cc, 'approval_mode' => 'offline',
    ], $user->id);
    $svc->pay($bonLama, [
        'paid_date' => $TGL1, 'payment_method' => 'debit_va',
        'va_expired_date' => sprintf('%04d-12-31', $TAHUN), 'credit_card_id' => $kartu->id,
    ], $user->id);

    $l1 = $alokSvc->tambah($bonLama, ['alloc_date' => $TGL1, 'description' => 'Lama 1', 'amount' => 100000, 'cash_account_id' => $BANK_A], $user->id);
    $l2 = $alokSvc->tambah($bonLama, ['alloc_date' => $TGL1, 'description' => 'Lama 2', 'amount' => 200000, 'cash_account_id' => $BANK_A], $user->id);

    // Dibuat menyerupai data lama: alokasi kedua dipaksa punya jurnalnya SENDIRI.
    $jTerpisah = $js->post([
        'journal_date' => $TGL1, 'source' => 'cash', 'status' => 'posted',
        'reference' => $bonLama->advance_number, 'description' => 'Alokasi Kasbon (model lama)',
        'created_by' => $user->id,
    ], [
        ['account_id' => (int) $bonLama->advance_account_id, 'cost_center_id' => $cc, 'debit' => 200000, 'credit' => 0, 'description' => 'Panjar lama'],
        ['account_id' => $BANK_A, 'cost_center_id' => $cc, 'debit' => 0, 'credit' => 200000, 'description' => 'Pengeluaran lama'],
    ]);
    $l2->update(['journal_id' => $jTerpisah->id]);

    $ok('grupnya memang menunjuk dua jurnal berbeda', $l1->journal_id !== $jTerpisah->id);

    $l3 = $alokSvc->tambah($bonLama, ['alloc_date' => $TGL1, 'description' => 'Lama 3', 'amount' => 300000, 'cash_account_id' => $BANK_A], $user->id);
    $ok('alokasi baru menempel ke jurnal TERTUA di grup itu',
        $l3->journal_id === $l1->journal_id, json_encode([$l3->journal_id, $l1->journal_id, $jTerpisah->id]));
    $ok('jurnal lama yang terpisah TIDAK ikut diutak-atik',
        BsJournal::whereKey($jTerpisah->id)->first() !== null
        && $rp(BsJournal::whereKey($jTerpisah->id)->first()->total_debit) === 200000);

    echo "\n=== 9. PERIODE TERTUTUP MENOLAK PENEMPELAN ===\n";

    /* Menempelkan baris pada jurnal bulan yang sudah ditutup akan mengubah angka yang sudah
       dilaporkan — dan karena penempelan tidak lewat JournalService::update(), pemeriksaannya
       harus dilakukan sendiri. Tanpa uji ini, hilangnya pemeriksaan itu tidak terasa sama sekali. */
    \App\Domain\BusinessSupport\Models\BsFiscalPeriod::where('year', $TAHUN)->where('month', $BULAN)
        ->update(['status' => 'closed']);

    $kode = $tolak(fn () => $alok($TGL1, $BANK_A, 'Sesudah closing', 100000));
    $ok('alokasi pada periode yang sudah closed ditolak', $kode === 'PERIOD_CLOSED', (string) $kode);

    \App\Domain\BusinessSupport\Models\BsFiscalPeriod::where('year', $TAHUN)->where('month', $BULAN)
        ->update(['status' => 'open']);

    echo "\n──────────────────────────────────────────────────────────────────────────────\n";
    echo $gagal === 0 ? "SEMUA LULUS ($lulus)\n" : "ADA YANG GAGAL — lulus $lulus, gagal $gagal\n";
} finally {
    DB::rollBack();
    echo "(data uji dibatalkan)\n";
}
