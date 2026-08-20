<?php

declare(strict_types=1);

/**
 * Uji: impor Chart of Account (lampiran "COA Aktiva").
 *
 * Jalankan:  docker exec ipro-fo-app php artisan tinker /var/www/html/tests/Manual/uji-impor-coa.php
 *
 * Yang diperiksa:
 *   1. Seluruh 204 akun lampiran ada di bagan akun, persis nama/level/tipe/saldo normalnya.
 *   2. Rantai induk utuh dari level 1 sampai 6 — parent_id, bukan cuma awalan kode.
 *   3. Angka "180" pada kolom Tipe lampiran TIDAK pernah tersimpan sebagai tipe akun.
 *   4. Hanya level 6 yang bisa dijurnal.
 *   5. Berkas yang salah ditolak SELURUHNYA — tidak ada yang separuh tertulis.
 *   6. Impor ulang tidak menggandakan akun (idempoten).
 *   7. Berkas format unduhan memuat sepuluh kolom yang sama dengan yang dituntut pembaca.
 *
 * Semua yang menulis dibungkus transaksi lalu di-rollback.
 */

use App\Domain\BusinessSupport\Import\CoaImporter;
use App\Domain\BusinessSupport\Models\BsAccount;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$cek = function (string $nama, bool $ok, string $ket = '') use (&$lulus, &$gagal): void {
    if ($ok) {
        $lulus++;
        echo "  OK    {$nama}\n";
    } else {
        $gagal++;
        echo "  GAGAL {$nama}".($ket !== '' ? " — {$ket}" : '')."\n";
    }
};

$data = json_decode((string) file_get_contents(database_path('data/coa-aktiva.json')), true);
$importer = app(CoaImporter::class);

echo "\n=== 1. Seluruh akun lampiran ada dan sama persis ===\n";

$cek('berkas sumber berisi 204 akun', count($data) === 204, 'ada '.count($data));

$db = BsAccount::whereIn('code', array_column($data, 'code'))->get()->keyBy('code');
$cek('semuanya ada di bagan akun', $db->count() === count($data), $db->count().' dari '.count($data));

$beda = [];
foreach ($data as $a) {
    $x = $db->get((string) $a['code']);
    if (! $x) {
        $beda[] = $a['code'].' hilang';

        continue;
    }
    if ($x->name !== $a['name']) {
        $beda[] = $a['code'].' nama "'.$x->name.'" != "'.$a['name'].'"';
    }
    if ((int) $x->level !== (int) $a['level']) {
        $beda[] = $a['code'].' level '.$x->level.' != '.$a['level'];
    }
    if ($x->type !== $a['type']) {
        $beda[] = $a['code'].' tipe '.$x->type.' != '.$a['type'];
    }
    if ($x->normal_balance !== $a['normal_balance']) {
        $beda[] = $a['code'].' normal '.$x->normal_balance.' != '.$a['normal_balance'];
    }
}
$cek('tidak ada selisih dengan lampiran', $beda === [], implode('; ', array_slice($beda, 0, 5)));

$perLevel = collect($data)->countBy('level')->all();
ksort($perLevel);
$cek('sebaran level 1..6 = 1/3/13/23/23/141',
    $perLevel === [1 => 1, 2 => 3, 3 => 13, 4 => 23, 5 => 23, 6 => 141],
    json_encode($perLevel));

echo "\n=== 2. Rantai induk utuh ===\n";

$yatim = BsAccount::whereIn('code', array_column($data, 'code'))
    ->where('level', '>', 1)->whereNull('parent_id')->pluck('code')->all();
$cek('tidak ada akun non-level-1 tanpa parent_id', $yatim === [], implode(',', array_slice($yatim, 0, 5)));

$salahInduk = [];
foreach ($db as $x) {
    if ((int) $x->level === 1) {
        continue;
    }
    $induk = BsAccount::find($x->parent_id);
    if (! $induk) {
        $salahInduk[] = $x->code.' induk hilang';
    } elseif ((int) $induk->level !== (int) $x->level - 1) {
        $salahInduk[] = $x->code.' induk level '.$induk->level.', harusnya '.((int) $x->level - 1);
    } elseif (! str_starts_with((string) $x->code, (string) $induk->code)) {
        $salahInduk[] = $x->code.' bukan lanjutan kode induk '.$induk->code;
    }
}
$cek('induk selalu tepat satu tingkat di atas & kodenya melanjutkan',
    $salahInduk === [], implode('; ', array_slice($salahInduk, 0, 5)));

// Telusuri satu akun terdalam sampai ke puncak: ini yang membuktikan rantainya utuh
// sungguhan, bukan sekadar tiap baris punya induk.
$rantai = [];
$jalan = BsAccount::where('code', '10101010101')->first();
while ($jalan) {
    $rantai[] = $jalan->code;
    $jalan = $jalan->parent_id ? BsAccount::find($jalan->parent_id) : null;
}
$cek('KAS OPERASIONAL tertelusur sampai ASET (6 tingkat)',
    $rantai === ['10101010101', '101010101', '1010101', '10101', '101', '1'],
    implode(' > ', $rantai));

echo "\n=== 2b. Saldo normal akun kontra-aset ===\n";

// Akumulasi penyusutan & amortisasi mengurangi aset, jadi lazimnya bersaldo KREDIT walaupun
// bercabang dari ASET yang bersaldo debit. Kolom ini memberi TANDA pada Neraca Komparasi,
// Neraca Multi Periode, dan pembandingan budget vs realisasi — salah arah di sini membuat
// angkanya terbaca terbalik, bukan sekadar salah label.
$kontra = BsAccount::whereIn('code', [
    '10203', '1020303', '102030303', '10203030301', '10203030302', '10203030303', '10203030304',
    '10204', '1020404', '102040404', '10204040401', '10204040402', '10204040403',
])->get()->keyBy('code');

$cek('ketigabelas akun akumulasi ada semua', $kontra->count() === 13, (string) $kontra->count());
$salahArah = $kontra->filter(fn ($a) => $a->normal_balance !== 'K')->pluck('code')->all();
$cek('seluruh cabang akumulasi bersaldo normal K — daun maupun headernya',
    $salahArah === [], implode(',', $salahArah));

// Kepala cabang yang isinya bercampur harus TETAP debit: nilai bersih aset tetap (harga
// perolehan dikurangi akumulasi penyusutan) memang bersaldo debit.
foreach (['1' => 'ASET', '102' => 'ASET TETAP'] as $kode => $nama) {
    $a = BsAccount::where('code', $kode)->first();
    $cek("{$kode} {$nama} tetap bersaldo normal D", $a?->normal_balance === 'D', $a?->normal_balance ?? '-');
}

$kMenurutBerkas = collect($data)->where('normal_balance', 'K')->pluck('code')->sort()->values()->all();
$cek('berkas sumber menandai tepat 13 akun sebagai K', count($kMenurutBerkas) === 13, (string) count($kMenurutBerkas));

$kDiDb = BsAccount::whereIn('code', array_column($data, 'code'))
    ->where('normal_balance', 'K')->pluck('code')->sort()->values()->all();
$cek('akun bersaldo K di bagan akun sama persis dengan berkas', $kDiDb === $kMenurutBerkas,
    implode(',', array_diff($kDiDb, $kMenurutBerkas)).' / '.implode(',', array_diff($kMenurutBerkas, $kDiDb)));

// Aturan penurunannya: berkas yang HANYA menyebut K pada baris level 6 tetap harus
// menghasilkan header K juga — itulah yang membuat unggahan lampiran mentah lewat layar
// memberi hasil yang sama dengan berkas data yang sudah terselesaikan.
$mentah = [
    ['3', '10203', 'AKUMULASI PENYUSUTAN ASET TETAP BERWUJUD', 'Ledger', '180', '', '', '', '', ''],
    ['4', '1020303', 'AKUMULASI PENYUSUTAN ASET TETAP BERWUJUD', 'Detil', '180', '', '', '', '', ''],
    ['5', '102030303', 'AKUMULASI PENYUSUTAN ASET TETAP BERWUJUD', 'Detil Transaksi', '180', '', '', '', '', ''],
    ['6', '10203030301', 'AKUMULASI PENYUSUTAN BANGUNAN', 'Detil Sub Transaksi', 'Aktiva', 'K', '√', '', '', ''],
    ['6', '10203030302', 'AKUMULASI PENYUSUTAN KENDARAAN', 'Detil Sub Transaksi', 'Aktiva', 'K', '√', '', '', ''],
];
$hMentah = $importer->baca($mentah);
$arah = collect($hMentah['akun'])->pluck('normal_balance', 'code')->all();
$cek('header ikut anaknya saat kolom Normal-nya dikosongkan',
    $hMentah['galat'] === [] && $arah === [
        '10203' => 'K', '1020303' => 'K', '102030303' => 'K',
        '10203030301' => 'K', '10203030302' => 'K',
    ], json_encode($arah).' galat='.json_encode($hMentah['galat']));

// Anak yang campur arah TIDAK boleh menyeret induknya.
$campur = $importer->baca([
    ['5', '102010101', 'HARGA PEROLEHAN', 'Detil Transaksi', '180', '', '', '', '', ''],
    ['6', '10201010101', 'TANAH', 'Detil Sub Transaksi', 'Aktiva', 'D', '√', '', '', ''],
    ['6', '10201010102', 'UJI KONTRA', 'Detil Sub Transaksi', 'Aktiva', 'K', '√', '', '', ''],
]);
$arahCampur = collect($campur['akun'])->pluck('normal_balance', 'code')->all();
// Sengaja TIDAK memakai ?? di sini: nilainya memang null, dan ?? akan menyamakan "bernilai
// null" dengan "kuncinya tidak ada" sehingga pemeriksaannya jadi tidak berarti.
$cek('induk beranak campur tidak ikut salah satu anaknya',
    array_key_exists('102010101', $arahCampur) && $arahCampur['102010101'] === null,
    json_encode($arahCampur));

// Yang ditinggal kosong itu diselesaikan tulis() dari induk yang sudah ada di bagan akun —
// bukan dibiarkan kosong sampai ke database.
DB::beginTransaction();
try {
    $importer->tulis($campur['akun']);
    $indukDb = BsAccount::where('code', '1020101')->value('normal_balance');
    $cek('tulis() mengisinya dari induk yang sudah ada di bagan akun',
        BsAccount::where('code', '102010101')->value('normal_balance') === $indukDb,
        'induk '.$indukDb.' vs '.BsAccount::where('code', '102010101')->value('normal_balance'));
} finally {
    DB::rollBack();
}

// Baris yang menyebutkan arahnya sendiri tidak boleh ditimpa aturan penurunan.
$eksplisit = $importer->baca([
    ['5', '102030303', 'AKUMULASI PENYUSUTAN ASET TETAP BERWUJUD', 'Detil Transaksi', '', 'D', '', '', '', ''],
    ['6', '10203030301', 'AKUMULASI PENYUSUTAN BANGUNAN', 'Detil Sub Transaksi', 'Aktiva', 'K', '√', '', '', ''],
]);
$cek('arah yang ditulis operator tidak ditimpa turunan anaknya',
    collect($eksplisit['akun'])->firstWhere('code', '102030303')['normal_balance'] === 'D');

echo "\n=== 3. Angka format \"180\" tidak pernah jadi tipe ===\n";

$tipe = BsAccount::whereIn('code', array_column($data, 'code'))->distinct()->pluck('type')->all();
$cek('tipe akun hanya "asset"', $tipe === ['asset'], implode(',', $tipe));
$cek('tidak ada akun bertipe "180"',
    BsAccount::where('type', '180')->count() === 0);

echo "\n=== 4. Hanya level 6 yang bisa dijurnal ===\n";

$salahPostable = BsAccount::whereIn('code', array_column($data, 'code'))
    ->where(fn ($q) => $q->where(fn ($w) => $w->where('level', 6)->where('is_postable', false))
        ->orWhere(fn ($w) => $w->where('level', '<', 6)->where('is_postable', true)))
    ->pluck('code')->all();
$cek('postable persis mengikuti level 6', $salahPostable === [], implode(',', array_slice($salahPostable, 0, 5)));
$cek('jumlah akun postable dari lampiran = 141',
    BsAccount::whereIn('code', array_column($data, 'code'))->where('is_postable', true)->count() === 141);

echo "\n=== 5. Berkas salah ditolak seluruhnya ===\n";

$sebelum = BsAccount::count();
$berkasSalah = [
    ['6', '99999999999', 'AKUN UJI SATU', 'Detil Sub Transaksi', 'Aktiva', 'D', '√', '', '', ''],
    ['6', '888', 'PANJANG KODE SALAH', 'Detil Sub Transaksi', 'Aktiva', 'D', '√', '', '', ''],
];
$hasil = $importer->baca($berkasSalah);
$cek('baris berkode terlalu pendek ditolak', count($hasil['galat']) >= 1, json_encode($hasil['galat']));
$cek('baris yatim juga dilaporkan',
    (bool) collect($hasil['galat'])->first(fn ($g) => str_contains($g, 'induknya')),
    json_encode($hasil['galat']));
$cek('jumlah akun tidak berubah karena berkas ditolak', BsAccount::count() === $sebelum);

$galatLevel = $importer->baca([['9', '1', 'X', '', '', '', '', '', '', '']])['galat'];
$cek('level di luar 1..6 ditolak', (bool) collect($galatLevel)->first(fn ($g) => str_contains($g, 'harus 1 sampai 6')));

$galatKembar = $importer->baca([
    ['1', '9', 'PUNCAK UJI', 'Kategori', '', '', '', '', '', ''],
    ['1', '9', 'PUNCAK UJI LAGI', 'Kategori', '', '', '', '', '', ''],
])['galat'];
$cek('kode kembar di satu berkas ditolak',
    (bool) collect($galatKembar)->first(fn ($g) => str_contains($g, 'dua kali')));

$galatNamaLevel = $importer->baca([['1', '9', 'PUNCAK UJI', 'Ledger', '', '', '', '', '', '']])['galat'];
$cek('nama level yang tidak cocok ditolak',
    (bool) collect($galatNamaLevel)->first(fn ($g) => str_contains($g, 'tidak cocok')));

echo "\n=== 6. Impor ulang tidak menggandakan ===\n";

DB::beginTransaction();
try {
    $sebelum = BsAccount::count();
    $baris = array_map(fn ($a) => [
        (string) $a['level'], (string) $a['code'], (string) $a['name'],
        CoaImporter::LEVEL[(int) $a['level']], 'Aktiva', (string) $a['normal_balance'],
        $a['level'] === 6 ? '√' : '', '', '', '',
    ], $data);
    $ulang = $importer->tulis($importer->baca($baris)['akun']);
    $cek('impor ulang: 0 baru, 204 diperbarui',
        $ulang['baru'] === 0 && $ulang['ubah'] === 204, json_encode($ulang));
    $cek('jumlah akun tidak bertambah', BsAccount::count() === $sebelum,
        $sebelum.' -> '.BsAccount::count());

    // Akun baru betul-betul lahir lengkap dengan induknya.
    $baru = $importer->baca([
        ['1', '9', 'PUNCAK UJI', 'Kategori', '', '', '', '', '', ''],
        ['2', '901', 'CABANG UJI', 'Sub Kategori', 'Aktiva', 'D', '', '', '', ''],
    ]);
    $cek('berkas berisi induk + anak lolos pemeriksaan', $baru['galat'] === [], json_encode($baru['galat']));
    $t = $importer->tulis($baru['akun']);
    $anak = BsAccount::where('code', '901')->first();
    $cek('anak yang baru dibuat langsung tertaut ke induknya',
        $t['baru'] === 2 && $anak?->parent_code === '9'
        && $anak->parent_id === BsAccount::where('code', '9')->value('id'),
        json_encode($t).' parent='.($anak->parent_code ?? '-'));
    $cek('akun level 2 tidak bisa dijurnal', $anak && ! $anak->is_postable);
} finally {
    DB::rollBack();
}
$cek('rollback bersih — akun uji tidak tertinggal',
    BsAccount::whereIn('code', ['9', '901'])->count() === 0);

echo "\n=== 7. Format unduhan sepadan dengan pembacanya ===\n";

$cek('kolom format = 10 kolom lampiran', CoaImporter::KOLOM === [
    'level', 'kode', 'akun', 'nama_level', 'tipe', 'normal', 'postable',
    'cost_center', 'cash_flow_direct', 'cash_flow_indirect',
], implode(',', CoaImporter::KOLOM));

// Baris judul pada berkas unduhan harus DIBUANG oleh pembacanya, kalau tidak baris judul
// itu sendiri akan dianggap akun dan impor gagal di baris pertama.
$csv = "\u{FEFF}".implode(',', CoaImporter::KOLOM)."\n6,10101010101,KAS OPERASIONAL,Detil Sub Transaksi,Aktiva,D,,,,\n";
$terbaca = $importer->csv($csv);
$cek('BOM & baris judul dibuang', count($terbaca) === 1 && $terbaca[0][1] === '10101010101',
    json_encode($terbaca));
$cek('baris judul tidak lolos sebagai akun', $importer->baca($terbaca)['galat'] === []);

$cek('nama tingkatan importer = nama tingkatan model',
    CoaImporter::LEVEL === BsAccount::LEVELS, json_encode(CoaImporter::LEVEL));

echo "\n=== 8. Jalur layar: unduh format lalu unggah balik ===\n";

// Yang diuji di sini adalah jalur yang benar-benar dipakai operator: berkas contoh dari
// tombol "Unduh Format" harus bisa diunggah balik apa adanya. Kalau contoh bawaannya
// sendiri ditolak, operator akan menyangka formatnyalah yang salah.
$user = App\Models\User::whereHas('roles')->first() ?: App\Models\User::first();
auth()->setUser($user);
$req = Illuminate\Http\Request::create('/business-support/gl/accounts/format-impor', 'GET');
$req->setUserResolver(fn () => $user);
$ctl = app(App\Http\Controllers\Web\BusinessSupport\Setup\AccountController::class);
ob_start();
$ctl->template($req)->sendContent();
$format = ob_get_clean();

$cek('format berawalan BOM', str_starts_with($format, chr(0xEF).chr(0xBB).chr(0xBF)));
$cek('baris judulnya sepuluh kolom', str_contains($format, implode(',', CoaImporter::KOLOM)));

$isiFormat = $importer->csv($format);
$cek('contoh memuat satu baris tiap level 1..6',
    array_map(fn ($b) => (int) $b[0], $isiFormat) === [1, 2, 3, 4, 5, 6],
    json_encode(array_map(fn ($b) => $b[0], $isiFormat)));

$hFormat = $importer->baca($isiFormat);
$cek('format bawaan lolos pemeriksaan tanpa disunting', $hFormat['galat'] === [],
    json_encode($hFormat['galat']));
$cek('tipe tertulis dengan kata Indonesia (Aktiva)', str_contains($format, 'Aktiva'));
$cek('tanda centang tercetak, bukan kode pelariannya',
    str_contains($format, '√') && ! str_contains($format, 'u221a'));

DB::beginTransaction();
try {
    $sebelum = BsAccount::count();
    $importer->tulis($hFormat['akun']);
    $cek('unggah balik format bawaan tidak menambah akun', BsAccount::count() === $sebelum,
        $sebelum.' -> '.BsAccount::count());
} finally {
    DB::rollBack();
}

echo "\n==================================================\n";
echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
echo "==================================================\n";
