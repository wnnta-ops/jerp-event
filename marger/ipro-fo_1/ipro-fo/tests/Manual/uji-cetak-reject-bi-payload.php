<?php

declare(strict_types=1);

/*
 * Sisi server lembar cetak "Berita Acara Reject BI Checking" — apakah controller benar-benar
 * MENGIRIM keadaan yang benar.
 *
 * Uji SSR di sebelahnya (uji-cetak-reject-bi.cjs) memberi propsnya sendiri, jadi ia buta terhadap
 * controller yang menyusunnya keliru — dan kalau keliru, lembarnya tetap keluar rapi dari printer,
 * hanya isinya salah. Empat hal yang dijaga di sini:
 *
 *  1. PERLU_PASANGAN = SALINAN PERSIS GERBANG REJECT. Gerbangnya berbunyi
 *     marital_status !== 'belum_menikah', sehingga data lama yang belum punya kolom itu DIANGGAP
 *     perlu pasangan. Menuliskannya sebagai === 'sudah_menikah' membalik seluruh data lama:
 *     hasil BI pasangan hilang dari lembar, dan pemohon menikah tercetak seolah lajang.
 *  2. PEMOHON LAJANG TIDAK MEMBAWA SISA DATA PASANGAN. Baris dokumen pasangan dipangkas DAN
 *     result_pasangan dinolkan. Tanpa yang kedua, nilai sisa dari data lama tetap menandai
 *     skala kolektibilitas atas nama pasangan yang tidak dipersyaratkan.
 *  3. JENIS REJECT DINORMALKAN. Nilai di luar enum jatuh ke 'bi_checking' — kalau tidak,
 *     batalSepihak di layar cetak bernilai false untuk nilai asing dan lembar Batal Sepihak
 *     lama bisa tercetak merah "ditolak".
 *  4. TEKS MENTAH OCR TIDAK PERNAH IKUT. NIK & NPWP hasil scan tidak boleh keluar di kertas.
 *
 * Ikut diperiksa: gerbang izin, 404 untuk inisiasi yang belum ditolak, dan rute cetaknya terdaftar.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback) — basis data kembali seperti semula.
 *
 * Jalankan:  docker exec ipro-fo-app php tests/Manual/uji-cetak-reject-bi-payload.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\BankLoan\Models\KprInitiation;
use App\Http\Controllers\Web\BankLoan\KprDatabaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// tenant_id() diisi middleware SetTenant; di luar request ia NULL dan TenantScope menyaring
// habis seluruh baris.
$tenantId = (int) DB::table('tenants')->orderBy('id')->value('id');
app()->instance('tenant.id', $tenantId);

$lulus = 0;
$gagal = 0;
$cek = function (string $nama, bool $ok, string $ket = '') use (&$lulus, &$gagal) {
    if ($ok) {
        $lulus++;
        echo "  OK    {$nama}\n";
    } else {
        $gagal++;
        echo "  GAGAL {$nama}".($ket !== '' ? " — {$ket}" : '')."\n";
    }
};

DB::beginTransaction();

try {
    $now = now();
    $userId = (int) DB::table('users')->orderBy('id')->value('id');
    $projectId = (int) DB::table('projects')->orderBy('id')->value('id');
    $user = \App\Models\User::query()->find($userId);

    $custId = (int) DB::table('customers')->insertGetId([
        'tenant_id' => $tenantId, 'code' => 'UJI-BI-C1', 'full_name' => 'UJI Budi Santoso',
        'id_type' => 'ktp', 'id_number' => '327'.random_int(1000000000000, 9999999999999),
        'phone' => '0812-0000-0001',
        'created_at' => $now, 'updated_at' => $now,
    ]);

    $ttsId = (int) DB::table('tts_receipts')->insertGetId([
        'tenant_id' => $tenantId, 'project_id' => $projectId, 'tts_number' => 'UJI-TTS-0001',
        'customer_id' => $custId, 'amount' => 5000000, 'payment_method' => 'transfer',
        'received_date' => '2026-03-04', 'status' => 'posted', 'received_by' => $userId,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    /** Satu inisiasi DITOLAK dengan isi stages yang bisa diatur per skenario. */
    $buat = function (array $stages, string $status = 'rejected', string $no = 'UJI-INIT-1') use (
        $tenantId, $custId, $ttsId, $userId, $now
    ): KprInitiation {
        $id = (int) DB::table('kpr_initiations')->insertGetId([
            'tenant_id' => $tenantId, 'initiation_number' => $no, 'customer_id' => $custId,
            'tts_receipt_id' => $ttsId, 'plafon_requested' => 425000000,
            'current_stage' => 2, 'status' => $status, 'stages' => json_encode($stages),
            'initiated_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return KprInitiation::query()->findOrFail($id);
    };

    $ctl = new KprDatabaseController;

    /** @return array<string,mixed> props Inertia dari lembar cetak */
    $props = function (KprInitiation $init) use ($ctl, $user): array {
        $r = Request::create('/kpr-database/reject-bi-checking/'.$init->id.'/cetak', 'GET');
        $r->setUserResolver(fn () => $user);
        $r->headers->set('X-Inertia', 'true');
        $r->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());

        return json_decode((string) $ctl->rejectBiCheckingPrint($r, $init)->toResponse($r)->getContent(), true)['props'] ?? [];
    };

    $sprsPenuh = [
        'marital_status' => 'sudah_menikah',
        'ktp_pemohon_received' => true, 'npwp_pemohon_received' => true,
        'ktp_pasangan_received' => true, 'npwp_pasangan_received' => true,
        'ktp_pemohon_received_at' => '2026-03-06T03:00:00+00:00',
        'npwp_pemohon_received_at' => '2026-03-06T03:05:00+00:00',
        'ktp_pasangan_received_at' => '2026-03-07T03:00:00+00:00',
        'npwp_pasangan_received_at' => '2026-03-07T03:02:00+00:00',
        'done' => true, 'at' => '2026-03-08T04:00:00+00:00',
    ];
    $biPenuh = [
        'result' => 'kol3', 'result_pasangan' => 'kol2', 'permintaan' => 'mundur',
        'notes' => 'Tunggakan kartu kredit 4 bulan.', 'rejected' => true,
        'reject_type' => 'bi_checking', 'reject_reason' => 'Kolektibilitas 3 pada pemohon.',
        'rejected_at' => '2026-03-10T07:30:00+00:00', 'rejected_by' => $userId,
        'rejected_by_name' => 'UJI Sari Dewi',
    ];

    echo "\n=== 0. Gerbang izin & keadaan ===\n";

    $cek('user uji memang berhak', (bool) $user?->can('bankloan.application.view'),
        'user '.$userId.' tidak berhak — ganti user uji');

    $initPenuh = $buat(['sprs' => $sprsPenuh, 'bi_checking' => $biPenuh]);

    $tanpaUser = Request::create('/x', 'GET');
    $tanpaUser->setUserResolver(fn () => null);
    $ditolak = false;

    try {
        $ctl->rejectBiCheckingPrint($tanpaUser, $initPenuh);
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        $ditolak = $e->getStatusCode() === 403;
    }
    $cek('tanpa izin ditolak 403', $ditolak, 'lembar cetak terbuka untuk siapa saja');

    $belumDitolak = $buat(['sprs' => $sprsPenuh, 'bi_checking' => []], 'in_progress', 'UJI-INIT-BELUM');
    $empatNolEmpat = false;

    try {
        $props($belumDitolak);
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        $empatNolEmpat = $e->getStatusCode() === 404;
    }
    $cek('inisiasi yang belum ditolak menghasilkan 404', $empatNolEmpat,
        'berita acara penolakan bisa dicetak untuk inisiasi yang masih berjalan');

    $cek('rute cetak terdaftar', Route::has('kpr.database.reject-bi.cetak'));
    $cek('rute cetak terpisah dari rute detail',
        Route::has('kpr.database.reject-bi.detail')
        && route('kpr.database.reject-bi.cetak', 1) !== route('kpr.database.reject-bi.detail', 1),
        'kedua rute menunjuk URL yang sama');

    echo "\n=== 1. Payload lengkap terkirim ===\n";

    $p = $props($initPenuh);

    $cek('nama customer terkirim', ($p['init']['customer'] ?? null) === 'UJI Budi Santoso');
    $cek('telepon terkirim', ($p['init']['phone'] ?? null) === '0812-0000-0001');
    $cek('nomor TTS terkirim', ($p['init']['tts'] ?? null) === 'UJI-TTS-0001');
    // Kedua kolom ini sudah ikut ter-load sejak dulu tetapi tidak pernah dikirim ke mana pun.
    $cek('nominal TTS terkirim', (float) ($p['init']['tts_amount'] ?? 0) === 5000000.0,
        var_export($p['init']['tts_amount'] ?? null, true));
    $cek('tanggal TTS terkirim', ($p['init']['tts_date'] ?? null) === '2026-03-04',
        var_export($p['init']['tts_date'] ?? null, true));
    $cek('plafon terkirim', (float) ($p['init']['plafon'] ?? 0) === 425000000.0);
    $cek('id ikut terkirim (untuk tautan balik)', (int) ($p['init']['id'] ?? 0) === $initPenuh->id);

    $cek('alasan penolakan terkirim', ($p['bi']['reason'] ?? null) === 'Kolektibilitas 3 pada pemohon.');
    $cek('catatan BI terkirim', ($p['bi']['notes'] ?? null) === 'Tunggakan kartu kredit 4 bulan.');
    $cek('nama penolak terkirim', ($p['bi']['rejected_by'] ?? null) === 'UJI Sari Dewi');
    $cek('waktu penolakan terkirim', ! empty($p['bi']['rejected_at']));
    $cek('identitas perusahaan terkirim', ! empty($p['company']['name']) && ! empty($p['company']['address']));
    $cek('nomor dokumen memuat nomor inisiasi',
        str_contains((string) ($p['cetak']['nomor'] ?? ''), 'UJI-INIT-1'), (string) ($p['cetak']['nomor'] ?? ''));
    $cek('penanda tangan kiri = petugas yang menolak',
        ($p['signers']['left']['name'] ?? null) === 'UJI Sari Dewi');

    echo "\n=== 2. perlu_pasangan = salinan persis gerbang reject ===\n";

    $cek('sudah menikah → perlu pasangan', ($p['sprs']['perlu_pasangan'] ?? null) === true);
    $cek('sudah menikah → empat baris dokumen', count($p['sprs']['dokumen'] ?? []) === 4,
        (string) count($p['sprs']['dokumen'] ?? []));

    // Gerbang reject berbunyi !== 'belum_menikah'. Data lama tanpa kolom marital_status karena
    // itu DIANGGAP perlu pasangan — menulisnya === 'sudah_menikah' membalik seluruh data lama.
    $sprsLama = $sprsPenuh;
    unset($sprsLama['marital_status']);
    $pLama = $props($buat(['sprs' => $sprsLama, 'bi_checking' => $biPenuh], 'rejected', 'UJI-INIT-LAMA'));
    $cek('data lama tanpa marital_status → tetap perlu pasangan',
        ($pLama['sprs']['perlu_pasangan'] ?? null) === true,
        'sama seperti gerbang reject; kalau false, hasil BI pasangan hilang dari seluruh data lama');
    $cek('data lama tetap empat baris dokumen', count($pLama['sprs']['dokumen'] ?? []) === 4);
    $cek('data lama tetap membawa hasil BI pasangan', ($pLama['bi']['result_pasangan'] ?? null) === 'kol2');

    echo "\n=== 3. Pemohon lajang tidak membawa sisa data pasangan ===\n";

    $sprsLajang = $sprsPenuh;
    $sprsLajang['marital_status'] = 'belum_menikah';
    // Sisa data pasangan SENGAJA dibiarkan terisi: begitulah bentuk baris yang status
    // pernikahannya diubah setelah dokumen pasangan terlanjur tercentang.
    $pLajang = $props($buat(['sprs' => $sprsLajang, 'bi_checking' => $biPenuh], 'rejected', 'UJI-INIT-LAJANG'));

    $cek('belum menikah → tidak perlu pasangan', ($pLajang['sprs']['perlu_pasangan'] ?? null) === false);
    $cek('belum menikah → dua baris dokumen saja', count($pLajang['sprs']['dokumen'] ?? []) === 2,
        (string) count($pLajang['sprs']['dokumen'] ?? []));
    $cek('baris dokumen yang tersisa hanya milik pemohon',
        array_column($pLajang['sprs']['dokumen'] ?? [], 'label') === ['KTP Pemohon', 'NPWP Pemohon'],
        implode(',', array_column($pLajang['sprs']['dokumen'] ?? [], 'label')));
    // array_key_exists, BUKAN ?? : operator ?? ikut memicu pada nilai null, sehingga
    // ($x ?? 'ADA') === null tidak pernah benar dan pemeriksaannya tidak menguji apa pun.
    $cek('hasil BI pasangan dinolkan',
        array_key_exists('result_pasangan', $pLajang['bi'] ?? []) && $pLajang['bi']['result_pasangan'] === null,
        'sisa nilai lama masih menandai skala atas nama pasangan yang tidak dipersyaratkan: '
        .var_export($pLajang['bi']['result_pasangan'] ?? '(kunci hilang)', true));
    $cek('hasil BI pemohon TIDAK ikut hilang', ($pLajang['bi']['result'] ?? null) === 'kol3');

    echo "\n=== 4. Jenis reject dinormalkan ===\n";

    $jenisDari = function ($nilai) use ($buat, $props, $sprsPenuh, $biPenuh) {
        $bi = $biPenuh;
        if ($nilai === null) {
            unset($bi['reject_type']);
        } else {
            $bi['reject_type'] = $nilai;
        }

        return $props($buat(['sprs' => $sprsPenuh, 'bi_checking' => $bi], 'rejected', 'UJI-INIT-J'.md5((string) $nilai)))['bi']['jenis'] ?? null;
    };

    // Tiap nilai dihitung SEKALI: PHP mengevaluasi argumen keterangan juga, dan memanggil
    // $jenisDari dua kali akan menyisipkan inisiasi bernomor sama untuk kedua kalinya.
    $jBi = $jenisDari('bi_checking');
    $jBs = $jenisDari('batal_sepihak');
    $jAsing = $jenisDari('entah_apa');
    $jKosong = $jenisDari(null);

    $cek("'bi_checking' tetap bi_checking", $jBi === 'bi_checking', (string) $jBi);
    $cek("'batal_sepihak' tetap batal_sepihak", $jBs === 'batal_sepihak', (string) $jBs);
    $cek('nilai asing jatuh ke bi_checking', $jAsing === 'bi_checking', (string) $jAsing);
    $cek('kunci yang tidak ada jatuh ke bi_checking', $jKosong === 'bi_checking', (string) $jKosong);

    echo "\n=== 5. Jejak pernah-lanjut & OCR ===\n";

    $cek('done=true terbaca sebagai pernah lanjut', ($p['bi']['pernah_lanjut'] ?? null) === false,
        'bi_checking pada data uji ini tidak punya done — harusnya false');

    $biLanjut = $biPenuh + ['done' => true, 'at' => '2026-03-09T02:00:00+00:00'];
    $pLanjut = $props($buat(['sprs' => $sprsPenuh, 'bi_checking' => $biLanjut], 'rejected', 'UJI-INIT-LANJUT'));
    $cek('bi_checking done=true → pernah_lanjut true', ($pLanjut['bi']['pernah_lanjut'] ?? null) === true);
    $cek('waktu lanjut ikut terkirim', ($pLanjut['bi']['lanjut_at'] ?? null) === '2026-03-09T02:00:00+00:00');

    // verdict ada tetapi verified belum true = scan diunggah, belum diverifikasi. Mengirim
    // verdictnya membuat lembar cetak memvonis "nama TIDAK sesuai" atas pemeriksaan yang
    // belum pernah dijalankan.
    $sprsBelumVerif = $sprsPenuh;
    $sprsBelumVerif['docs'] = ['verified' => false, 'verdict' => 'mismatch', 'ktp_text' => 'NIK 3578...', 'npwp_text' => '65.507...'];
    $pBelum = $props($buat(['sprs' => $sprsBelumVerif, 'bi_checking' => $biPenuh], 'rejected', 'UJI-INIT-OCR0'));
    $cek('scan belum diverifikasi → OCR tidak dilaporkan',
        array_key_exists('ocr', $pBelum['sprs'] ?? []) && $pBelum['sprs']['ocr'] === null,
        'memvonis kecocokan nama atas pemeriksaan yang belum dijalankan: '
        .var_export($pBelum['sprs']['ocr'] ?? '(kunci hilang)', true));

    $sprsVerif = $sprsPenuh;
    $sprsVerif['docs'] = ['verified' => true, 'verdict' => 'match', 'ktp_text' => 'NIK 3578...', 'npwp_text' => '65.507...'];
    $pVerif = $props($buat(['sprs' => $sprsVerif, 'bi_checking' => $biPenuh], 'rejected', 'UJI-INIT-OCR1'));
    $cek('scan terverifikasi → vonis OCR dilaporkan', ($pVerif['sprs']['ocr'] ?? null) === 'match');

    echo "\n=== 6. Teks mentah OCR tidak pernah sampai ke kertas ===\n";

    $rata = json_encode($pVerif);
    $cek('NIK hasil scan tidak ada di payload', ! str_contains((string) $rata, 'NIK 3578'),
        'teks mentah KTP ikut terkirim ke lembar cetak');
    $cek('teks NPWP hasil scan tidak ada di payload', ! str_contains((string) $rata, '65.507'),
        'teks mentah NPWP ikut terkirim ke lembar cetak');
    $cek('kunci ktp_text/npwp_text tidak ada', ! str_contains((string) $rata, 'ktp_text') && ! str_contains((string) $rata, 'npwp_text'));

    echo "\n==================================================\n";
    echo "  LULUS {$lulus} · GAGAL {$gagal}\n";
    if ($gagal === 0) {
        echo "  SEMUA UJI LULUS\n";
    }
    echo "==================================================\n";
} finally {
    DB::rollBack();
}

exit($gagal ? 1 : 0);
