<?php

/**
 * Faktur Pajak Masukan & Keluaran dibentuk dari MUTASI AKUN PPN di buku besar.
 *
 * APA YANG SALAH SEBELUMNYA. Kedua daftar dibaca dari tabel invoice: Masukan dari
 * bs_ap_invoices yang ppn_amount > 0, Keluaran dari bs_ar_invoices + FakturPajak + entri
 * approval. Akibatnya setiap PPN yang lahir DI LUAR jalur invoice — jurnal memorial, koreksi,
 * impor jurnal, konversi UTJ/DP — tidak pernah muncul di layar, sementara akun 10105010101 /
 * 20104040406 di buku besar jelas termutasi. SPT Masa disusun dari daftar yang lebih pendek
 * daripada buku besarnya sendiri, dan selisih itu tidak menimbulkan galat apa pun.
 *
 * Yang diuji di sini:
 *   1. Baris = baris jurnal. Jurnal tanpa invoice IKUT; jurnal terhapus & masa lain TIDAK.
 *   2. No. Faktur Pajak & NPWP kosong sampai diisi, dan tersimpan pada BARIS-nya.
 *   3. NPWP tidak dituliskan balik ke master supplier/customer.
 *   4. Hapus hanya membuang nomornya — baris jurnalnya tetap ada.
 *   5. Input massal hanya menyentuh baris yang dikirim.
 *   6. Impor CSV (koma maupun titik-koma) memakai JENIS_BARIS + ID_BARIS.
 *   7. Ekspor CSV/Excel berisi jumlah baris yang SAMA dengan layarnya.
 *   8. Rekap PPN memakai angka dari kedua daftar itu juga.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-faktur-pajak-jurnal.php';"
 */

use App\Domain\BusinessSupport\Models\BsFakturPajakLineEntry;
use App\Http\Controllers\Web\BusinessSupport\Tax\FakturPajakController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

$user = User::all()->first(fn ($u) => $u->canModule('tax')) ?? User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
auth()->setUser($user);

$ctl = app(FakturPajakController::class);
$panggil = function (string $method, ...$args) use ($ctl) {
    $m = new ReflectionMethod(FakturPajakController::class, $method);
    $m->setAccessible(true);

    return $m->invoke($ctl, ...$args);
};
/** Request ber-user, supaya abort_unless(canModule('tax')) lolos. */
$req = function (array $data = [], array $query = []) use ($user) {
    $r = Request::create('/uji', empty($data) ? 'GET' : 'POST', $data + $query);
    $r->setUserResolver(fn () => $user);

    return $r;
};
/** Request bergaya Inertia, supaya Inertia\Response mengembalikan JSON berisi props. */
$reqInertia = function (array $query = []) use ($user) {
    $r = Request::create('/uji', 'GET', $query, [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $r->setUserResolver(fn () => $user);

    return $r;
};

$AKUN_PPN_IN = DB::table('bs_accounts')->where('code', '10105010101')->value('id');
$AKUN_PPN_OUT = DB::table('bs_accounts')->where('code', '20104040406')->value('id');
if (! $AKUN_PPN_IN || ! $AKUN_PPN_OUT) { echo "Akun PPN tidak ada — uji dihentikan.\n"; return; }

// Akun lawan mana pun asal bukan akun PPN-nya sendiri.
$lawan = DB::table('bs_accounts')->whereNotIn('id', [$AKUN_PPN_IN, $AKUN_PPN_OUT])
    ->where('is_postable', 1)->orderBy('id')->limit(2)->pluck('id')->all();
if (count($lawan) < 2) { echo "Akun lawan tidak cukup — uji dihentikan.\n"; return; }
[$AKUN_A, $AKUN_B] = $lawan;

$TAHUN = 2031;   // masa pajak khayalan, tidak mungkin bertabrakan dengan data nyata
$BULAN = 7;
$TGL   = sprintf('%04d-%02d-15', $TAHUN, $BULAN);

DB::beginTransaction();

try {
    $tenantId = (int) (DB::table('bs_accounts')->where('id', $AKUN_PPN_IN)->value('tenant_id') ?: 1);

    /** Bentuk satu jurnal + baris-barisnya. $lines = [[account_id, debit, credit, desc], …] */
    $buatJurnal = function (string $nomor, string $tanggal, string $source, string $desc, array $lines, ?string $ref = null, bool $terhapus = false)
        use ($tenantId, $user) {
        $jid = DB::table('bs_journals')->insertGetId([
            'tenant_id' => $tenantId, 'journal_number' => $nomor, 'journal_date' => $tanggal,
            'period_year' => (int) substr($tanggal, 0, 4), 'period_month' => (int) substr($tanggal, 5, 2),
            'source' => $source, 'reference' => $ref, 'description' => $desc, 'status' => 'posted',
            'total_debit' => array_sum(array_column($lines, 1)), 'total_credit' => array_sum(array_column($lines, 2)),
            'created_by' => $user->id, 'posted_at' => now(),
            'deleted_at' => $terhapus ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ids = [];
        foreach ($lines as $l) {
            $ids[] = DB::table('bs_journal_lines')->insertGetId([
                'journal_id' => $jid, 'account_id' => $l[0], 'debit' => $l[1], 'credit' => $l[2],
                'description' => $l[3] ?? null, 'currency' => 'IDR', 'kurs' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$jid, $ids];
    };

    // ── MASUKAN ──
    // J1: pembelian berinvoice (jalur lama — dulu pun muncul).
    [$j1, $l1] = $buatJurnal('UJI/FP/IN-1', $TGL, 'ap', 'Pembelian berinvoice', [
        [$AKUN_A, 1000000, 0, 'Beban'],
        [$AKUN_PPN_IN, 110000, 0, 'PPN Masukan'],
        [$AKUN_B, 0, 1110000, 'Utang'],
    ]);
    // J2: jurnal MEMORIAL tanpa invoice — inilah yang dulu hilang dari layar.
    [$j2, $l2] = $buatJurnal('UJI/FP/IN-2', $TGL, 'memorial', 'Koreksi PPN tanpa invoice', [
        [$AKUN_A, 2000000, 0, 'Beban'],
        [$AKUN_PPN_IN, 220000, 0, 'PPN Masukan'],
        [$AKUN_B, 0, 2220000, 'Kas'],
    ]);

    // ── KELUARAN ──
    // J3: konversi UTJ/DP — mengkredit PPN Keluaran tanpa AR Invoice sama sekali.
    [$j3, $l3] = $buatJurnal('UJI/FP/OUT-1', $TGL, 'cash', 'Konversi UTJ/DP ke Uang Muka Penjualan', [
        [$AKUN_A, 1110000, 0, 'Utang Usaha Customer'],
        [$AKUN_B, 0, 1000000, 'Uang Muka Penjualan'],
        [$AKUN_PPN_OUT, 0, 110000, 'PPN Keluaran'],
    ], 'KONVERSI-UJI');
    // J4: jurnal TERHAPUS — tidak boleh ikut.
    [$j4, $l4] = $buatJurnal('UJI/FP/OUT-2', $TGL, 'cash', 'Jurnal dibatalkan', [
        [$AKUN_A, 555000, 0, null],
        [$AKUN_PPN_OUT, 0, 555000, 'PPN Keluaran'],
    ], null, true);
    // J5: masa pajak LAIN — tidak boleh ikut.
    [$j5, $l5] = $buatJurnal('UJI/FP/OUT-3', sprintf('%04d-%02d-15', $TAHUN, $BULAN + 1), 'cash', 'Bulan berikutnya', [
        [$AKUN_A, 777000, 0, null],
        [$AKUN_PPN_OUT, 0, 777000, 'PPN Keluaran'],
    ]);

    // ── ENTRI APPROVAL ──
    $buatEntri = function (string $sourceType, string $tanggal, string $ket, float $dpp, float $ppn, bool $aktif, ?int $journalId = null, ?int $convertJournalId = null)
        use ($tenantId, $user) {
        return DB::table('bs_faktur_keluaran_entries')->insertGetId([
            'tenant_id' => $tenantId, 'source_type' => $sourceType, 'source_id' => null, 'keterangan' => $ket,
            'customer_id' => null, 'booking_id' => null, 'doc_date' => $tanggal,
            'dpp' => $dpp, 'ppn' => $ppn, 'total' => $dpp + $ppn, 'fp_number' => null,
            'is_active' => $aktif ? 1 : 0, 'converted_at' => null,
            'convert_journal_id' => $convertJournalId, 'journal_id' => $journalId,
            'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    // E1: UTJ & DP belum dikonversi — belum berjurnal, jadi harus tetap tampil (pintu konversi).
    $e1 = $buatEntri('utj_dp', $TGL, 'UTJ Uji Belum Konversi', 3000000, 330000, false);
    // E2: entri Retensi yang jurnalnya SUDAH ada di daftar (J3) — tidak boleh dihitung dua kali.
    $e2 = $buatEntri('retensi', $TGL, 'Retensi Uji Sudah Berjurnal', 1000000, 110000, true, $j3);

    echo "\n=== 1. DAFTAR DIBENTUK DARI MUTASI AKUN, BUKAN DARI INVOICE ===\n";

    $mas = $panggil('masukanRows', $TAHUN, $BULAN);
    $kel = $panggil('keluaranRows', $TAHUN, $BULAN);

    $idMas = $mas->pluck('id')->all();
    $ok('jurnal AP berinvoice ikut di Masukan', in_array($l1[1], $idMas, true));
    $ok('jurnal MEMORIAL tanpa invoice ikut di Masukan', in_array($l2[1], $idMas, true),
        'inilah baris yang dulu hilang: '.json_encode($idMas));

    $idKel = $kel->where('row_type', 'jurnal')->pluck('id')->all();
    $ok('jurnal konversi UTJ/DP ikut di Keluaran', in_array($l3[2], $idKel, true), json_encode($idKel));
    $ok('jurnal TERHAPUS tidak ikut', ! in_array($l4[1], $idKel, true));
    $ok('jurnal masa pajak lain tidak ikut', ! in_array($l5[1], $idKel, true));

    // Sisi mutasinya harus benar: PPN Masukan dari DEBIT, PPN Keluaran dari KREDIT.
    $barisIn  = $mas->firstWhere('id', $l2[1]);
    $barisOut = $kel->firstWhere('id', $l3[2]);
    $ok('PPN Masukan diambil dari sisi debit', abs((float) $barisIn['ppn'] - 220000) < 0.01, (string) $barisIn['ppn']);
    $ok('PPN Keluaran diambil dari sisi kredit', abs((float) $barisOut['ppn'] - 110000) < 0.01, (string) $barisOut['ppn']);

    // DPP diturunkan dari jurnalnya, bukan nol menyesatkan.
    $ok('DPP Masukan = total debit − debit PPN', abs((float) $barisIn['dpp'] - 2000000) < 0.01, (string) $barisIn['dpp']);
    $ok('DPP Keluaran = total kredit − kredit PPN', abs((float) $barisOut['dpp'] - 1000000) < 0.01, (string) $barisOut['dpp']);

    // Kolom-kolom yang diminta mengikuti rincian jurnalnya.
    $ok('kolom Sumber mengikuti sumber jurnalnya', $barisOut['source'] === 'cash', (string) $barisOut['source']);
    $ok('kolom Tanggal mengikuti tanggal jurnalnya', $barisOut['invoice_date'] === $TGL, (string) $barisOut['invoice_date']);
    $ok('kolom No. Dokumen memakai referensi/nomor jurnalnya', $barisOut['doc_no'] === 'KONVERSI-UJI', (string) $barisOut['doc_no']);
    $ok('baris keluaran membawa journal_id untuk ditautkan', (int) $barisOut['journal_id'] === $j3);

    echo "\n=== 1b. ENTRI APPROVAL: PINTU KONVERSI TETAP ADA, TANPA HITUNG GANDA ===\n";

    $barisEntri = $kel->first(fn ($r) => $r['row_type'] === 'entry' && $r['id'] === $e1);
    $ok('entri UTJ & DP yang belum berjurnal tetap tampil', $barisEntri !== null);
    $ok('entri itu ditandai perlu dikonversi', (bool) ($barisEntri['needs_konversi'] ?? false));
    $ok('entri itu belum boleh diisi nomor faktur', ($barisEntri['editable'] ?? true) === false);
    $ok('entri yang jurnalnya sudah masuk daftar TIDAK ditampilkan lagi',
        $kel->first(fn ($r) => $r['row_type'] === 'entry' && $r['id'] === $e2) === null,
        'PPN-nya akan terhitung dua kali');
    $ok('PPN total tidak menghitung entri berjurnal dua kali',
        abs((float) $kel->sum('ppn') - (110000 + 330000)) < 0.01, (string) $kel->sum('ppn'));

    echo "\n=== 2. NO. FAKTUR PAJAK & NPWP KOSONG SAMPAI DIISI ===\n";

    $ok('No. Faktur Pajak baris keluaran kosong', $barisOut['tax_invoice_no'] === null);
    $ok('NPWP baris keluaran kosong', $barisOut['npwp'] === null);
    $ok('No. Faktur Pajak baris masukan kosong', $barisIn['tax_invoice_no'] === null);
    $ok('NPWP baris masukan kosong', $barisIn['npwp'] === null);
    $ok('belum ada entri tersimpan sama sekali',
        BsFakturPajakLineEntry::whereIn('journal_line_id', [$l2[1], $l3[2]])->count() === 0);

    echo "\n=== 3. SIMPAN SATUAN — MENEMPEL PADA BARISNYA ===\n";

    $ctl->updateNsfp($req(['type' => 'keluaran', 'invoice_id' => $l3[2], 'tax_invoice_no' => '010.000-31.00000001', 'npwp' => '01.234.567.8-901.000']));
    $ctl->updateNsfp($req(['type' => 'masukan', 'invoice_id' => $l2[1], 'tax_invoice_no' => '020.000-31.00000002', 'npwp' => '09.876.543.2-109.000']));

    $kel2 = $panggil('keluaranRows', $TAHUN, $BULAN);
    $mas2 = $panggil('masukanRows', $TAHUN, $BULAN);
    $ok('nomor faktur keluaran tersimpan pada barisnya',
        $kel2->firstWhere('id', $l3[2])['tax_invoice_no'] === '010.000-31.00000001');
    $ok('NPWP keluaran tersimpan pada barisnya',
        $kel2->firstWhere('id', $l3[2])['npwp'] === '01.234.567.8-901.000');
    $ok('nomor faktur masukan tersimpan pada barisnya',
        $mas2->firstWhere('id', $l2[1])['tax_invoice_no'] === '020.000-31.00000002');

    $jenis = BsFakturPajakLineEntry::whereIn('journal_line_id', [$l2[1], $l3[2]])
        ->pluck('jenis', 'journal_line_id');
    $ok('entri keluaran berjenis keluaran', ($jenis[$l3[2]] ?? null) === 'keluaran', (string) ($jenis[$l3[2]] ?? '—'));
    $ok('entri masukan berjenis masukan', ($jenis[$l2[1]] ?? null) === 'masukan', (string) ($jenis[$l2[1]] ?? '—'));

    // Baris LAIN tidak boleh ikut terisi.
    $ok('baris jurnal lain tetap kosong', $mas2->firstWhere('id', $l1[1])['tax_invoice_no'] === null);

    echo "\n=== 3b. DIKOSONGKAN KEMBALI = ENTRINYA DIHAPUS, BUKAN BARIS BERNILAI NULL ===\n";

    /* "Sudah pernah diisi lalu dikosongkan" harus tidak terbedakan dari "memang belum pernah
       diisi" — kalau entri kosong menetap, has_entry tetap true dan rekonsiliasi masa pajak
       menghitungnya sebagai baris yang sudah ditangani. */
    $ctl->updateNsfp($req(['type' => 'masukan', 'invoice_id' => $l2[1], 'tax_invoice_no' => '', 'npwp' => '']));
    $ok('entri dihapus saat kedua kolomnya dikosongkan',
        BsFakturPajakLineEntry::where('journal_line_id', $l2[1])->count() === 0);
    $masKosong = $panggil('masukanRows', $TAHUN, $BULAN);
    $ok('barisnya kembali bertanda belum pernah diisi',
        $masKosong->firstWhere('id', $l2[1])['has_entry'] === false);
    $ok('nomor fakturnya ikut kosong lagi', $masKosong->firstWhere('id', $l2[1])['tax_invoice_no'] === null);
    // Diisi ulang supaya tahapan berikutnya berangkat dari keadaan semula.
    $ctl->updateNsfp($req(['type' => 'masukan', 'invoice_id' => $l2[1], 'tax_invoice_no' => '020.000-31.00000002', 'npwp' => '09.876.543.2-109.000']));

    echo "\n=== 4. NPWP TIDAK DITULIS BALIK KE MASTER ===\n";

    $npwpSupplierSebelum = DB::table('bs_suppliers')->whereNotNull('npwp')->count();
    $npwpCustomerSebelum = DB::table('customers')->whereNotNull('npwp')->count();
    $ctl->updateNsfp($req(['type' => 'masukan', 'invoice_id' => $l1[1], 'tax_invoice_no' => 'X', 'npwp' => '99.999.999.9-999.999']));
    $ok('jumlah NPWP master supplier tidak berubah',
        DB::table('bs_suppliers')->whereNotNull('npwp')->count() === $npwpSupplierSebelum);
    $ok('tidak ada supplier yang ber-NPWP 99.999.999.9-999.999',
        DB::table('bs_suppliers')->where('npwp', '99.999.999.9-999.999')->count() === 0);
    $ok('jumlah NPWP master customer tidak berubah',
        DB::table('customers')->whereNotNull('npwp')->count() === $npwpCustomerSebelum);

    echo "\n=== 5. HAPUS HANYA MEMBUANG NOMORNYA ===\n";

    $ctl->hapusEntriBaris($req(['row_type' => 'jurnal', 'journal_line_id' => $l3[2]]));
    $kel3 = $panggil('keluaranRows', $TAHUN, $BULAN);
    $ok('nomor faktur baris itu kembali kosong', $kel3->firstWhere('id', $l3[2])['tax_invoice_no'] === null);
    $ok('NPWP baris itu kembali kosong', $kel3->firstWhere('id', $l3[2])['npwp'] === null);
    $ok('BARIS JURNALNYA masih ada', DB::table('bs_journal_lines')->where('id', $l3[2])->exists());
    $ok('jurnalnya masih ada', DB::table('bs_journals')->where('id', $j3)->whereNull('deleted_at')->exists());
    $ok('barisnya masih terdaftar di layar', $kel3->contains(fn ($r) => $r['row_type'] === 'jurnal' && $r['id'] === $l3[2]));
    $ok('entrinya benar-benar dihapus, bukan disimpan bernilai null',
        BsFakturPajakLineEntry::where('journal_line_id', $l3[2])->count() === 0);

    echo "\n=== 6. INPUT MASSAL HANYA MENYENTUH BARIS YANG DIKIRIM ===\n";

    $ctl->bulkKeluaranNsfp($req([
        'items' => [
            ['row_type' => 'jurnal', 'id' => $l3[2], 'tax_invoice_no' => '010.000-31.00000009', 'npwp' => '11.111.111.1-111.111'],
        ],
    ]));
    $kel4 = $panggil('keluaranRows', $TAHUN, $BULAN);
    $ok('baris yang dikirim terisi lewat input massal',
        $kel4->firstWhere('id', $l3[2])['tax_invoice_no'] === '010.000-31.00000009');
    $ok('NPWP ikut tersimpan lewat input massal',
        $kel4->firstWhere('id', $l3[2])['npwp'] === '11.111.111.1-111.111');

    $ctl->bulkNsfp($req(['items' => [['invoice_id' => $l2[1], 'tax_invoice_no' => '020.000-31.00000099', 'npwp' => '22.222.222.2-222.222']]]));
    $mas4 = $panggil('masukanRows', $TAHUN, $BULAN);
    $ok('input massal masukan menimpa baris yang dikirim',
        $mas4->firstWhere('id', $l2[1])['tax_invoice_no'] === '020.000-31.00000099');
    $ok('baris masukan yang TIDAK dikirim tidak berubah',
        $mas4->firstWhere('id', $l1[1])['tax_invoice_no'] === 'X', (string) $mas4->firstWhere('id', $l1[1])['tax_invoice_no']);

    // Entri UTJ & DP yang belum dikonversi tidak boleh diam-diam terisi lewat jalur massal.
    $ctl->bulkKeluaranNsfp($req(['items' => [['row_type' => 'entry', 'id' => $e1, 'tax_invoice_no' => '010.000-31.00000ZZ']]]));
    $ok('entri UTJ & DP yang belum dikonversi DILEWATI, bukan diisi',
        DB::table('bs_faktur_keluaran_entries')->where('id', $e1)->value('fp_number') === null);
    $ok('pesannya menyebut entri yang dilewati',
        str_contains((string) session('error'), 'dilewati'), (string) session('error'));

    echo "\n=== 7. FORMAT IMPOR & IMPOR BERKAS ===\n";

    $tarik = function ($response) {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    };

    $format = $tarik($ctl->formatImporKeluaran($req([], ['year' => $TAHUN, 'month' => $BULAN])));
    $barisFormat = array_values(array_filter(explode("\n", trim($format))));
    $judulFormat = str_getcsv($barisFormat[0]);
    $ok('format impor berjudul kolom JENIS_BARIS & ID_BARIS di depan',
        ($judulFormat[0] ?? '') === 'JENIS_BARIS' && ($judulFormat[1] ?? '') === 'ID_BARIS', json_encode($judulFormat));
    $ok('kolom terakhir format impor = NO_FAKTUR_PAJAK & NPWP',
        ($judulFormat[7] ?? '') === 'NO_FAKTUR_PAJAK' && ($judulFormat[8] ?? '') === 'NPWP', json_encode($judulFormat));
    $ok('format impor sudah terisi baris masa pajak ini', count($barisFormat) - 1 === $kel4->count(),
        (count($barisFormat) - 1).' baris berkas vs '.$kel4->count().' baris layar');

    // Impor dengan pemisah KOMA.
    $tulisBerkas = function (string $isi): string {
        $path = tempnam(sys_get_temp_dir(), 'ujifp').'.csv';
        file_put_contents($path, $isi);

        return $path;
    };
    $imporReq = function (string $path) use ($user) {
        $r = Request::create('/uji', 'POST');
        $r->files->set('file', new \Illuminate\Http\UploadedFile($path, basename($path), 'text/csv', null, true));
        $r->setUserResolver(fn () => $user);

        return $r;
    };

    $csvKoma = "JENIS_BARIS,ID_BARIS,NO_JURNAL_DOKUMEN,TANGGAL,NAMA,DPP,PPN,NO_FAKTUR_PAJAK,NPWP\n"
        ."jurnal,{$l3[2]},KONVERSI-UJI,{$TGL},Uji,1000000,110000,010.000-31.00000777,33.333.333.3-333.333\n";
    $ctl->imporKeluaran($imporReq($tulisBerkas($csvKoma)));
    $kel5 = $panggil('keluaranRows', $TAHUN, $BULAN);
    $ok('impor pemisah KOMA tersimpan', $kel5->firstWhere('id', $l3[2])['tax_invoice_no'] === '010.000-31.00000777',
        (string) $kel5->firstWhere('id', $l3[2])['tax_invoice_no']);
    $ok('NPWP dari berkas impor ikut tersimpan', $kel5->firstWhere('id', $l3[2])['npwp'] === '33.333.333.3-333.333');

    // Impor dengan pemisah TITIK-KOMA (Excel berlokal Indonesia) + BOM UTF-8.
    $csvTitikKoma = "\xEF\xBB\xBFJENIS_BARIS;ID_BARIS;NO_JURNAL_DOKUMEN;TANGGAL;NAMA;DPP;PPN;NO_FAKTUR_PAJAK;NPWP\r\n"
        ."jurnal;{$l3[2]};KONVERSI-UJI;{$TGL};Uji;1000000;110000;010.000-31.00000888;44.444.444.4-444.444\r\n";
    $ctl->imporKeluaran($imporReq($tulisBerkas($csvTitikKoma)));
    $kel6 = $panggil('keluaranRows', $TAHUN, $BULAN);
    $ok('impor pemisah TITIK-KOMA + BOM tersimpan',
        $kel6->firstWhere('id', $l3[2])['tax_invoice_no'] === '010.000-31.00000888',
        (string) $kel6->firstWhere('id', $l3[2])['tax_invoice_no']);

    // Berkas yang baris judulnya sudah dihapus orang — barisnya TIDAK boleh hilang diam-diam.
    $csvTanpaJudul = "\xEF\xBB\xBFjurnal,{$l3[2]},KONVERSI-UJI,{$TGL},Uji,1000000,110000,010.000-31.00000555,66.666.666.6-666.666\n";
    $ctl->imporKeluaran($imporReq($tulisBerkas($csvTanpaJudul)));
    $kelTJ = $panggil('keluaranRows', $TAHUN, $BULAN);
    $ok('berkas tanpa baris judul tetap terbaca seluruhnya',
        $kelTJ->firstWhere('id', $l3[2])['tax_invoice_no'] === '010.000-31.00000555',
        (string) $kelTJ->firstWhere('id', $l3[2])['tax_invoice_no']);

    // Berkas tanpa kolom kunci harus DITOLAK, bukan menyimpan sembarang baris.
    $sebelumTolak = $kelTJ->firstWhere('id', $l3[2])['tax_invoice_no'];
    $ctl->imporKeluaran($imporReq($tulisBerkas("NO_FAKTUR_PAJAK,NPWP\n010.000-31.00000999,55.555.555.5-555.555\n")));
    $kel7 = $panggil('keluaranRows', $TAHUN, $BULAN);
    $ok('berkas tanpa JENIS_BARIS/ID_BARIS tidak mengubah apa pun',
        $kel7->firstWhere('id', $l3[2])['tax_invoice_no'] === $sebelumTolak);
    /* Dan ia harus MENGATAKAN kenapa. Tanpa pesan ini, berkas salah format akan berakhir
       sebagai "Impor selesai: 0 baris" yang terbaca seperti keberhasilan. */
    $ok('berkas salah format ditolak dengan pesan yang menjelaskan',
        str_contains((string) session('error'), 'Tidak ada baris yang bisa dibaca'), (string) session('error'));

    echo "\n=== 8. EKSPOR SEJUMLAH BARIS YANG SAMA DENGAN LAYARNYA ===\n";

    $csvKel = $tarik($ctl->exportKeluaran($req([], ['year' => $TAHUN, 'month' => $BULAN])));
    $csvMas = $tarik($ctl->exportMasukan($req([], ['year' => $TAHUN, 'month' => $BULAN])));
    $barisCsvKel = count(array_filter(explode("\n", trim($csvKel)))) - 1;
    $barisCsvMas = count(array_filter(explode("\n", trim($csvMas)))) - 1;
    $ok('CSV Keluaran = jumlah baris layar', $barisCsvKel === $kel7->count(), "$barisCsvKel vs ".$kel7->count());
    $ok('CSV Masukan = jumlah baris layar', $barisCsvMas === $mas4->count(), "$barisCsvMas vs ".$mas4->count());
    $ok('CSV Masukan memuat jurnal memorial tanpa invoice', str_contains($csvMas, 'UJI/FP/IN-2'));
    $ok('CSV Keluaran memuat jurnal konversi tanpa AR invoice', str_contains($csvKel, 'KONVERSI-UJI'));
    $ok('CSV Keluaran tidak memuat jurnal terhapus', ! str_contains($csvKel, 'UJI/FP/OUT-2'));

    $xlsKel = $ctl->exportKeluaranXlsx($req([], ['year' => $TAHUN, 'month' => $BULAN]))->getContent();
    $xlsMas = $ctl->exportMasukanXlsx($req([], ['year' => $TAHUN, 'month' => $BULAN]))->getContent();
    // −1 baris judul kolom, −1 baris total.
    $ok('Excel Keluaran = jumlah baris layar', substr_count($xlsKel, '<tr') - 2 === $kel7->count(),
        (substr_count($xlsKel, '<tr') - 2).' vs '.$kel7->count());
    $ok('Excel Masukan = jumlah baris layar', substr_count($xlsMas, '<tr') - 2 === $mas4->count(),
        (substr_count($xlsMas, '<tr') - 2).' vs '.$mas4->count());
    $ok('Excel Masukan memuat jurnal memorial tanpa invoice', str_contains($xlsMas, 'UJI/FP/IN-2'));
    $ok('kolom identitas Excel dipaksa dibaca sebagai teks', str_contains($xlsKel, 'mso-number-format'));
    $ok('Excel Keluaran tetap membawa kolom Status Konversi', str_contains($xlsKel, 'Status Konversi'));

    /* Baris Total harus SELEBAR tabelnya. Menambah kolom keterangan tanpa menyesuaikan sel
       penutupnya membuat angka Total bergeser satu kolom — tanpa galat apa pun. */
    $lebarTabel = function (string $xls): array {
        preg_match('/<tr style="background:[^"]*">(.*?)<\/tr>/s', $xls, $mj);
        preg_match('/<tr style="font-weight:bold[^"]*">(.*?)<\/tr>/s', $xls, $mt);
        $judul = substr_count($mj[1] ?? '', '<th');
        $isi = $mt[1] ?? '';
        $total = (int) (preg_match('/colspan="(\d+)"/', $isi, $mc) ? $mc[1] : 1) + substr_count($isi, '<td') - 1;

        return [$judul, $total];
    };
    [$jKel, $tKel] = $lebarTabel($xlsKel);
    [$jMas, $tMas] = $lebarTabel($xlsMas);
    $ok('baris Total Excel Keluaran selebar tabelnya', $jKel === $tKel, "$jKel kolom judul vs $tKel kolom total");
    $ok('baris Total Excel Masukan selebar tabelnya', $jMas === $tMas, "$jMas kolom judul vs $tMas kolom total");

    echo "\n=== 9. REKAP MEMAKAI ANGKA DARI KEDUA DAFTAR ITU ===\n";

    $rekap = $ctl->rekap($reqInertia(['year' => $TAHUN, 'month' => $BULAN]))->toResponse($reqInertia())->getData(true)['props'];
    $ok('PPN Keluaran rekap = jumlah PPN daftar keluaran',
        abs((float) $rekap['keluaran']['ppn'] - (float) $kel7->sum('ppn')) < 0.01,
        $rekap['keluaran']['ppn'].' vs '.$kel7->sum('ppn'));
    $ok('PPN Masukan rekap = jumlah PPN daftar masukan',
        abs((float) $rekap['masukan']['ppn'] - (float) $mas4->sum('ppn')) < 0.01,
        $rekap['masukan']['ppn'].' vs '.$mas4->sum('ppn'));
    $ok('selisih rekap = keluaran − masukan',
        abs((float) $rekap['selisih'] - ((float) $kel7->sum('ppn') - (float) $mas4->sum('ppn'))) < 0.01);

    echo "\n=== 10. LAYAR MEMBAWA BEKAL YANG DIPAKAI TAMPILANNYA ===\n";

    $props = $ctl->keluaran($reqInertia(['year' => $TAHUN, 'month' => $BULAN]))->toResponse($reqInertia())->getData(true)['props'];
    $ok('layar keluaran mengirim peta label Sumber', isset($props['sources']['cash']) && $props['sources']['cash'] === 'Kas/Bank');
    $ok('layar keluaran menghitung baris yang belum ber-No. Faktur',
        $props['totals']['tanpa_fp_count'] === $kel7->filter(fn ($r) => $r['tax_invoice_no'] === null)->count());
    $ok('jumlah baris jurnal + entri approval = jumlah baris',
        $props['totals']['jurnal_count'] + $props['totals']['entry_count'] === $props['totals']['count']);
} finally {
    DB::rollBack();
}

echo "\n────────────────────────────────\n";
echo "LULUS: $lulus   GAGAL: $gagal\n";
echo $gagal === 0 ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
