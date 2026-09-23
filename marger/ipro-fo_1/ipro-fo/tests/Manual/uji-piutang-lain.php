<?php

/**
 * PIUTANG LAIN — Piutang Usaha (Booked) → Piutang Lain.
 *
 * Layar ini menjumlah buku besar, jadi seluruh kegagalannya berupa ANGKA YANG SALAH tanpa satu
 * pun galat. Yang dijaga, berurut dari yang paling mahal:
 *
 *  1. SALDO AWAL DIHITUNG SEBELUM PERIODE, bukan di dalamnya. Menyaringnya dengan rentang yang
 *     sama seperti mutasi membuat Saldo Awal SELALU NOL — dan layarnya tetap terbaca masuk akal,
 *     sebab Penambahan & Pelunasan-nya benar. Ini kekeliruan yang paling mudah terjadi dan
 *     paling sulit terlihat.
 *  2. ANGKANYA SAMA DENGAN BUKU BESAR. Saldo akhir tiap akun dibandingkan langsung dengan
 *     LedgerReportController::accountLedger() untuk akun yang sama. Kalau layar ini memungut
 *     status jurnal yang berbeda (mis. ikut menghitung 'draft'), Neraca dan layar ini akan
 *     berbeda selamanya tanpa ada yang memberi tahu.
 *  3. TIDAK ADA BARIS YANG HILANG. Jumlah seluruh peminjam harus sama persis dengan totalnya;
 *     baris yang tak punya identitas dikumpulkan sebagai "(tanpa peminjam)", bukan dibuang.
 *     Baris yang hilang membuat angka layar berhenti sama dengan saldo akunnya.
 *  4. DEBIT MENAMBAH, KREDIT MELUNASI (akun aset), dan saldo akhir = awal + tambah − lunas —
 *     diperiksa per baris DAN pada totalnya.
 *  5. PEMINJAM DIAMBIL PER BARIS, bukan per jurnal. JournalPartyResolver memulangkan SATU nama
 *     untuk satu jurnal; memorial yang mendebit Piutang Karyawan untuk tiga orang dalam tiga
 *     baris akan menumpuk seluruh uangnya ke satu nama bila keterangan barisnya diabaikan.
 *  6. AKUNNYA DICARI LEWAT KODE INDUK, bukan nama. ADA TIGA akun bertingkat yang sama-sama
 *     bernama "PIUTANG LAIN-LAIN"; mencocokkan nama akan memulangkan akun yang salah.
 *
 * Seluruh tulisan dibungkus transaksi lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-piutang-lain.php';"
 */

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Http\Controllers\Web\BusinessSupport\Receivable\PiutangLainController as K;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$tuntas = false;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

$user = \App\Models\User::all()->first(fn ($u) => $u->hasRole('SuperAdmin')) ?? \App\Models\User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
auth()->setUser($user);
app()->instance('tenant.id', (int) $user->tenant_id);
$tenant = (int) $user->tenant_id;

/** Props Inertia sungguhan dari controller — bukan tiruan. */
$props = function (array $query = []) {
    $r = \Illuminate\Http\Request::create('/uji', 'GET', $query);
    $r->headers->set('X-Inertia', 'true');
    $r->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
    $r->setUserResolver(fn () => auth()->user());
    $ctl = new K;
    $resp = $ctl->index($r, app(\App\Domain\BusinessSupport\Services\JournalPartyResolver::class));

    return json_decode((string) $resp->toResponse($r)->getContent(), true)['props'] ?? [];
};

$TAG = 'ZPL'.strtoupper(substr((string) getmypid(), -4));
$AWAL = '2026-06-01';
$AKHIR = '2026-06-30';

DB::beginTransaction();

try {
    $akun = BsAccount::where('parent_code', K::KODE_INDUK)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name']);
    $ok('ketiga akun piutang lain ditemukan lewat kode induk', $akun->count() >= 3,
        'ditemukan '.$akun->count().' akun di bawah '.K::KODE_INDUK);
    $pemegangSaham = $akun->firstWhere('code', '10103010101');
    $karyawan      = $akun->firstWhere('code', '10103010102');
    $lainnya       = $akun->firstWhere('code', '10103010103');
    $ok('  ketiganya adalah akun yang diminta user',
        $pemegangSaham && $karyawan && $lainnya);

    $akunLawan = BsAccount::where('is_postable', true)->whereNotIn('id', $akun->pluck('id'))->firstOrFail();

    $nomor = 0;
    $jurnal = function (string $tanggal, string $status, array $baris, ?string $reference = null) use (&$nomor, $tenant, $akunLawan, $TAG) {
        $nomor++;
        $jid = (int) DB::table('bs_journals')->insertGetId([
            'tenant_id' => $tenant, 'journal_number' => 'JPL-'.$TAG.'-'.$nomor,
            'journal_date' => $tanggal,
            'period_year' => (int) substr($tanggal, 0, 4), 'period_month' => (int) substr($tanggal, 5, 2),
            'description' => 'Uji piutang lain', 'status' => $status, 'source' => 'memorial',
            'reference' => $reference,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $isi = [];
        $imbang = 0.0;
        foreach ($baris as [$akunId, $debit, $kredit, $ket]) {
            $isi[] = ['journal_id' => $jid, 'account_id' => $akunId, 'debit' => $debit, 'credit' => $kredit, 'description' => $ket];
            $imbang += $debit - $kredit;
        }
        // Lawan penyeimbang: jurnal yang tidak balance bukan jurnal, dan uji yang memakainya
        // tidak membuktikan apa pun tentang laporan yang membaca jurnal sungguhan.
        $isi[] = ['journal_id' => $jid, 'account_id' => $akunLawan->id, 'debit' => max(0, -$imbang), 'credit' => max(0, $imbang), 'description' => 'penyeimbang'];
        DB::table('bs_journal_lines')->insert($isi);

        return $jid;
    };

    // ── Data uji ───────────────────────────────────────────────────────────────
    // SEBELUM periode → hanya boleh masuk Saldo Awal.
    $jurnal('2026-05-10', 'posted', [[$pemegangSaham->id, 5_000_000, 0, 'Budi Pemegang Saham']]);
    $jurnal('2026-05-20', 'posted', [[$karyawan->id, 2_000_000, 0, 'Ani Karyawan']]);
    $jurnal('2026-05-25', 'posted', [[$karyawan->id, 0, 500_000, 'Ani Karyawan']]);

    // DI DALAM periode.
    $jurnal($AWAL, 'posted', [[$pemegangSaham->id, 1_000_000, 0, 'Budi Pemegang Saham']]);
    $jurnal('2026-06-15', 'posted', [[$pemegangSaham->id, 0, 3_000_000, 'Budi Pemegang Saham']]);
    // Satu jurnal, TIGA peminjam berbeda — inilah yang membuktikan identitas diambil per BARIS.
    $spk = DB::table('bs_work_orders')->whereNotNull('supplier_id')->first(['spk_number']);
    $jurnal('2026-06-20', 'posted', [
        [$karyawan->id, 700_000, 0, 'Ani Karyawan'],
        [$karyawan->id, 800_000, 0, 'Cici Karyawan'],
        [$karyawan->id, 900_000, 0, 'Dodi Karyawan'],
    ], $spk?->spk_number);
    // Tanpa keterangan baris → harus jatuh ke "(tanpa peminjam)", bukan hilang.
    $jurnal('2026-06-22', 'posted', [[$lainnya->id, 400_000, 0, null]]);
    // SESUDAH periode → tidak boleh ikut sama sekali.
    $jurnal('2026-07-05', 'posted', [[$lainnya->id, 999_000_000, 0, 'Sesudah Periode']]);
    // BELUM approved → tidak boleh ikut (Buku Besar & Neraca juga tidak menghitungnya).
    $jurnal('2026-06-10', 'draft', [[$pemegangSaham->id, 123_456_000, 0, 'Jurnal Draft']]);
    $jurnal('2026-06-11', 'final', [[$karyawan->id, 654_321_000, 0, 'Jurnal Final']]);

    $d = $props(['from' => $AWAL, 'to' => $AKHIR]);
    $baris = collect($d['rows'] ?? []);
    $total = $d['totals'] ?? [];
    $nama = fn (string $n) => $baris->firstWhere('peminjam', $n);

    echo "\n=== 1. Saldo awal dihitung SEBELUM periode ===\n";

    $ok('Budi: saldo awal 5.000.000', ($nama('Budi Pemegang Saham')['saldo_awal'] ?? null) == 5_000_000,
        'saldo awal tidak dihitung dari mutasi sebelum tanggal awal — angkanya '.($nama('Budi Pemegang Saham')['saldo_awal'] ?? 'tidak ada'));
    $ok('  Ani: saldo awal 1.500.000 (2jt debit − 500rb kredit)',
        ($nama('Ani Karyawan')['saldo_awal'] ?? null) == 1_500_000);
    $ok('  mutasi sebelum periode TIDAK ikut jadi penambahan',
        ($nama('Budi Pemegang Saham')['penambahan'] ?? null) == 1_000_000,
        'penambahan='.($nama('Budi Pemegang Saham')['penambahan'] ?? 'tidak ada'));

    echo "\n=== 2. Debit menambah, kredit melunasi, saldo akhir konsisten ===\n";

    $ok('Budi: penambahan 1.000.000', ($nama('Budi Pemegang Saham')['penambahan'] ?? null) == 1_000_000);
    $ok('  Budi: pelunasan 3.000.000', ($nama('Budi Pemegang Saham')['pelunasan'] ?? null) == 3_000_000,
        'penambahan & pelunasan tertukar untuk akun aset');
    $ok('  Budi: saldo akhir 3.000.000 (5jt + 1jt − 3jt)',
        ($nama('Budi Pemegang Saham')['saldo_akhir'] ?? null) == 3_000_000);

    $rumusBaris = $baris->every(fn ($r) => abs(($r['saldo_awal'] + $r['penambahan'] - $r['pelunasan']) - $r['saldo_akhir']) < 0.01);
    $ok('  rumus berlaku untuk SETIAP baris', $rumusBaris);
    $ok('  rumus berlaku untuk TOTAL',
        abs((($total['saldo_awal'] ?? 0) + ($total['penambahan'] ?? 0) - ($total['pelunasan'] ?? 0)) - ($total['saldo_akhir'] ?? 0)) < 0.01);

    echo "\n=== 3. Tidak ada baris yang hilang ===\n";

    foreach (['saldo_awal', 'penambahan', 'pelunasan', 'saldo_akhir'] as $kolom) {
        $ok('  jumlah seluruh peminjam === total '.$kolom,
            abs($baris->sum($kolom) - ($total[$kolom] ?? 0)) < 0.01,
            'ada baris yang dibuang — angka layar berhenti sama dengan saldo akunnya');
    }
    $ok('baris tanpa keterangan tetap tampil sebagai "(tanpa peminjam)"',
        ($nama('(tanpa peminjam)')['penambahan'] ?? null) == 400_000,
        'baris tanpa identitas dibuang diam-diam');

    echo "\n=== 4. Peminjam diambil PER BARIS, bukan per jurnal ===\n";

    // Satu jurnal berisi tiga orang. Kalau identitasnya diambil per jurnal
    // (JournalPartyResolver), ketiganya akan menumpuk jadi satu nama.
    foreach ([['Ani Karyawan', 700_000], ['Cici Karyawan', 800_000], ['Dodi Karyawan', 900_000]] as [$n, $nilai]) {
        $ok("  $n berdiri sendiri dengan penambahan ".number_format($nilai, 0, ',', '.'),
            ($nama($n)['penambahan'] ?? null) == $nilai,
            'satu jurnal banyak orang menumpuk ke satu nama — uang berpindah ke orang yang salah');
    }

    // Prasyarat mutasi: resolver HARUS punya nama untuk jurnal tiga orang itu. Kalau tidak,
    // "ambil per jurnal" dan "ambil per baris" memulangkan hasil yang sama, dan ketiga
    // pemeriksaan di atas tidak membuktikan apa pun. Referensinya diisi nomor SPK karena
    // JournalPartyResolver mencocokkan bs_journals.reference dengan bs_work_orders.spk_number.
    $rinAni = collect($props(['from' => $AWAL, 'to' => $AKHIR, 'mode' => 'detail', 'peminjam' => 'Ani Karyawan'])['detail'] ?? []);
    $pihakJurnal = $rinAni->pluck('pihak_jurnal')->filter()->unique()->values();
    $ok('  prasyarat: jurnal itu MEMANG punya nama versi penelusuran balik',
        $spk !== null && $pihakJurnal->isNotEmpty(),
        'tanpa ini, pemeriksaan "per baris vs per jurnal" di atas tidak menggigit');
    $ok('  nama versi penelusuran balik ditampilkan berdampingan, bukan menggantikan',
        $pihakJurnal->first() !== 'Ani Karyawan'
        && collect($rinAni)->contains(fn ($r) => ($r['description'] ?? '') === 'Ani Karyawan'));

    echo "\n=== 5. Hanya jurnal Approved yang dihitung ===\n";

    $ok('jurnal draft tidak ikut', $nama('Jurnal Draft') === null,
        'angka layar tidak akan pernah sama dengan Neraca');
    $ok('  jurnal final (belum approved) tidak ikut', $nama('Jurnal Final') === null);
    $ok('  mutasi SESUDAH periode tidak ikut', $nama('Sesudah Periode') === null);

    echo "\n=== 6. Angkanya sama dengan Buku Besar ===\n";

    // Pembuktian paling menentukan: bandingkan dengan accountLedger() yang dipakai layar Buku
    // Besar, per akun. Keduanya harus tidak mungkin berbeda.
    $ledger = new ReflectionMethod(\App\Http\Controllers\Web\BusinessSupport\Gl\LedgerReportController::class, 'accountLedger');
    $ledger->setAccessible(true);
    $ctlGl = app(\App\Http\Controllers\Web\BusinessSupport\Gl\LedgerReportController::class);

    foreach ([$pemegangSaham, $karyawan, $lainnya] as $a) {
        $bb = $ledger->invoke($ctlGl, (int) $a->id, $AWAL, $AKHIR);
        $layar = $props(['from' => $AWAL, 'to' => $AKHIR, 'account_id' => $a->id])['totals'] ?? [];
        $ok('  '.$a->name.': saldo awal sama dengan Buku Besar',
            abs(($layar['saldo_awal'] ?? 0) - (float) $bb['opening']) < 0.01,
            'layar='.($layar['saldo_awal'] ?? 0).' bukubesar='.$bb['opening']);
        $akhirBb = (float) $bb['opening'] + collect($bb['rows'])->sum(fn ($r) => (float) $r['debit'] - (float) $r['credit']);
        $ok('    dan saldo akhirnya',
            abs(($layar['saldo_akhir'] ?? 0) - $akhirBb) < 0.01,
            'layar='.($layar['saldo_akhir'] ?? 0).' bukubesar='.$akhirBb);
    }

    echo "\n=== 7. Penyaring akun ===\n";

    $perAkun = [];
    foreach ([$pemegangSaham, $karyawan, $lainnya] as $a) {
        $perAkun[$a->code] = $props(['from' => $AWAL, 'to' => $AKHIR, 'account_id' => $a->id])['totals'] ?? [];
    }
    $ok('memilih satu akun hanya menampilkan akun itu',
        ($perAkun['10103010101']['saldo_akhir'] ?? null) == 3_000_000);
    foreach (['saldo_awal', 'penambahan', 'pelunasan', 'saldo_akhir'] as $kolom) {
        $jumlahTiga = array_sum(array_column($perAkun, $kolom));
        $ok('  "semua akun" === jumlah ketiga akun untuk '.$kolom,
            abs($jumlahTiga - ($total[$kolom] ?? 0)) < 0.01,
            'semua='.($total[$kolom] ?? 0).' jumlah-tiga='.$jumlahTiga);
    }
    $ok('  account_id asing diabaikan, bukan memulangkan kosong',
        abs((($props(['from' => $AWAL, 'to' => $AKHIR, 'account_id' => 999999999])['totals'] ?? [])['saldo_akhir'] ?? -1) - ($total['saldo_akhir'] ?? 0)) < 0.01);

    echo "\n=== 8. Rincian per peminjam ===\n";

    $rin = $props(['from' => $AWAL, 'to' => $AKHIR, 'mode' => 'detail', 'peminjam' => 'Budi Pemegang Saham']);
    $det = collect($rin['detail'] ?? []);
    $ok('rincian memuat hanya baris peminjam itu', $det->count() === 2,
        'baris rincian: '.$det->count());
    $ok('  saldo berjalan dimulai dari saldo awalnya',
        abs(($det->first()['saldo'] ?? 0) - 6_000_000) < 0.01,
        'saldo berjalan pertama='.($det->first()['saldo'] ?? 'tidak ada').' (harusnya 5jt + 1jt)');
    $ok('  saldo berjalan terakhir === saldo akhir barisnya',
        abs(($det->last()['saldo'] ?? 0) - ($nama('Budi Pemegang Saham')['saldo_akhir'] ?? -1)) < 0.01);
    $ok('  rincian menyebut nama akunnya', ($det->first()['account'] ?? '') === $pemegangSaham->name);
    $ok('  mode ringkas tidak ikut mengirim rincian', count($d['detail'] ?? []) === 0);

    echo "\n=== 9. Rentang tanggal terbalik ditukar, bukan didiamkan ===\n";

    $terbalik = $props(['from' => $AKHIR, 'to' => $AWAL])['filters'] ?? [];
    $ok('from/to ditukar', ($terbalik['from'] ?? '') === $AWAL && ($terbalik['to'] ?? '') === $AKHIR,
        'rentang terbalik memulangkan daftar kosong yang terbaca seperti "tidak ada data"');

    echo "\n=== 10. Jurnal terhapus tidak ikut ===\n";

    $jidHapus = $jurnal('2026-06-12', 'posted', [[$lainnya->id, 777_000_000, 0, 'Jurnal Terhapus']]);
    DB::table('bs_journals')->where('id', $jidHapus)->update(['deleted_at' => now()]);
    $sesudah = collect($props(['from' => $AWAL, 'to' => $AKHIR])['rows'] ?? []);
    $ok('jurnal yang dihapus tidak menambah apa pun',
        $sesudah->firstWhere('peminjam', 'Jurnal Terhapus') === null);

    echo "\n=== 11. Perkabelan layar ===\n";

    $nav = file_get_contents(base_path('resources/js/Components/Layout/navGroups.ts'));
    $posHapus = strpos($nav, "label: 'Penghapusan Piutang'");
    $posLain = strpos($nav, "label: 'Piutang Lain'");
    $ok('sidebar memuat "Piutang Lain"', $posLain !== false);
    $ok('  letaknya DI BAWAH "Penghapusan Piutang"',
        $posHapus !== false && $posLain !== false && $posHapus < $posLain);
    $ok('  menunjuk rute bs.ar.piutang-lain', str_contains($nav, "href: 'bs.ar.piutang-lain'"));

    $rute = app('router')->getRoutes()->getByName('bs.ar.piutang-lain');
    $ok('rutenya terdaftar', $rute !== null);
    // Prefix satu segmen: wildcard /receivable/{invoice} akan menelan /receivable/apa-pun.
    $ok('  alamatnya satu segmen (tidak tertelan wildcard receivable/{invoice})',
        $rute !== null && str_contains($rute->uri(), 'receivable-piutang-lain'));

    $ok('izin ar.piutang-lain.view ada', DB::table('permissions')->where('name', 'ar.piutang-lain.view')->exists());
    $ok('  dan dipegang SuperAdmin',
        DB::table('role_has_permissions as rp')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->join('roles as r', 'r.id', '=', 'rp.role_id')
            ->where('p.name', 'ar.piutang-lain.view')->where('r.name', 'SuperAdmin')->exists(),
        'izin yatim — menunya tidak akan muncul untuk siapa pun');
    $ok('  seeder izin juga memuatnya',
        str_contains(file_get_contents(base_path('database/seeders/GranularPermissionSeeder.php')), "'ar.piutang-lain.view'"));

    $ok('halaman Vue-nya ada', is_file(base_path('resources/js/Pages/BusinessSupport/Receivable/PiutangLain.vue')));

    // Akun dicari lewat kode induk, bukan lewat nama: ADA TIGA akun bertingkat yang sama-sama
    // bernama "PIUTANG LAIN-LAIN", dan dua di antaranya bukan akun posting.
    $src = file_get_contents((new ReflectionClass(K::class))->getFileName());
    $ok('akun dicari lewat parent_code, bukan nama',
        str_contains($src, "where('parent_code', self::KODE_INDUK)") && ! str_contains($src, "'name', 'like'"));
    $ok('  hanya jurnal posted yang dihitung', str_contains($src, "where('status', 'posted')"));

    $tuntas = true;
} catch (\Throwable $e) {
    $gagal++;
    echo "\n  GAGAL (galat tak tertangani) — ".get_class($e).': '.$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n";
} finally {
    DB::rollBack();

    echo "\n────────────────────────────────\n";
    echo "LULUS: $lulus   GAGAL: $gagal\n";
    // Tanpa penanda "sampai ujung", galat di tengah jalan meninggalkan GAGAL 0 dan skrip ini
    // akan mencetak SEMUA UJI LULUS padahal separuh pemeriksaannya tak pernah berjalan.
    echo ($gagal === 0 && $tuntas) ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
    echo "(data uji dibatalkan)\n";
}
