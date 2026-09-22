<?php

declare(strict_types=1);

/**
 * Uji: jembatan modul Legal → SPR (Final) di FO → Detail AJB di ipro_bo.
 *
 * Akta AJB diarsipkan di modul Legal lalu ditautkan ke sebuah No. SPR (Final).
 * Modul Legal tidak boleh menyentuh MySQL, jadi FO yang mengerjakan keduanya:
 * memvalidasi SPR-nya dan menulis baris ipro_bo.ajb_documents yang mengisi layar
 * "Detail AJB". Yang dijaga di sini adalah hal-hal yang, bila salah, TIDAK akan
 * memunculkan galat apa pun di layar mana pun:
 *
 *  1. SPR yang belum final / batal / tidak ada ditolak dengan pesan yang bisa
 *     ditindaklanjuti — bukan 500 dari MySQL dan bukan diterima diam-diam.
 *  2. Booking ipro_bo DIBUAT SENDIRI bila sinkron masternya belum sampai.
 *     Tanpa ini penautan gagal untuk setiap SPR yang belum pernah disinkron —
 *     dan di lingkungan ini tabel bookings memang masih kosong.
 *  3. Diulang tidak menggandakan; satu SPR = satu baris.
 *  4. Memindahkan akta ke SPR lain MELEPAS baris lamanya. Kalau tidak, akta yang
 *     sama muncul di dua baris SPR dan dua-duanya terbaca "SUDAH BAST".
 *  5. Nomor akta yang bentrok dengan akta lain ditolak — TAPI akta yang bentrok
 *     dengan dirinya sendiri (baris lama saat pindah SPR) tidak boleh ikut ditolak.
 *  6. Satu SPR tidak bisa diklaim dua dokumen Legal sekaligus.
 *  7. Melepas tautan hanya menghapus baris milik Legal; akta yang diterbitkan di
 *     BO lewat "Input Massal AJB" tidak boleh ikut terhapus dari modul lain.
 *
 * Tulisan FO dibungkus transaksi lalu di-rollback. Baris di ipro_bo TIDAK ikut
 * ter-rollback (beda koneksi) sehingga dihapus sendiri di blok finally.
 *
 * Jalankan (butuh container yang membawa kode FO terbaru + akses ke ipro_bo):
 *   docker exec -e DB_DATABASE=jerp_verify jerp-verify php artisan tinker /var/www/html/tests/Manual/uji-legal-ajb-jembatan.php
 */

use App\Modules\BO\IproBridge\Services\LegalAjbBridge;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$cek = function (string $nama, bool $ok, string $ket = '') use (&$lulus, &$gagal): void {
    if ($ok) { $lulus++; echo "  OK    {$nama}\n"; }
    else { $gagal++; echo "  GAGAL {$nama}".($ket !== '' ? " — {$ket}" : '')."\n"; }
};

$bo = DB::connection((string) config('ipro.connection', 'ipro_bo'));
$tenant = (int) config('ipro.tenant_id', 1);
$projectId = (int) config('ipro.project_id', 1);
$jembatan = app(LegalAjbBridge::class);

$TAG = 'ZUJB'.strtoupper(substr((string) getmypid(), -3));
$sprFinal = 'UJI-'.$TAG.'-FINAL';
$sprFinal2 = 'UJI-'.$TAG.'-FINAL2';
$sprSprs = 'UJI-'.$TAG.'-SPRS';
$sprBatal = 'UJI-'.$TAG.'-BATAL';
$docLegal = 'cklegal'.$TAG;
$docLegal2 = 'cklegal2'.$TAG;

/** Baris ajb_documents untuk sebuah No. SPR (lewat bookings.booking_number). */
$aktaSpr = function (string $spr) use ($bo, $tenant) {
    $bid = $bo->table('bookings')->where('tenant_id', $tenant)->where('booking_number', $spr)->value('id');

    return $bid ? $bo->table('ajb_documents')->where('booking_id', $bid)->first() : null;
};

echo "\n=== 0. Prasyarat ===\n";
$cek('ipro_bo.ajb_documents sudah punya kolom source',
    Schema::connection((string) config('ipro.connection', 'ipro_bo'))->hasColumn('ajb_documents', 'source'));
$cek('  dan kolom legal_doc_id',
    Schema::connection((string) config('ipro.connection', 'ipro_bo'))->hasColumn('ajb_documents', 'legal_doc_id'));

DB::beginTransaction();

try {
    // ── Rantai data FO: customer → unit → kontrak (SPR) ──
    $custId = (int) DB::table('fo_customers')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $projectId, 'code' => 'CUST-'.$TAG,
        'full_name' => 'Pembeli Uji Jembatan', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $unitId = (int) DB::table('fo_units')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $projectId, 'kode_unit' => 'UNIT-'.$TAG,
        'cluster' => 'Cluster Uji', 'blok' => 'A', 'no_unit' => '12',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Pasangannya di ipro_bo — IproMasterSync mencocokkan customer lewat `code`
    // dan unit lewat `unit_code`; tanpa keduanya syncSpr pulang null.
    $iproCust = (int) $bo->table('customers')->insertGetId([
        'tenant_id' => $tenant, 'code' => 'CUST-'.$TAG, 'full_name' => 'Pembeli Uji Jembatan',
        'id_type' => 'ktp', 'id_number' => encrypt('3515000000000001'),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $iproUnit = (int) $bo->table('units')->insertGetId([
        'tenant_id' => $tenant, 'project_id' => $projectId, 'unit_code' => 'UNIT-'.$TAG,
        'base_price' => 500000000, 'current_price' => 501000000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $buatKontrak = function (string $nomor, string $tahap, string $status) use ($tenant, $custId, $unitId) {
        return (int) DB::table('fo_kontrak')->insertGetId([
            'tenant_id' => $tenant, 'no_kontrak' => $nomor, 'customer_id' => $custId, 'unit_id' => $unitId,
            'tgl_kontrak' => now()->toDateString(), 'harga_jual' => 500000000, 'total_kontrak' => 501000000,
            'cara_bayar' => 'KPR', 'status' => $status, 'tahap' => $tahap,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $buatKontrak($sprFinal, 'SPR', 'AKTIF');
    $buatKontrak($sprFinal2, 'SPR', 'AKTIF');
    $buatKontrak($sprSprs, 'SPRS', 'AKTIF');
    $buatKontrak($sprBatal, 'SPR', 'BATAL');

    echo "\n=== 1. Pencarian SPR untuk penaut di modul Legal ===\n";

    $hasil = $jembatan->cariSpr($TAG, 50);
    $nomor = array_column($hasil, 'spr');
    $cek('SPR Final ikut terdaftar', in_array($sprFinal, $nomor, true), json_encode($nomor));
    $cek('  SPRS (belum final) TIDAK terdaftar', ! in_array($sprSprs, $nomor, true));
    $cek('  SPR BATAL TIDAK terdaftar', ! in_array($sprBatal, $nomor, true));

    $satu = collect($hasil)->firstWhere('spr', $sprFinal);
    $cek('  membawa nama pembeli', ($satu['customer'] ?? null) === 'Pembeli Uji Jembatan');
    $cek('  membawa kode unit', ($satu['unit'] ?? null) === 'UNIT-'.$TAG);
    // Cluster/blok/no_unit dipakai mengisi kolom unit di formulir Legal; tanpanya
    // petugas harus mengetik ulang dan data unit di dua modul bisa berbeda.
    $cek('  membawa cluster/blok/no unit', ($satu['cluster'] ?? null) === 'Cluster Uji'
        && ($satu['blok'] ?? null) === 'A' && ($satu['no_unit'] ?? null) === '12');
    $cek('  membawa nilai kontrak', (float) ($satu['total'] ?? 0) === 501000000.0);

    $cariNama = array_column($jembatan->cariSpr('Pembeli Uji Jembatan', 50), 'spr');
    $cek('  bisa dicari lewat nama pembeli', in_array($sprFinal, $cariNama, true));
    $cariUnit = array_column($jembatan->cariSpr('UNIT-'.$TAG, 50), 'spr');
    $cek('  bisa dicari lewat kode unit', in_array($sprFinal, $cariUnit, true));

    echo "\n=== 2. SPR yang tidak layak ditolak dengan alasan, bukan 500 ===\n";

    $dasar = ['legal_doc_id' => $docLegal, 'ajb_number' => '112/AJB/V/2026-'.$TAG,
        'signed_at' => '2026-05-18', 'notary_name' => 'Rina Sari, S.H., M.Kn.', 'amount' => 501000000];

    $r = $jembatan->simpan(array_merge($dasar, ['spr' => 'SPR-TIDAK-ADA-'.$TAG]));
    $cek('SPR tidak ada ditolak', ($r['ok'] ?? true) === false && ($r['kode'] ?? '') === 'SPR_TIDAK_DITEMUKAN', json_encode($r));
    $r = $jembatan->simpan(array_merge($dasar, ['spr' => $sprSprs]));
    $cek('SPR masih SPRS ditolak', ($r['kode'] ?? '') === 'SPR_BELUM_FINAL', json_encode($r));
    $r = $jembatan->simpan(array_merge($dasar, ['spr' => $sprBatal]));
    $cek('SPR batal ditolak', ($r['kode'] ?? '') === 'SPR_BATAL', json_encode($r));
    $r = $jembatan->simpan(['spr' => $sprFinal, 'legal_doc_id' => $docLegal]);
    $cek('data kurang ditolak', ($r['kode'] ?? '') === 'DATA_KURANG', json_encode($r));
    $r = $jembatan->simpan(array_merge($dasar, ['spr' => $sprFinal, 'signed_at' => 'bukan-tanggal']));
    $cek('tanggal tak terbaca ditolak', ($r['kode'] ?? '') === 'TANGGAL_INVALID', json_encode($r));
    $r = $jembatan->simpan(array_merge($dasar, ['spr' => $sprFinal, 'amount' => -5]));
    $cek('nominal negatif ditolak', ($r['kode'] ?? '') === 'NOMINAL_INVALID', json_encode($r));
    $cek('  tidak satu pun penolakan meninggalkan baris', $aktaSpr($sprFinal) === null);

    echo "\n=== 3. Penautan berhasil — booking dibuatkan bila belum ada ===\n";

    $cek('booking SPR ini memang belum ada di ipro_bo',
        $bo->table('bookings')->where('booking_number', $sprFinal)->count() === 0);

    $r = $jembatan->simpan(array_merge($dasar, ['spr' => $sprFinal]));
    $cek('dorongan diterima', ($r['ok'] ?? false) === true, json_encode($r));
    $cek('  booking-nya dibuat otomatis', $bo->table('bookings')->where('booking_number', $sprFinal)->count() === 1);

    $akta = $aktaSpr($sprFinal);
    $cek('  baris akta terbentuk', $akta !== null);
    $cek('  nomor akta tersimpan', ($akta->ajb_number ?? '') === '112/AJB/V/2026-'.$TAG);
    $cek('  tanggal akta tersimpan', str_starts_with((string) ($akta->signed_at ?? ''), '2026-05-18'));
    $cek('  notaris tersimpan', ($akta->notary_name ?? '') === 'Rina Sari, S.H., M.Kn.');
    $cek('  nominal tersimpan', (float) ($akta->amount ?? 0) === 501000000.0);
    // Inilah yang membuat layar Detail AJB menampilkan kolomnya tanpa menunggu BAST.
    $cek('  ditandai sumber legal', ($akta->source ?? '') === 'legal', (string) ($akta->source ?? '-'));
    $cek('  membawa id dokumen Legal', ($akta->legal_doc_id ?? '') === $docLegal);
    $cek('  stempel sinkron terisi', ($akta->legal_synced_at ?? null) !== null);

    echo "\n=== 4. Diulang tidak menggandakan ===\n";

    for ($i = 0; $i < 3; $i++) { $jembatan->simpan(array_merge($dasar, ['spr' => $sprFinal])); }
    $bid = (int) $bo->table('bookings')->where('booking_number', $sprFinal)->value('id');
    $cek('tiga kali dorong tetap satu baris akta',
        $bo->table('ajb_documents')->where('booking_id', $bid)->count() === 1);

    // Perubahan di modul Legal harus MENYUSUL, bukan mengendap di baris pertama.
    $jembatan->simpan(array_merge($dasar, ['spr' => $sprFinal, 'notary_name' => 'Notaris Berubah', 'amount' => 499000000]));
    $akta = $aktaSpr($sprFinal);
    $cek('  perubahan notaris menyusul', ($akta->notary_name ?? '') === 'Notaris Berubah');
    $cek('  perubahan nominal menyusul', (float) ($akta->amount ?? 0) === 499000000.0);

    echo "\n=== 5. Pindah SPR melepas baris lama ===\n";

    // Nomor aktanya SENGAJA tidak diubah: baris lama memegang nomor yang sama, dan
    // pemeriksaan bentrok tidak boleh menganggap akta ini bentrok dengan dirinya
    // sendiri. Tanpa pengecualian itu, memindahkan akta ke SPR lain mustahil.
    $r = $jembatan->simpan(array_merge($dasar, ['spr' => $sprFinal2, 'notary_name' => 'Notaris Berubah', 'amount' => 499000000]));
    $cek('pindah ke SPR lain diterima (bukan dituduh nomor bentrok)', ($r['ok'] ?? false) === true, json_encode($r));
    $cek('  baris di SPR lama dilepas', $aktaSpr($sprFinal) === null);
    $cek('  baris di SPR baru ada', $aktaSpr($sprFinal2) !== null);
    $cek('  totalnya tetap satu baris untuk dokumen ini',
        $bo->table('ajb_documents')->where('legal_doc_id', $docLegal)->count() === 1);

    // Kembalikan ke SPR pertama untuk pengujian berikutnya.
    $jembatan->simpan(array_merge($dasar, ['spr' => $sprFinal]));

    echo "\n=== 6. Satu SPR tidak bisa diklaim dua dokumen Legal ===\n";

    $r = $jembatan->simpan([
        'legal_doc_id' => $docLegal2, 'spr' => $sprFinal, 'ajb_number' => '999/AJB/V/2026-'.$TAG,
        'signed_at' => '2026-06-01', 'notary_name' => 'Notaris Lain', 'amount' => 400000000,
    ]);
    $cek('dokumen Legal kedua ditolak', ($r['kode'] ?? '') === 'SPR_SUDAH_DITAUTKAN', json_encode($r));
    $cek('  baris pertama tidak tersentuh', ($aktaSpr($sprFinal)->legal_doc_id ?? '') === $docLegal);

    echo "\n=== 7. Nomor akta bentrok dengan akta LAIN ditolak ===\n";

    // Akta yang diterbitkan di BO (Input Massal) pada SPR kedua, memakai nomor
    // yang ingin dipakai dokumen Legal → harus ditolak sebelum MySQL berteriak.
    $bid2 = (int) $bo->table('bookings')->where('booking_number', $sprFinal2)->value('id');
    $bo->table('ajb_documents')->insert([
        'tenant_id' => $tenant, 'booking_id' => $bid2, 'ajb_number' => 'BO-BENTROK-'.$TAG,
        'signed_at' => '2026-04-01', 'amount' => 300000000, 'notary_name' => 'Notaris BO',
        'status' => 'active', 'source' => 'bo', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $r = $jembatan->simpan(array_merge($dasar, ['spr' => $sprFinal, 'ajb_number' => 'BO-BENTROK-'.$TAG]));
    $cek('nomor yang sudah dipakai akta lain ditolak', ($r['kode'] ?? '') === 'NOMOR_BENTROK', json_encode($r));
    $cek('  akta BO-nya tidak berubah',
        ($aktaSpr($sprFinal2)->notary_name ?? '') === 'Notaris BO');

    echo "\n=== 8. Akta ketikan BO digantikan akta asli dari Legal ===\n";

    // SPR kedua kini dipegang akta ketikan BO. Dokumen Legal lain menautkan diri
    // ke sana: barisnya harus DIGANTIKAN (aktanya yang asli), dan pemanggil diberi
    // tahu bahwa ada yang ditimpa — bukan diam-diam.
    $r = $jembatan->simpan([
        'legal_doc_id' => $docLegal2, 'spr' => $sprFinal2, 'ajb_number' => '113/AJB/VI/2026-'.$TAG,
        'signed_at' => '2026-06-01', 'notary_name' => 'Notaris Asli', 'amount' => 402000000,
    ]);
    $cek('penautan diterima', ($r['ok'] ?? false) === true, json_encode($r));
    $cek('  pemanggil diberi tahu ada baris BO yang ditimpa', ($r['ditimpa'] ?? false) === true);
    $akta2 = $aktaSpr($sprFinal2);
    $cek('  datanya jadi milik akta Legal', ($akta2->notary_name ?? '') === 'Notaris Asli'
        && ($akta2->source ?? '') === 'legal' && ($akta2->legal_doc_id ?? '') === $docLegal2);
    $cek('  tetap satu baris untuk SPR itu',
        $bo->table('ajb_documents')->where('booking_id', $bid2)->count() === 1);

    echo "\n=== 9. Lepas tautan hanya menyentuh baris milik Legal ===\n";

    $r = $jembatan->lepas($docLegal2);
    $cek('pelepasan diterima', ($r['ok'] ?? false) === true, json_encode($r));
    $cek('  barisnya hilang', $aktaSpr($sprFinal2) === null);
    $cek('  baris dokumen Legal lain tidak ikut terhapus', $aktaSpr($sprFinal) !== null);

    // Akta yang diterbitkan di BO tidak boleh bisa dihapus lewat jalur ini.
    // legal_doc_id-nya SENGAJA diisi id dokumen Legal: kalau baris hanya disaring
    // lewat legal_doc_id, baris ini lolos apa adanya dan penjaga `source` tidak
    // pernah benar-benar diuji. Baris seperti ini memang tidak lahir dari alur
    // normal — justru itu yang dijaga: pelepasan tidak boleh menyentuh baris yang
    // tidak menyatakan dirinya milik modul Legal.
    $bo->table('ajb_documents')->insert([
        'tenant_id' => $tenant, 'booking_id' => $bid2, 'ajb_number' => 'BO-TETAP-'.$TAG,
        'signed_at' => '2026-04-01', 'amount' => 300000000, 'notary_name' => 'Notaris BO',
        'status' => 'active', 'source' => 'bo', 'legal_doc_id' => $docLegal2,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $jembatan->lepas($docLegal2);
    $cek('  akta terbitan BO tetap utuh sesudah pelepasan', $aktaSpr($sprFinal2) !== null,
        'baris source=bo ikut terhapus');

    $r = $jembatan->lepas('');
    $cek('  lepas tanpa id ditolak', ($r['kode'] ?? '') === 'DATA_KURANG');

    echo "\n=== 10. Pintu masuknya: endpoint HTTP + tanda tangan ===\n";

    // Sampai di sini yang diuji adalah servisnya. Modul Legal tidak memanggil
    // servis itu langsung melainkan lewat HTTP, dan penjaganya ada di sana —
    // jadi jalur itu harus ikut ditembus sungguhan, bukan diasumsikan.
    $RAHASIA = 'rahasia-uji-jembatan-legal';
    config(['ipro.legal_bridge_secret' => $RAHASIA]);

    // $ts bisa ditentukan pemanggil supaya permintaan "lama" tetap DITANDATANGANI
    // DENGAN BENAR. Kalau hanya headernya yang diubah, tanda tangannya ikut tidak
    // cocok dan penolakan 401 datang dari pemeriksaan tanda tangan — penjaga umur
    // permintaannya tak pernah benar-benar diuji.
    $kirim = function (string $path, array $muatan, ?array $timpaHeader = null, ?int $waktu = null) use ($RAHASIA) {
        $body = json_encode($muatan);
        $ts = (string) ($waktu ?? time());
        $sig = hash_hmac('sha256', $ts."\n".$path."\n".hash('sha256', $body), $RAHASIA);
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_LEGAL_TIMESTAMP' => $ts,
            'HTTP_X_LEGAL_SIGNATURE' => $sig,
        ];
        if ($timpaHeader) { $server = array_merge($server, $timpaHeader); }
        $req = Illuminate\Http\Request::create($path, 'POST', [], [], [], $server, $body);
        $res = app(Illuminate\Contracts\Http\Kernel::class)->handle($req);

        return [$res->getStatusCode(), json_decode((string) $res->getContent(), true)];
    };

    [$kode, $isi] = $kirim('/api/legal-bridge/spr', ['q' => $TAG, 'limit' => 50]);
    $cek('POST /api/legal-bridge/spr menerima tanda tangan yang sah', $kode === 200 && ($isi['ok'] ?? false) === true,
        'HTTP '.$kode.' '.json_encode($isi));
    $cek('  isinya daftar SPR Final', in_array($sprFinal, array_column($isi['data'] ?? [], 'spr'), true));

    [$kode, $isi] = $kirim('/api/legal-bridge/spr', ['q' => $TAG], ['HTTP_X_LEGAL_SIGNATURE' => str_repeat('0', 64)]);
    $cek('tanda tangan salah ditolak 401', $kode === 401, 'HTTP '.$kode.' '.json_encode($isi));

    [$kode] = $kirim('/api/legal-bridge/spr', ['q' => $TAG], null, time() - 3600);
    $cek('permintaan lama (1 jam) ditolak walau tanda tangannya sah — anti pengulangan',
        $kode === 401, 'HTTP '.$kode);
    // Pembanding: permintaan yang MASIH SEGAR dengan tanda tangan yang sama-sama
    // sah harus diterima. Tanpa ini, "401" di atas bisa saja karena sebab lain.
    [$kode] = $kirim('/api/legal-bridge/spr', ['q' => $TAG], null, time() - 60);
    $cek('  permintaan berumur 1 menit masih diterima', $kode === 200, 'HTTP '.$kode);

    [$kode] = $kirim('/api/legal-bridge/spr', ['q' => $TAG], ['HTTP_X_LEGAL_SIGNATURE' => '']);
    $cek('tanpa tanda tangan ditolak 401', $kode === 401, 'HTTP '.$kode);

    // Tanda tangan untuk satu endpoint tidak boleh berlaku di endpoint lain:
    // /ajb/lepas menghapus baris, /spr hanya membaca.
    $bodyLepas = json_encode(['legal_doc_id' => $docLegal]);
    $tsX = (string) time();
    $sigUntukSpr = hash_hmac('sha256', $tsX."\n/api/legal-bridge/spr\n".hash('sha256', $bodyLepas), $RAHASIA);
    $reqX = Illuminate\Http\Request::create('/api/legal-bridge/ajb/lepas', 'POST', [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_LEGAL_TIMESTAMP' => $tsX, 'HTTP_X_LEGAL_SIGNATURE' => $sigUntukSpr], $bodyLepas);
    $resX = app(Illuminate\Contracts\Http\Kernel::class)->handle($reqX);
    $cek('tanda tangan endpoint lain tidak berlaku di /ajb/lepas', $resX->getStatusCode() === 401,
        'HTTP '.$resX->getStatusCode());
    $cek('  barisnya memang tidak ikut terhapus', $aktaSpr($sprFinal) !== null);

    // FAIL-CLOSED: tanpa kunci, endpoint harus menolak SEMUA permintaan.
    config(['ipro.legal_bridge_secret' => null]);
    [$kode] = $kirim('/api/legal-bridge/spr', ['q' => $TAG]);
    $cek('tanpa kunci terkonfigurasi, semua permintaan ditolak (fail-closed)', $kode === 401, 'HTTP '.$kode);
    config(['ipro.legal_bridge_secret' => $RAHASIA]);

    // Penolakan aturan bisnis = 422 (bukan 500): sisi Legal menampilkan pesannya apa adanya.
    [$kode, $isi] = $kirim('/api/legal-bridge/ajb', array_merge($dasar, ['spr' => $sprSprs]));
    $cek('SPR belum final lewat HTTP balas 422, bukan 500', $kode === 422 && ($isi['kode'] ?? '') === 'SPR_BELUM_FINAL',
        'HTTP '.$kode.' '.json_encode($isi));

    echo "\n==================================================\n";
    echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
    if ($gagal === 0) { echo "  SEMUA UJI LULUS\n"; }
    echo "==================================================\n";
} finally {
    DB::rollBack();

    // ipro_bo beda koneksi — tidak ikut ter-rollback, jadi dibersihkan manual.
    $bidAll = $bo->table('bookings')->where('booking_number', 'like', 'UJI-'.$TAG.'-%')->pluck('id')->all();
    if ($bidAll) {
        $bo->table('ajb_documents')->whereIn('booking_id', $bidAll)->delete();
        $bo->table('payment_schedules')->whereIn('booking_id', $bidAll)->delete();
        $bo->table('bookings')->whereIn('id', $bidAll)->delete();
    }
    $bo->table('customers')->where('code', 'CUST-'.$TAG)->delete();
    $bo->table('units')->where('unit_code', 'UNIT-'.$TAG)->delete();
    echo "(data uji dibatalkan & baris ipro_bo dibersihkan)\n";
}
