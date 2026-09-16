<?php

/**
 * Cost center per baris pada Registrasi Pengeluaran Kas & Bank — sisi server.
 *
 * Sisi layar diuji di tests/Manual/uji-cost-center-kas-bank.cjs. Berkas ini menutup ujung yang
 * tidak bisa dilihat dari sana, dan yang paling mudah luput:
 *
 *  1. COST CENTER HARUS SELAMAT SAMPAI KE BARIS JURNAL. Rantainya panjang: payload →
 *     normalizeLines → bs_cash_transaction_lines → journalLines → JournalService. Putus di mana
 *     pun, jurnalnya tetap terbentuk dan tetap balance — yang hilang hanya dimensinya, dan itu
 *     baru ketahuan berbulan kemudian saat anggaran direkonsiliasi.
 *  2. KAS KELUAR (BKK) BARU BERJURNAL SAAT DISETUJUI. postJournal() menyusun ulang barisnya DARI
 *     TABEL, bukan dari payload yang dulu dikirim. Karena itu kolom di basis data bukan pilihan:
 *     tanpa dipersistensi, cost centernya hilang tepat di antara "Simpan" dan "Approve".
 *  3. KEWAJIBANNYA BERGANTUNG PADA AKUN. 71 dari 267 akun postable bertanda cost_center_optional
 *     — termasuk rekening bank tujuan pada Transfer Antar Rekening. Mewajibkannya tanpa pandang
 *     akun akan mengunci mode transfer sepenuhnya. Kedua sisinya diuji.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-cost-center-kas-bank.php';"
 */

use App\Domain\BusinessSupport\Actions\RecordCashTransaction;
use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCashTransaction;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

$user = User::all()->first(fn ($u) => $u->canModule('businesssupport')) ?? User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
auth()->setUser($user);

echo "\n=== 0. Kolomnya benar-benar ada di basis data ===\n";

$ok('bs_cash_transaction_lines punya kolom cost_center_id',
    Schema::hasColumn('bs_cash_transaction_lines', 'cost_center_id'),
    'migrasi belum dijalankan — cost center akan hilang antara Simpan dan Approve');
$ok('bs_journal_lines punya kolom cost_center_id', Schema::hasColumn('bs_journal_lines', 'cost_center_id'));

DB::beginTransaction();

try {
    $aksi = app(RecordCashTransaction::class);

    $kas = BsAccount::where('is_active', true)->where('is_postable', true)
        ->where('code', 'like', '101010%')->orderBy('code')->first();
    // Akun beban yang MENUNTUT cost center, dan akun lain yang justru DIKECUALIKAN.
    $wajib = BsAccount::where('is_active', true)->where('is_postable', true)
        ->where('cost_center_optional', false)->where('type', 'expense')->orderBy('code')->first();
    $bebas = BsAccount::where('is_active', true)->where('is_postable', true)
        ->where('cost_center_optional', true)->whereNot('id', $kas?->id)->orderBy('code')->first();
    $cc = BsCostCenter::where('is_active', true)->orderBy('code')->first();

    $ok('akun kas/bank ada', (bool) $kas);
    $ok('akun yang MENUNTUT cost center ada', (bool) $wajib, 'tidak ada akun beban ber-cost_center_optional=false');
    $ok('akun yang DIKECUALIKAN ada', (bool) $bebas, 'tidak ada akun ber-cost_center_optional=true');
    $ok('cost center ada', (bool) $cc);
    if (! $kas || ! $wajib || ! $bebas || ! $cc) { DB::rollBack(); echo "Prasyarat kurang — uji dihentikan.\n"; return; }

    $no = 0;
    $simpan = function (array $ubah) use ($aksi, $kas, $user, &$no) {
        return $aksi->execute(array_merge([
            'trx_type'        => 'out',
            'trx_date'        => now()->toDateString(),
            'cash_account_id' => $kas->id,
            'description'     => 'UJI cost center kas bank '.(++$no),
            'payee'           => 'UJI',
        ], $ubah), $user->id);
    };

    echo "\n=== 1. Cost center tersimpan ke baris transaksi ===\n";

    $trx = $simpan(['lines' => [
        ['account_id' => $wajib->id, 'cost_center_id' => $cc->id, 'amount' => 150000, 'description' => 'Baris uji'],
    ]]);
    $baris = $trx->lines()->first();

    $ok('barisnya tersimpan', (bool) $baris);
    $ok('cost_center_id tersimpan di bs_cash_transaction_lines',
        (int) ($baris->cost_center_id ?? 0) === $cc->id,
        'dapat '.var_export($baris->cost_center_id ?? null, true).', diharapkan '.$cc->id);

    echo "\n=== 2. Kas Keluar baru berjurnal saat DISETUJUI — cost center harus selamat ===\n";

    // Inilah sebabnya kolom DB wajib: jurnalnya dibentuk ulang dari tabel, bukan dari payload.
    $ok('BKK belum berjurnal saat disimpan', $trx->journal_id === null,
        'jurnal terbentuk lebih awal — skenario ini tidak lagi menguji jalur approval');

    $aksi->postJournal($trx->fresh(), $user->id);
    $trx = $trx->fresh();
    $ok('BKK berjurnal setelah disetujui', (bool) $trx->journal_id);

    $jl = BsJournal::find($trx->journal_id)?->lines ?? collect();
    $barisBeban = $jl->firstWhere('account_id', $wajib->id);
    $barisKas   = $jl->firstWhere('account_id', $kas->id);

    $ok('baris beban ada di jurnal', (bool) $barisBeban);
    $ok('cost center SELAMAT sampai ke baris jurnal',
        (int) ($barisBeban->cost_center_id ?? 0) === $cc->id,
        'dapat '.var_export($barisBeban->cost_center_id ?? null, true).' — hilang antara Simpan dan Approve');
    // Baris kas sengaja TANPA cost center: rekening kas/bank tidak menanggung anggaran, dan
    // mengisinya membuat realisasi tercatat dua kali pada cost center yang sama.
    $ok('baris KAS justru tanpa cost center', ($barisKas->cost_center_id ?? null) === null,
        'dapat '.var_export($barisKas->cost_center_id ?? null, true));
    $d = (float) $jl->sum('debit');
    $k = (float) $jl->sum('credit');
    $ok('jurnalnya balance', abs($d - $k) < 0.01, "D=$d K=$k");

    echo "\n=== 3. Wajib — akun yang menuntut cost center ditahan ===\n";

    $ditolak = null;
    try {
        $simpan(['lines' => [['account_id' => $wajib->id, 'amount' => 90000, 'description' => 'tanpa cc']]]);
    } catch (BusinessRuleException $e) {
        $ditolak = $e;
    }
    $ok('tanpa cost center DITOLAK', $ditolak !== null, 'baris jurnal tanpa dimensi lolos ke buku besar');
    $ok('kode galatnya CASH_NO_COST_CENTER', $ditolak?->errorCode() === 'CASH_NO_COST_CENTER',
        (string) $ditolak?->errorCode());
    $ok('pesannya menyebut akun mana yang kurang',
        str_contains((string) $ditolak?->getMessage(), $wajib->code),
        (string) $ditolak?->getMessage());

    // cost_center_id = 0 bukan "terisi" — id itu tidak pernah ada di master.
    $nol = null;
    try {
        $simpan(['lines' => [['account_id' => $wajib->id, 'cost_center_id' => 0, 'amount' => 90000]]]);
    } catch (BusinessRuleException $e) { $nol = $e; }
    $ok('cost_center_id = 0 juga ditolak', $nol !== null, 'id 0 dianggap terisi');

    echo "\n=== 4. Dikecualikan — akun cost_center_optional tetap boleh kosong ===\n";

    $bebasTrx = null;
    $galatBebas = null;
    try {
        $bebasTrx = $simpan(['lines' => [['account_id' => $bebas->id, 'amount' => 70000, 'description' => 'dikecualikan']]]);
    } catch (BusinessRuleException $e) { $galatBebas = $e; }

    $ok('akun dikecualikan boleh tanpa cost center', $bebasTrx !== null,
        'layar akan mengunci diri pada transaksi yang sebenarnya sah: '.(string) $galatBebas?->getMessage());
    $ok('barisnya tersimpan dengan cost center null',
        $bebasTrx && ($bebasTrx->lines()->first()->cost_center_id ?? null) === null);

    echo "\n=== 5. Transfer Antar Rekening tidak ikut terkunci ===\n";

    // Rekening tujuan transfer adalah akun kas/bank — umumnya cost_center_optional.
    $tujuan = BsAccount::where('is_active', true)->where('is_postable', true)
        ->where('code', 'like', '101010%')->where('cost_center_optional', true)
        ->whereNot('id', $kas->id)->orderBy('code')->first();

    if (! $tujuan) {
        $ok('rekening tujuan transfer yang dikecualikan ada', false, 'tidak ada akun 101010% ber-cost_center_optional=true');
    } else {
        $trf = null;
        $galatTrf = null;
        try {
            $trf = $aksi->execute([
                'trx_type' => 'transfer', 'trx_date' => now()->toDateString(),
                'cash_account_id' => $kas->id, 'description' => 'UJI transfer cc', 'payee' => 'UJI',
                'lines' => [['account_id' => $tujuan->id, 'amount' => 50000]],
            ], $user->id);
        } catch (BusinessRuleException $e) { $galatTrf = $e; }

        $ok('transfer tanpa cost center tetap bisa disimpan', $trf !== null,
            'mode transfer terkunci: '.(string) $galatTrf?->getMessage());
    }

    echo "\n=== 6. Controller benar-benar mengirim bahan yang dibutuhkan layar ===\n";

    /* Layar tidak bisa mewajibkan apa pun bila bahannya tidak dikirim: tanpa daftar costCenters
       kolomnya kosong melompong, dan tanpa penanda cost_center_optional layar menganggap SEMUA
       akun menuntut cost center — mode Transfer terkunci selamanya. Propnya diambil dengan
       MEMANGGIL controllernya, bukan dihitung ulang di sini. */
    $ctl = app(\App\Http\Controllers\Web\BusinessSupport\CashBook\CashBookController::class);
    $req = \Illuminate\Http\Request::create('/business-support/cash-book/create', 'GET', ['type' => 'out']);
    $req->setUserResolver(fn () => $user);
    $req->headers->set('X-Inertia', 'true');
    $req->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
    $props = json_decode((string) $ctl->create($req)->toResponse($req)->getContent(), true)['props'] ?? [];

    $ok('daftar costCenters dikirim ke layar', ! empty($props['costCenters']),
        'kolom Cost Center akan kosong melompong');
    $ok('isinya cost center yang aktif',
        collect($props['costCenters'] ?? [])->contains(fn ($c) => (int) $c['id'] === $cc->id),
        json_encode(array_slice($props['costCenters'] ?? [], 0, 3)));
    $ok('counterAccounts membawa penanda cost_center_optional',
        collect($props['counterAccounts'] ?? [])->every(fn ($a) => array_key_exists('cost_center_optional', $a)),
        'layar akan menganggap semua akun menuntut cost center — mode Transfer terkunci');
    $ok('penandanya membedakan akun, bukan seragam',
        collect($props['counterAccounts'] ?? [])->pluck('cost_center_optional')->unique()->count() > 1,
        'semua akun bernilai sama — penandanya tidak berarti apa-apa');

    // Membuka transaksi lama untuk disunting harus membawa cost centernya kembali.
    $reqEdit = \Illuminate\Http\Request::create('/x', 'GET');
    $reqEdit->setUserResolver(fn () => $user);
    $reqEdit->headers->set('X-Inertia', 'true');
    $reqEdit->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
    $propsEdit = json_decode((string) $ctl->edit($reqEdit, $trx->fresh())->toResponse($reqEdit)->getContent(), true)['props'] ?? [];
    $barisEdit = $propsEdit['editing']['lines'][0] ?? [];

    $ok('baris lama saat edit membawa cost_center_id',
        array_key_exists('cost_center_id', $barisEdit),
        'membuka transaksi lama akan mengosongkan cost centernya diam-diam: '.json_encode($barisEdit));
    $ok('nilainya memang cost center yang dipilih dulu', (int) ($barisEdit['cost_center_id'] ?? 0) === $cc->id,
        json_encode($barisEdit));
    $ok('layar edit juga menerima daftar costCenters', ! empty($propsEdit['costCenters']));

    echo "\n=== 7. Mengubah transaksi tidak menghilangkan cost centernya ===\n";

    $ubah = $simpan(['lines' => [
        ['account_id' => $wajib->id, 'cost_center_id' => $cc->id, 'amount' => 120000, 'description' => 'sebelum ubah'],
    ]]);
    $aksi->update($ubah, [
        'trx_type' => 'out', 'trx_date' => now()->toDateString(), 'cash_account_id' => $kas->id,
        'description' => 'UJI setelah diubah', 'payee' => 'UJI',
        'lines' => [['account_id' => $wajib->id, 'cost_center_id' => $cc->id, 'amount' => 130000, 'description' => 'sesudah ubah']],
    ], $user->id);

    $barisUbah = $ubah->fresh()->lines()->first();
    $ok('cost center tetap ada setelah diubah', (int) ($barisUbah->cost_center_id ?? 0) === $cc->id,
        var_export($barisUbah->cost_center_id ?? null, true));
    $ok('nominalnya memang ikut berubah', (float) $barisUbah->amount === 130000.0, (string) $barisUbah->amount);

    echo "\n────────────────────────────────\n";
    echo "LULUS: $lulus   GAGAL: $gagal\n";
    echo $gagal === 0 ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
} finally {
    DB::rollBack();
}
