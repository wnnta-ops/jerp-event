<?php

/**
 * "Komponen COGS" pada Chart of Account → mutasi buku besar terbaca di layar Komponen COGS.
 *
 * Akun yang ditandai salah satu komponen (Tanah/Housing/Infrastruktur/License Permit) membuat
 * SELURUH mutasinya — masuk maupun keluar — ikut terbaca di layar komponen itu.
 *
 * Yang dijaga, berurut dari yang paling mahal bila salah:
 *
 *  1. ANGKANYA TIDAK BOLEH DIJUMLAHKAN DENGAN SUMBER LAMA. Satu akun kewajiban di bagan akun
 *     ini melayani BEBERAPA kategori invoice sekaligus (mis. 20107070704 adalah akun debit
 *     TANAH-C1, LICENSE-PERMIT-C1, DAN KONSTRUKSI-INFRA-C1). Menjumlahkan blok buku besar
 *     dengan blok Kategori Invoice/SPK akan menghitung uang yang sama dua kali — tanpa galat,
 *     hanya angka HPP yang menggelembung. Karena itu blok GL berdiri di prop SENDIRI dan
 *     ringkasan lama TIDAK BOLEH berubah sedikit pun.
 *  2. MASUK & KELUAR DITAMPILKAN TERPISAH, bukan satu angka bersih. Akun CIP yang ditandai
 *     komponen juga DIKREDIT oleh proses HPP sendiri (CogsService::postPersediaan menulis
 *     "Dr Persediaan Unit / Cr CIP"). Satu angka bersih akan meluncur ke NOL begitu HPP
 *     dikapitalisasi, dan layarnya terlihat seperti kehilangan biaya.
 *  3. HANYA JURNAL APPROVED YANG DIJUMLAH, tetapi yang belum tetap DITAMPILKAN. Status bawaan
 *     di pemasangan ini 'final', bukan 'posted', sedangkan Buku Besar & Neraca hanya
 *     menghitung 'posted'. Menyaring 'posted' saja membuat layar kosong padahal biayanya ada;
 *     menjumlahkan semuanya membuat angkanya tidak pernah sama dengan Buku Besar.
 *  4. BARIS YANG TIDAK TERTELUSUR TIDAK DIBUANG. No. SPR/Customer/Unit hanya ada bila
 *     jurnalnya bisa ditelusuri balik ke dokumen ber-booking; sisanya tampil dengan sel kosong.
 *  5. KOLOMNYA BENAR-BENAR TERSIMPAN. Kolom yang lupa didaftarkan di $fillable lolos validasi,
 *     memunculkan notifikasi "tersimpan", lalu nilainya hilang — preseden nyatanya ada di
 *     model yang sama (cost_center_optional).
 *  6. NILAI YANG DISIMPAN adalah kunci komponen HPP ('tanah'|'bangunan'|'sarana'|'permit'),
 *     bukan slug alamat layar — sebahasa dengan bs_cogs_component_ap_categories.component.
 *
 * Seluruh tulisan dibungkus transaksi lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-komponen-cogs-dari-coa.php';"
 */

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Http\Controllers\Web\BusinessSupport\Cogs\CogsComponentController as K;
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

$props = function (string $komponen, array $query = []) {
    $r = \Illuminate\Http\Request::create('/uji', 'GET', $query);
    $r->headers->set('X-Inertia', 'true');
    $r->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
    $r->setUserResolver(fn () => auth()->user());

    return json_decode((string) app(K::class)->show($r, $komponen)->toResponse($r)->getContent(), true)['props'] ?? [];
};

$TAG = 'ZKC'.strtoupper(substr((string) getmypid(), -4));

DB::beginTransaction();

try {
    echo "\n=== 1. Daftar pilihan memakai kunci komponen HPP ===\n";

    $pilihan = K::pilihanKomponen();
    $ok('empat pilihan tersedia', count($pilihan) === 4);
    $ok('  kuncinya kunci HPP, bukan slug alamat',
        array_keys($pilihan) === ['tanah', 'bangunan', 'sarana', 'permit'],
        'kunci: '.implode(', ', array_keys($pilihan)));
    $ok('  labelnya yang dibaca operator sesuai permintaan',
        $pilihan['bangunan'] === 'Housing' && $pilihan['sarana'] === 'Infrastruktur' && $pilihan['permit'] === 'License Permit');
    // Slug alamat layar sengaja TIDAK dipakai di basis data: dua kosakata untuk satu hal
    // berarti setiap pembacaan harus menerjemahkan, dan satu terjemahan terlupa =
    // komponen yang diam-diam kosong.
    $ok('  kunci basis data sama dengan bs_cogs_component_ap_categories.component',
        DB::table('bs_cogs_component_ap_categories')->whereNotIn('component', array_keys($pilihan))->count() === 0);

    echo "\n=== 2. Kolomnya benar-benar tersimpan ===\n";

    $indukAkun = BsAccount::where('level', 5)->orderBy('code')->firstOrFail(['id', 'code']);
    $akun = BsAccount::create([
        'tenant_id' => $tenant, 'code' => $indukAkun->code.'98', 'name' => 'CIP UJI '.$TAG,
        'type' => 'asset', 'normal_balance' => 'D', 'level' => 6,
        'parent_id' => $indukAkun->id, 'parent_code' => $indukAkun->code,
        'is_postable' => true, 'is_active' => true,
        'cogs_component' => 'tanah',
    ]);
    $ok('tersimpan saat create', BsAccount::whereKey($akun->id)->value('cogs_component') === 'tanah',
        'kolom tidak terdaftar di $fillable — Eloquent membuangnya tanpa galat');
    $akun->update(['cogs_component' => 'bangunan']);
    $ok('  tersimpan saat update', BsAccount::whereKey($akun->id)->value('cogs_component') === 'bangunan');
    $akun->update(['cogs_component' => null]);
    $ok('  boleh dikosongkan kembali', BsAccount::whereKey($akun->id)->value('cogs_component') === null);
    $akun->update(['cogs_component' => 'tanah']);

    echo "\n=== 3. Validasi layar COA ===\n";

    $ctlAkun = app(\App\Http\Controllers\Web\BusinessSupport\Setup\AccountController::class);
    $refData = new ReflectionMethod($ctlAkun, 'data');
    $refData->setAccessible(true);
    $induk = BsAccount::where('level', 5)->orderBy('code')->firstOrFail(['id', 'code']);
    $dasar = [
        'code' => $induk->code.'99', 'name' => 'Uji validasi', 'level' => 6, 'parent_id' => $induk->id,
        'type' => 'asset', 'normal_balance' => 'D',
    ];
    $tolak = false;
    try {
        $refData->invoke($ctlAkun, \Illuminate\Http\Request::create('/x', 'POST', $dasar + ['cogs_component' => 'housing']), null);
    } catch (\Illuminate\Validation\ValidationException $e) {
        $tolak = array_key_exists('cogs_component', $e->errors());
    }
    $ok('slug alamat ("housing") DITOLAK sebagai nilai simpan', $tolak,
        'layar boleh menyimpan kosakata kedua — komponen jadi diam-diam kosong');
    $terima = $refData->invoke($ctlAkun, \Illuminate\Http\Request::create('/x', 'POST', $dasar + ['cogs_component' => 'bangunan']), null);
    $ok('  kunci HPP ("bangunan") diterima', ($terima['cogs_component'] ?? null) === 'bangunan');
    $kosong = $refData->invoke($ctlAkun, \Illuminate\Http\Request::create('/x', 'POST', $dasar), null);
    $ok('  tanpa nilai tetap sah (tidak wajib)', ! isset($kosong['cogs_component']) || $kosong['cogs_component'] === null);

    echo "\n=== 4. Mutasi masuk & keluar terbaca di layar komponen ===\n";

    $lawan = BsAccount::where('is_postable', true)->where('id', '!=', $akun->id)->firstOrFail();
    $jurnal = function (string $tanggal, string $status, float $debit, float $kredit) use (&$n, $tenant, $akun, $lawan, $TAG) {
        static $urut = 0;
        $urut++;
        $jid = (int) DB::table('bs_journals')->insertGetId([
            'tenant_id' => $tenant, 'journal_number' => 'JKC-'.$TAG.'-'.$urut, 'journal_date' => $tanggal,
            'period_year' => (int) substr($tanggal, 0, 4), 'period_month' => (int) substr($tanggal, 5, 2),
            'description' => 'Uji komponen dari COA', 'status' => $status, 'source' => 'memorial',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('bs_journal_lines')->insert([
            ['journal_id' => $jid, 'account_id' => $akun->id, 'debit' => $debit, 'credit' => $kredit, 'description' => 'baris uji'],
            ['journal_id' => $jid, 'account_id' => $lawan->id, 'debit' => $kredit, 'credit' => $debit, 'description' => 'penyeimbang'],
        ]);

        return $jid;
    };

    $jurnal('2026-06-10', 'posted', 5_000_000, 0);   // biaya masuk
    $jurnal('2026-06-20', 'posted', 0, 2_000_000);   // kapitalisasi HPP: Cr CIP
    $jurnal('2026-06-25', 'final', 7_000_000, 0);    // belum Approved

    $d = $props('tanah');
    $gl = $d['glRingkas'] ?? [];
    $baris = collect($d['glBaris'] ?? []);

    $ok('akun bertanda muncul di layar komponennya', count($d['glAkun'] ?? []) >= 1);
    $ok('ketiga baris DITAMPILKAN, termasuk yang belum Approved', $baris->count() === 3,
        'baris: '.$baris->count());
    $ok('  yang belum Approved dihitung & ditandai', ($gl['belumEfektif'] ?? null) === 1);

    // Inilah jebakan nomor 2: kalau masuk & keluar dilebur jadi satu angka bersih, angka ini
    // akan menyusut diam-diam setiap kali HPP dikapitalisasi.
    $ok('Masuk = 5.000.000 (hanya jurnal Approved)', ($gl['masuk'] ?? null) == 5_000_000,
        'masuk='.($gl['masuk'] ?? 'tidak ada').' — jurnal final ikut terjumlah, angkanya tidak akan sama dengan Buku Besar');
    $ok('  Keluar = 2.000.000 dan berdiri sendiri', ($gl['keluar'] ?? null) == 2_000_000,
        'mutasi keluar tidak terbaca — kapitalisasi HPP tak terlihat di layar');
    $ok('  Netto = 3.000.000', ($gl['netto'] ?? null) == 3_000_000);
    $ok('  baris yang belum Approved TIDAK ikut dijumlah',
        ($gl['masuk'] ?? 0) != 12_000_000);

    echo "\n=== 5. Tidak dijumlahkan dengan sumber lama ===\n";

    // Blok GL berdiri di prop sendiri; ringkasan lama tidak boleh bergerak sedikit pun.
    $ok('blok buku besar dikirim di prop terpisah',
        array_key_exists('glBaris', $d) && array_key_exists('glRingkas', $d) && array_key_exists('ringkas', $d));
    // Dibandingkan sebagai PECAHAN, bukan dengan in_array ketat: 5_000_000 (int) tidak pernah
    // identik dengan 5000000.0 (float) yang keluar dari JSON, sehingga pemeriksaan ketat
    // selalu hijau dan tidak menguji apa pun.
    $ok('  ringkasan sumber lama tidak memuat angka buku besar',
        ! collect($d['ringkas'] ?? [])->contains(fn ($v) => is_numeric($v) && abs((float) $v - 5_000_000) < 0.01),
        'angka GL bocor ke ringkasan Kategori Invoice — uang yang sama terhitung dua kali');

    // Penjaga struktural: apa pun yang dipulangkan dataGl WAJIB berawalan 'gl'. Tanpa ini,
    // satu kunci yang bertabrakan dengan sumber lama akan menimpanya lewat array_merge —
    // dan angka yang tertimpa tidak memunculkan galat apa pun.
    $refGl = new ReflectionMethod(K::class, 'dataGl');
    $refGl->setAccessible(true);
    $kunciGl = array_keys($refGl->invoke(app(K::class), ['cogs' => 'tanah'], ['tahun' => null, 'search' => '']));
    $ok('  seluruh kunci prop blok buku besar berawalan "gl"',
        $kunciGl !== [] && collect($kunciGl)->every(fn ($k) => str_starts_with($k, 'gl')),
        'kunci: '.implode(', ', $kunciGl));

    $src = file_get_contents((new ReflectionClass(K::class))->getFileName());
    $ok('  dataGl dipanggil BERDAMPINGAN, bukan menggantikan',
        str_contains($src, '$this->dataGl($def, $filters)') && str_contains($src, "\$def['sumber'] === 'ap' ? \$this->dataAp"));

    echo "\n=== 6. Baris tanpa jejak tetap ditampilkan ===\n";

    // Jurnal memorial uji ini tidak punya dokumen ber-booking, jadi seluruhnya tanpa jejak.
    $ok('baris tanpa No. SPR tetap tampil', $baris->whereNull('spr')->count() === 3,
        'baris tak tertelusur dibuang — jumlah layar berhenti sama dengan mutasi akunnya');
    $ok('  jumlahnya dilaporkan ke layar', ($gl['tanpaJejak'] ?? null) === 3);
    $ok('  kolom yang diminta user semuanya ada pada barisnya',
        collect(['no', 'spr', 'customer', 'unit', 'lokasi', 'tahap', 'tanggal', 'masuk', 'keluar'])
            ->every(fn ($k) => array_key_exists($k, $baris->first())));

    echo "\n=== 7. Komponen lain tidak ikut terbawa ===\n";

    $lain = $props('license-permit');
    $ok('akun bertanda Tanah tidak muncul di License Permit',
        count($lain['glAkun'] ?? []) === 0 && count($lain['glBaris'] ?? []) === 0,
        'penyaring komponen tidak bekerja — seluruh akun bertanda muncul di semua layar');

    echo "\n=== 8. Perkabelan layar COA ===\n";

    $vue = file_get_contents(base_path('resources/js/Pages/BusinessSupport/Setup/Accounts.vue'));
    $ok('modal Edit Akun punya kontrol Komponen COGS',
        str_contains($vue, 'Komponen COGS') && str_contains($vue, 'v-model="form.cogs_component"'));
    // openEdit TIDAK memanggil form.reset() (beda dari openCreate/openCreateChild), jadi field
    // yang lupa ditimpa akan MEWARISI nilai akun yang dibuka sebelumnya dan ikut tersimpan.
    $openEdit = substr($vue, strpos($vue, 'function openEdit'), 900);
    $ok('  openEdit mengisi ulang nilainya (cegah nilai akun sebelumnya menempel)',
        str_contains($openEdit, 'form.cogs_component = a.cogs_component'),
        'komponen akun yang disunting sebelumnya akan tersimpan ke akun berikutnya');
    $ok('  nilai awalnya ada di useForm (supaya form.reset mengosongkannya)',
        str_contains($vue, 'cogs_component: null'));

    $ctlSrc = file_get_contents(base_path('app/Http/Controllers/Web/BusinessSupport/Setup/AccountController.php'));
    $ok('daftar akun ikut mengambil kolomnya',
        str_contains($ctlSrc, "'cost_center_id', 'cogs_component']"),
        'modal Edit selalu terbuka kosong lalu menimpanya dengan kosong');
    $ok('  daftar pilihan dikirim ke layar', str_contains($ctlSrc, 'cogsComponents'));
    $ok('  daftar pilihannya diambil dari satu tempat, bukan disalin',
        str_contains($ctlSrc, 'CogsComponentController::pilihanKomponen()'));

    $tuntas = true;
} catch (\Throwable $e) {
    $gagal++;
    echo "\n  GAGAL (galat tak tertangani) — ".get_class($e).': '.$e->getMessage()."\n".$e->getFile().':'.$e->getLine()."\n";
} finally {
    DB::rollBack();

    echo "\n────────────────────────────────\n";
    echo "LULUS: $lulus   GAGAL: $gagal\n";
    echo ($gagal === 0 && $tuntas) ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
    echo "(data uji dibatalkan)\n";
}
