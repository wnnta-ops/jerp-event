<?php

/**
 * Detail AJB (Booked Revenue) — baris yang diisi dari direktori modul Legal.
 *
 * Permintaannya: akta AJB yang diarsipkan di modul Legal dan ditautkan ke sebuah
 * No. SPR (Final) harus MENGISI SEMUA KOLOM di layar Detail AJB dan mengubah
 * keterangannya dari "BELUM BAST" menjadi "SUDAH BAST".
 *
 * Yang dijaga di sini, berurut dari yang paling mahal bila salah:
 *
 *  1. PERILAKU LAMA TIDAK IKUT TERBUKA. Sebelum ini kolom akta disembunyikan
 *     selama unitnya belum punya dokumen BAST. Cara termudah memenuhi permintaan
 *     adalah mencabut syarat itu untuk SEMUA baris — dan itu akan membuat nomor
 *     yang sekadar diketik lewat "Input Massal AJB", tanpa dokumen apa pun di
 *     belakangnya, ikut terbaca SUDAH BAST dan bisa diposting jadi pendapatan.
 *     Uji ini menuntut baris 'bo' tanpa BAST TETAP tertutup seperti semula.
 *  2. BARIS DARI LEGAL MENGISI KEEMPAT KOLOMNYA dan keterangannya berubah.
 *  3. KELENGKAPAN TETAP DITEGAKKAN. Akta dari Legal yang nominalnya belum ada
 *     tidak boleh terbaca SUDAH BAST hanya karena sumbernya Legal.
 *  4. TOMBOL POSTING ikut menyala — konsekuensi yang memang diminta, dibuktikan
 *     dengan angka supaya tidak jadi kejutan.
 *  5. EKSPOR-nya menjawab dari mana angkanya berasal (kolom "Sumber Akta") dan
 *     jumlah selnya tetap cocok dengan kepalanya.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-detail-ajb-dari-legal.php';"
 */

use App\Domain\Legal\Ajb\Models\AjbDocument;
use App\Http\Controllers\Web\BusinessSupport\Revenue\BookedRevenueController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

$user = User::all()->first(fn ($u) => $u->can('ar.booked-revenue.view')) ?? User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
auth()->setUser($user);
app()->instance('tenant.id', (int) $user->tenant_id);
$TENANT = (int) $user->tenant_id;

if (! Schema::hasColumn('ajb_documents', 'source')) {
    echo "Kolom ajb_documents.source belum ada — jalankan migrasi 2026_09_22_100000 dulu.\n";
    return;
}

$ctl = new BookedRevenueController;
$req = function ($u, array $data = []) {
    $r = Request::create('/uji', 'GET', $data, [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $r->setUserResolver(fn () => $u);
    $r->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());

    return $r;
};
$props = fn ($res, $r) => json_decode((string) $res->toResponse($r)->getContent(), true)['props'] ?? [];

/**
 * "Kolomnya benar-benar null" — BUKAN ditulis $row['k'] ?? 'x'.
 * `??` ikut menelan null, jadi bentuk itu tidak bisa membedakan "kolom sengaja
 * dikosongkan" dari "kunci tidak ada sama sekali" dan uji jadi lulus/gagal
 * karena alasan yang salah.
 */
$kosong = fn (?array $row, string $k) => is_array($row) && array_key_exists($k, $row) && $row[$k] === null;

DB::beginTransaction();

try {
    $schemeId = (int) DB::table('payment_schemes')->value('id');
    $unitIds  = DB::table('units')->limit(4)->pluck('id')->all();
    if ($schemeId === 0 || count($unitIds) < 4) {
        echo "Master belum cukup (butuh 1 payment_scheme & 4 unit) — uji dihentikan.\n";
        DB::rollBack();

        return;
    }

    $custId = DB::table('customers')->insertGetId([
        'tenant_id' => $TENANT, 'code' => 'UJI-AJB-'.uniqid(), 'full_name' => 'Pembeli Uji AJB',
        'id_type' => 'ktp', 'id_number' => encrypt('3515000000000000'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Empat SPR final — satu untuk tiap keadaan yang diuji.
    $buatSpr = function (string $nomor, int $unitId) use ($TENANT, $custId, $schemeId, $user) {
        return (int) DB::table('bookings')->insertGetId([
            'tenant_id' => $TENANT, 'project_id' => 1, 'booking_number' => $nomor,
            'customer_id' => $custId, 'unit_id' => $unitId, 'payment_scheme_id' => $schemeId,
            'booking_date' => now()->toDateString(), 'base_price' => 500000000, 'total_price' => 501000000,
            'status' => 'active', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $uniq = strtoupper(substr(uniqid(), -5));
    $sprPolos  = 'UJI-SPR-'.$uniq.'-A'; // tanpa akta sama sekali
    $sprBoTanpaBast = 'UJI-SPR-'.$uniq.'-B'; // akta diketik di BO, belum BAST
    $sprLegal  = 'UJI-SPR-'.$uniq.'-C'; // akta dari modul Legal, belum BAST
    $sprLegalTanpaNominal = 'UJI-SPR-'.$uniq.'-D'; // akta dari Legal, nominal kosong

    $idPolos = $buatSpr($sprPolos, (int) $unitIds[0]);
    $idBo    = $buatSpr($sprBoTanpaBast, (int) $unitIds[1]);
    $idLegal = $buatSpr($sprLegal, (int) $unitIds[2]);
    $idLegalNihil = $buatSpr($sprLegalTanpaNominal, (int) $unitIds[3]);

    AjbDocument::create([
        'tenant_id' => $TENANT, 'booking_id' => $idBo, 'ajb_number' => 'UJI-BO-'.$uniq,
        'signed_at' => '2026-05-18', 'amount' => 501000000, 'notary_name' => 'Notaris Ketikan BO', 'status' => 'active',
    ]);
    AjbDocument::create([
        'tenant_id' => $TENANT, 'booking_id' => $idLegal, 'ajb_number' => '112/AJB/V/2026-'.$uniq,
        'signed_at' => '2026-05-18', 'amount' => 501000000, 'notary_name' => 'Rina Sari, S.H., M.Kn.',
        'status' => 'active', 'source' => AjbDocument::SUMBER_LEGAL, 'legal_doc_id' => 'ckuji'.$uniq,
        'legal_synced_at' => now(),
    ]);
    AjbDocument::create([
        'tenant_id' => $TENANT, 'booking_id' => $idLegalNihil, 'ajb_number' => '113/AJB/V/2026-'.$uniq,
        'signed_at' => '2026-05-18', 'amount' => null, 'notary_name' => 'Rina Sari, S.H., M.Kn.',
        'status' => 'active', 'source' => AjbDocument::SUMBER_LEGAL, 'legal_doc_id' => 'ckuji2'.$uniq,
    ]);

    $r = $req($user, ['search' => 'UJI-SPR-'.$uniq]);
    $p = $props($ctl->controlAjb($r), $r);
    $baris = collect($p['rows'] ?? [])->keyBy('spr');

    echo "\n=== 1. Perilaku lama: akta ketikan BO tanpa BAST tetap tertutup ===\n";

    $b = $baris[$sprBoTanpaBast] ?? null;
    $ok('barisnya ada di layar', $b !== null);
    $ok('  No. AJB TIDAK ditampilkan (belum ada BAST)', $kosong($b, 'ajb_number'), json_encode($b['ajb_number'] ?? null));
    $ok('  Notaris TIDAK ditampilkan', $kosong($b, 'notaris'), json_encode($b['notaris'] ?? null));
    $ok('  Nominal TIDAK ditampilkan', $kosong($b, 'ajb_amount'), json_encode($b['ajb_amount'] ?? null));
    $ok('  keterangannya tetap BELUM BAST', ($b['keterangan'] ?? '') === 'BELUM BAST', $b['keterangan'] ?? '-');
    $ok('  tombol Posting tetap mati', ($b['can_posting'] ?? true) === false);
    $ok('  tidak ditandai berasal dari Legal', ($b['dari_legal'] ?? true) === false);

    echo "\n=== 2. SPR tanpa akta sama sekali ===\n";

    $a = $baris[$sprPolos] ?? null;
    $ok('barisnya tetap muncul (tidak ada SPR yang hilang)', $a !== null);
    $ok('  keterangannya BELUM BAST', ($a['keterangan'] ?? '') === 'BELUM BAST');
    $ok('  keempat kolom aktanya kosong',
        $kosong($a, 'notaris') && $kosong($a, 'ajb_number')
        && $kosong($a, 'ajb_date') && $kosong($a, 'ajb_amount'));

    echo "\n=== 3. Akta dari modul Legal — INTI PERMINTAAN ===\n";

    $c = $baris[$sprLegal] ?? null;
    $ok('barisnya ada di layar', $c !== null);
    $ok('  Notaris terisi dari Legal', ($c['notaris'] ?? null) === 'Rina Sari, S.H., M.Kn.', json_encode($c['notaris'] ?? null));
    $ok('  No. AJB terisi dari Legal', ($c['ajb_number'] ?? null) === '112/AJB/V/2026-'.$uniq, json_encode($c['ajb_number'] ?? null));
    $ok('  Tanggal AJB terisi dari Legal', ($c['ajb_date'] ?? null) === '2026-05-18', json_encode($c['ajb_date'] ?? null));
    $ok('  Nominal AJB terisi dari Legal', (float) ($c['ajb_amount'] ?? 0) === 501000000.0, json_encode($c['ajb_amount'] ?? null));
    $ok('  keterangannya BERUBAH jadi SUDAH BAST', ($c['keterangan'] ?? '') === 'SUDAH BAST', $c['keterangan'] ?? '-');
    // Sengaja dipisah dari poin di atas: baris ini memang BELUM punya dokumen
    // BAST — yang berubah adalah keterangannya, bukan ada-tidaknya BAST.
    $ok('  padahal dokumen BAST-nya memang belum ada', ($c['sudah_bast'] ?? true) === false);
    $ok('  ditandai berasal dari Legal (lencana di layar)', ($c['dari_legal'] ?? false) === true);
    $ok('  tombol Posting Booked Revenue menyala', ($c['can_posting'] ?? false) === true);

    echo "\n=== 4. Kelengkapan tetap ditegakkan ===\n";

    $d = $baris[$sprLegalTanpaNominal] ?? null;
    $ok('akta Legal tanpa nominal: nomornya tetap ditampilkan', ($d['ajb_number'] ?? null) !== null);
    $ok('  tetapi keterangannya BELUM BAST (nominal kosong)', ($d['keterangan'] ?? '') === 'BELUM BAST', $d['keterangan'] ?? '-');
    $ok('  tombol Posting tetap mati', ($d['can_posting'] ?? true) === false);

    echo "\n=== 5. Ringkasan & saringan ikut menghitung ===\n";

    $ok('ringkasan "sudah" menghitung baris dari Legal',
        (int) ($p['summary']['sudah'] ?? 0) === 1, json_encode($p['summary'] ?? null));
    $ok('ringkasan "belum" menghitung tiga sisanya',
        (int) ($p['summary']['belum'] ?? 0) === 3, json_encode($p['summary'] ?? null));

    $rSudah = $req($user, ['search' => 'UJI-SPR-'.$uniq, 'status' => 'sudah']);
    $pSudah = $props($ctl->controlAjb($rSudah), $rSudah);
    $ok('saringan "SUDAH BAST" hanya menyisakan baris dari Legal',
        count($pSudah['rows'] ?? []) === 1 && ($pSudah['rows'][0]['spr'] ?? '') === $sprLegal);

    // Notaris ikut dicari — di layar kolomnya ada, jadi petugas akan mengetiknya.
    $rCari = $req($user, ['search' => 'Rina Sari']);
    $pCari = $props($ctl->controlAjb($rCari), $rCari);
    $ok('pencarian nama notaris menemukan baris dari Legal',
        collect($pCari['rows'] ?? [])->contains(fn ($x) => $x['spr'] === $sprLegal));

    echo "\n=== 6. Ekspor menjawab dari mana angkanya berasal ===\n";

    $xls = $ctl->exportControlAjb($req($user, ['search' => 'UJI-SPR-'.$uniq]))->getContent();
    $ok('kepala berkas punya kolom "Sumber Akta"', str_contains($xls, 'Sumber Akta'));
    $ok('  baris dari Legal tertulis "Modul Legal"', str_contains($xls, 'Modul Legal'));
    $ok('  baris tanpa akta tertulis "—"', preg_match('/<td[^>]*>—<\/td>/u', $xls) === 1, 'tidak ada sel bertanda —');

    // Jumlah sel tiap baris harus sama dengan kepalanya; kalau tidak, Excel
    // menggeser isinya satu kolom dan angka terbaca di kolom yang salah.
    // Kepalanya memakai <th>, barisnya <td> — keduanya ikut dihitung.
    preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $xls, $m);
    $jumlahSel = array_map(fn ($tr) => substr_count($tr, '<td') + substr_count($tr, '<th'), $m[1]);
    $kepala = $jumlahSel[0] ?? 0;
    $ok('  kepalanya 11 kolom', $kepala === 11, 'dapat '.$kepala);
    $barisIsi = array_slice($jumlahSel, 1, count($jumlahSel) - 2);
    $ok('  setiap baris isi punya jumlah sel yang sama',
        count(array_unique($barisIsi)) === 1 && ($barisIsi[0] ?? 0) === $kepala, json_encode($jumlahSel));
    // Baris TOTAL memakai colspan 7 + 1 angka + 3 kosong = 5 <td>, menutup 11 kolom.
    $ok('  baris TOTAL menutup seluruh 11 kolom', end($jumlahSel) === 5, 'dapat '.end($jumlahSel));

    echo "\n==================================================\n";
    echo "  LULUS $lulus · GAGAL $gagal\n";
    if ($gagal === 0) { echo "  SEMUA UJI LULUS\n"; }
    echo "==================================================\n";
} finally {
    DB::rollBack();
    echo "(data uji dibatalkan)\n";
}
