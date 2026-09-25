<?php

/**
 * "Kontrak SPK (MUK)" pindah dari Account Payable → Posting Invoice SPK
 * ke Contractor Management → Master, tepat DI ATAS "Surat Perintah Kerja".
 *
 * Perpindahan menu terdengar sepele, tetapi yang satu ini menyeberangi BATAS IZIN dan di
 * situlah seluruh bahayanya:
 *
 *   MukKontrakController menuntut izin 'ap.posting.view' (baris 33 & 77), sedangkan grup
 *   "Master" di Contractor Management dijaga izin contractor.*. Memindahkannya begitu saja
 *   melukai DUA golongan sekaligus, dan keduanya tidak memunculkan galat di layar:
 *     - pemegang izin contractor MELIHAT menunya lalu ditolak 403 saat mengklik;
 *     - pemegang izin ap.posting yang selama ini memakainya KEHILANGAN menunya sama sekali,
 *       sebab grup Master tidak pernah dirender untuk mereka.
 *
 * Karena itu yang dijaga di sini:
 *  1. Butir menunya menyebut izinnya SENDIRI ('ap.posting.*'), bukan mewarisi izin grup.
 *  2. Grup Master ikut menerima 'ap.posting.*', supaya akses lama tidak hilang.
 *  3. Penjaga di controller TIDAK dilonggarkan — perpindahan menu tidak boleh diam-diam
 *     menjadi pelebaran hak akses.
 *  4. Letaknya benar-benar DI ATAS "Surat Perintah Kerja", dan sudah tidak ada lagi di
 *     Account Payable.
 *  5. Rute & layarnya TIDAK ikut pindah (yang berpindah hanya tempatnya di menu), dan kedua
 *     hub modul ikut disesuaikan — hub adalah pintu masuk kedua yang mudah terlupa.
 *  6. Tautan "kembali" pada layarnya tidak lagi menunjuk Account Payable. Dibiarkan, menekan
 *     kembali mendaratkan operator di modul yang menunya sudah tidak memuat layar ini.
 *
 * Tidak menyentuh basis data sama sekali.
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-kontrak-muk-pindah-modul.php';"
 */

$lulus = 0;
$gagal = 0;
$tuntas = false;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

try {
    $nav = file_get_contents(base_path('resources/js/Components/Layout/navGroups.ts'));
    $hubAp = file_get_contents(base_path('resources/js/Pages/BusinessSupport/Payable/Hub.vue'));
    $hubCon = file_get_contents(base_path('resources/js/Pages/BusinessSupport/Contractor/Hub.vue'));
    $layar = file_get_contents(base_path('resources/js/Pages/BusinessSupport/Payable/MukKontrak/Index.vue'));
    $ctl = file_get_contents(base_path('app/Http/Controllers/Web/BusinessSupport/Payable/MukKontrakController.php'));

    echo "\n=== 1. Sudah tidak ada di Account Payable ===\n";

    // Potongan grup "Posting Invoice SPK" saja — mencari di seluruh berkas akan menemukan
    // entri barunya di Contractor Management dan pemeriksaannya jadi selalu hijau.
    $awal = strpos($nav, "label: 'Posting Invoice SPK', icon:");
    $grupAp = substr($nav, $awal, strpos($nav, ']},', $awal) - $awal);
    $ok('grup "Posting Invoice SPK" tidak lagi memuatnya',
        $awal !== false && ! str_contains($grupAp, "label: 'Kontrak SPK (MUK)'"),
        'menunya kembar: muncul di dua modul sekaligus');
    $ok('  grup itu tetap memuat "Posting Invoice SPK" sendiri',
        str_contains($grupAp, "href: 'bs.ap.posting.spk.index'"),
        'grup jadi kosong — modul Account Payable kehilangan menu yang tidak diminta pindah');
    $ok('hub Account Payable tidak lagi mendaftarkannya',
        ! str_contains($hubAp, 'Kontrak SPK (MUK)'),
        'hub adalah pintu masuk kedua — kalau tertinggal, menunya seolah tidak pernah pindah');

    echo "\n=== 2. Sudah ada di Contractor Management → Master, DI ATAS Surat Perintah Kerja ===\n";

    $awalM = strpos($nav, "label: 'Master', icon: FileSignature");
    $grupMaster = substr($nav, $awalM, strpos($nav, ']},', $awalM) - $awalM);
    $posMuk = strpos($grupMaster, "label: 'Kontrak SPK (MUK)'");
    $posSpk = strpos($grupMaster, "label: 'Surat Perintah Kerja'");
    $ok('grup Master memuatnya', $awalM !== false && $posMuk !== false);
    $ok('  letaknya DI ATAS "Surat Perintah Kerja"',
        $posMuk !== false && $posSpk !== false && $posMuk < $posSpk,
        'urutannya terbalik dari yang diminta');
    $ok('  menunjuk rute yang sama seperti sebelumnya',
        str_contains($grupMaster, "href: 'bs.muk.kontrak.index'"));

    $posHubMuk = strpos($hubCon, 'Kontrak SPK (MUK)');
    $posHubSpk = strpos($hubCon, "label: 'Surat Perintah Kerja'");
    $ok('hub Contractor Management memuatnya', $posHubMuk !== false);
    $ok('  juga di atas Surat Perintah Kerja', $posHubMuk !== false && $posHubSpk !== false && $posHubMuk < $posHubSpk);

    echo "\n=== 3. Izin: tidak ada yang kehilangan akses, tidak ada yang ditawari 403 ===\n";

    // Butirnya WAJIB menyebut izinnya sendiri. Tanpa itu ia mewarisi izin grup (contractor.*),
    // dan pemegang izin contractor akan melihat menu yang berakhir 403.
    $ok('butir menunya menyebut izin ap.posting.* sendiri',
        (bool) preg_match("/label: 'Kontrak SPK \(MUK\)'[^}]*permission: \[[^\]]*'ap\.posting\.\*'/", $grupMaster),
        'butir tanpa izin sendiri ditampilkan ke pemegang izin contractor, lalu ditolak 403');
    // Grup Master WAJIB ikut menerima ap.posting.*, kalau tidak pemegang izin AP kehilangan
    // menunya sama sekali — grupnya tidak pernah dirender untuk mereka.
    $ok('grup Master ikut menerima ap.posting.*',
        (bool) preg_match("/label: 'Master', icon: FileSignature, permission: \[[^\]]*'ap\.posting\.\*'/", $nav),
        'pengguna yang selama ini memakainya dari Account Payable kehilangan menunya');
    $ok('  izin contractor yang lama TETAP ada di grup itu',
        str_contains($grupMaster, "'contractor.spk.*'") || str_contains(substr($nav, $awalM - 200, 400), "'contractor.spk.*'"),
        'izin contractor terhapus — tim contractor kehilangan seluruh grup Master');

    echo "\n=== 4. Hak akses TIDAK dilebarkan ===\n";

    $ok('controller tetap menuntut ap.posting.view',
        substr_count($ctl, "can('ap.posting.view')") === 2,
        'perpindahan menu diam-diam menjadi pelebaran hak akses');
    $ok('  tidak ada izin contractor yang disisipkan ke controller',
        ! str_contains($ctl, 'contractor.'),
        'penjaga dilonggarkan supaya menunya "jalan" — itu membuka layar ke peran yang tidak berhak');

    echo "\n=== 5. Rute & layar tidak ikut pindah ===\n";

    $rute = app('router')->getRoutes()->getByName('bs.muk.kontrak.index');
    $ok('rute bs.muk.kontrak.index masih terdaftar', $rute !== null);
    $ok('  masih menunjuk MukKontrakController',
        $rute !== null && str_contains((string) $rute->getActionName(), 'MukKontrakController'));
    $ok('  rute rinciannya juga utuh',
        app('router')->getRoutes()->getByName('bs.muk.kontrak.show') !== null);
    $ok('berkas layarnya tetap di tempat semula',
        is_file(base_path('resources/js/Pages/BusinessSupport/Payable/MukKontrak/Index.vue')));

    echo "\n=== 6. Tautan kembali ikut pindah ===\n";

    $ok('tautan kembali tidak lagi ke Account Payable',
        ! str_contains($layar, "route('bs.ap.index')"),
        'menekan kembali mendaratkan operator di modul yang menunya sudah tidak memuat layar ini');
    $ok('  mengarah ke Contractor Management', str_contains($layar, "route('bs.spk.index')")
        && str_contains($layar, 'Contractor Management'));

    $tuntas = true;
} catch (\Throwable $e) {
    $gagal++;
    echo "\n  GAGAL (galat tak tertangani) — ".get_class($e).': '.$e->getMessage()."\n";
} finally {
    echo "\n────────────────────────────────\n";
    echo "LULUS: $lulus   GAGAL: $gagal\n";
    echo ($gagal === 0 && $tuntas) ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
}
