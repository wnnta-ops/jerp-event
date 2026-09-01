<?php

declare(strict_types=1);

/*
 * Uji Posting BO Realisasi BLT → Posting Invoice → jurnal AP.
 *
 * Yang dijaga berkas ini ada dua, dan keduanya pernah salah:
 *
 *  1. TIDAK ADA BLT YANG TERTINGGAL. Sebelumnya satu pengajuan (mis. BLTPENG-202609-00001,
 *     berisi 3 BLT untuk 2 pembeli) dikirim sebagai SATU baris antrian berisi total NETT,
 *     dengan nama pembelinya diringkas jadi satu untai. Hasilnya satu invoice gelondongan:
 *     tidak terbaca milik siapa, dan tidak bisa dibayarkan ke rekening masing-masing
 *     penerima. Sekarang tiap BLT jadi barisnya sendiri.
 *
 *  2. POTONGAN PPh TIDAK BOLEH HILANG. Jurnal lama hanya Dr Komisi / Cr Utang Usaha sebesar
 *     BRUTO — PPh yang dipotong dan tercetak di permohonannya tidak muncul di buku besar
 *     sama sekali, dan hutang ke penerima kelebihan sebesar potongannya. Sekarang tiap BLT
 *     berjurnal tiga baris: Dr beban bruto, Cr Utang Usaha nett, Cr Hutang PPh sisanya.
 *
 * Acuan angkanya "Jurnal AP untuk BLT.xlsx" — dicocokkan BARIS DEMI BARIS: akun, keterangan,
 * debit, dan kredit. Dua jalur posting diuji: satuan (fromRefund) dan gabungan (bulkRefund),
 * karena keduanya membangun sisi kredit dengan kode yang berbeda.
 *
 * Diuji juga bahwa sumber LAMA (gimmick/komisi tanpa rincian kredit) tidak ikut berubah, dan
 * bahwa rincian kredit yang tidak sama dengan nilai antriannya DITOLAK — bukan dibulatkan
 * diam-diam menjadi jurnal pincang.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan:  docker exec ipro-fo-app php tests/Manual/uji-blt-posting-jurnal.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Http\Controllers\Web\BusinessSupport\Payable\PostingInvoiceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

/** Akun menurut kodenya — supaya harapan uji ditulis dengan KODE seperti di Excel-nya. */
$akun = fn (string $kode) => (int) DB::table('bs_accounts')->where('code', $kode)->value('id');

$KOMISI = $akun('50201010103');   // KOMISI/INSENTIF PENJUALAN
$UTANG  = $akun('20101010101');   // UTANG USAHA SUPPLIER
$PPH23  = $akun('20104040403');   // HUTANG PPH PASAL 23

DB::beginTransaction();

try {
    $userId = (int) DB::table('users')->orderBy('id')->value('id');
    $user   = \App\Models\User::query()->find($userId);
    auth()->setUser($user);

    if (! $KOMISI || ! $UTANG || ! $PPH23) {
        throw new RuntimeException('akun 50201010103 / 20101010101 / 20104040403 tidak ada di Bagan Akun');
    }

    $sup = BsSupplier::create(['code' => 'UJI-BLT-1', 'name' => 'UJI Penerima BLT', 'is_active' => true]);

    // ── Isi pengajuan BLTPENG-202609-00001, persis seperti di Excel-nya ───────────────────
    // [jenis, pembeli, unit, bruto, pph]
    $PENGAJUAN = 'BLTPENG-202609-00001';
    $isi = [
        ['Sales',       'DIDIK KEMBUNG',                   'JRK3-004-XVI-11', 25000000.0, 625000.0],
        ['Sales',       'REYZA FERNANDYA PUTRI PRASETYO',  'JRK3-004-XVI-20', 25000000.0, 625000.0],
        ['Koordinator', 'REYZA FERNANDYA PUTRI PRASETYO',  'JRK3-004-XVI-20',  2500000.0,  62500.0],
    ];

    /**
     * Bentuk baris antrian PERSIS seperti yang ditulis sisi FO
     * (App\Modules\BO\IproBridge\Services\IproApPoster::queueBltRow). Bila sisi FO diubah,
     * uji pendampingnya di repo FO — tests/Manual/uji-blt-antre-per-baris.php — yang gagal.
     */
    $antre = function (array $b, int $ke) use ($PENGAJUAN, $tenantId, $KOMISI, $UTANG) {
        [$jenis, $pembeli, $unit, $bruto, $pph] = $b;
        $nett = round($bruto - $pph, 2);
        $split = [['side' => 'credit', 'role' => 'payable', 'code' => '20101010101', 'amount' => $nett, 'label' => 'Hutang Usaha']];
        if ($pph > 0) {
            $split[] = ['side' => 'credit', 'role' => 'pph', 'code' => '20104040403', 'amount' => $pph, 'label' => 'Hutang PPh 23'];
        }

        return (int) DB::table('bs_ap_refund_queue')->insertGetId([
            'tenant_id' => $tenantId, 'kind' => 'blt',
            'refund_no' => $PENGAJUAN.'#BLT-UJI-'.$ke,
            'customer_name' => $pembeli, 'amount' => $bruto,
            'payable_account_id' => $UTANG, 'offset_account_id' => $KOMISI,
            'account_split' => json_encode($split),
            'tgl_refund' => '2026-09-01',
            'keterangan' => 'BLT '.$jenis.' '.$PENGAJUAN.' '.$pembeli.', '.$unit,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $ids = [];
    foreach ($isi as $i => $b) {
        $ids[] = $antre($b, $i + 1);
    }

    /** Harapan 3 baris jurnal untuk satu BLT — bentuk yang diminta Excel-nya. */
    $harapan = function (array $b) use ($PENGAJUAN, $KOMISI, $UTANG, $PPH23) {
        [$jenis, $pembeli, $unit, $bruto, $pph] = $b;
        $ket = 'BLT '.$jenis.' '.$PENGAJUAN.' '.$pembeli.', '.$unit;

        return [
            [$KOMISI, $ket,                       $bruto, 0.0],
            [$UTANG,  'Hutang Usaha '.$ket,       0.0,    round($bruto - $pph, 2)],
            [$PPH23,  'Hutang PPh 23 '.$ket,      0.0,    $pph],
        ];
    };

    /** Baris jurnal sebuah invoice, urut seperti dibuat. */
    $barisJurnal = fn (BsApInvoice $inv) => DB::table('bs_journal_lines')
        ->where('journal_id', $inv->journal_id)->orderBy('id')
        ->get(['account_id', 'description', 'debit', 'credit'])
        ->map(fn ($l) => [(int) $l->account_id, (string) $l->description, round((float) $l->debit, 2), round((float) $l->credit, 2)])
        ->all();

    $ctrl = new PostingInvoiceController();
    $journal = app(JournalService::class);
    $posting = function (int $queueId) use ($ctrl, $journal, $user, $sup) {
        $req = Request::create('/', 'POST', ['supplier_id' => $sup->id]);
        $req->setUserResolver(fn () => $user);

        return $ctrl->fromRefund($req, $journal, $queueId);
    };
    /**
     * Posting yang BOLEH ditolak. Penolakannya harus rapi — layar memulangkan pesan galat,
     * bukan melemparkan exception. Antrian yang meledak di tengah transaksi menggagalkan
     * seluruh posting massal yang sedang berjalan, termasuk baris-baris yang sebenarnya sah.
     */
    $postingBolehTolak = function (int $queueId) use ($posting, &$ledak) {
        $ledak = null;
        try {
            $posting($queueId);
        } catch (\Throwable $e) {
            $ledak = $e->getMessage();
        }
    };

    // ═════════════════════════════════════════════════════════════════════════════════════
    echo "\n=== 1. Posting satuan: tiap BLT jadi invoice & jurnalnya sendiri ===\n";

    $invoices = [];
    foreach ($ids as $i => $id) {
        $posting($id);
        $q = DB::table('bs_ap_refund_queue')->where('id', $id)->first();
        $cek('BLT '.($i + 1).' terposting', ($q->status ?? '') === 'posted' && ! empty($q->ap_invoice_id),
            'status='.($q->status ?? '?'));
        $invoices[$i] = BsApInvoice::find($q->ap_invoice_id);
    }
    $cek('3 BLT → 3 invoice berbeda (tidak ada yang digabung/tertinggal)',
        count(array_unique(array_map(fn ($v) => $v?->id, $invoices))) === 3);

    echo "\n=== 2. Jurnalnya sama persis dengan Excel — baris demi baris ===\n";

    foreach ($isi as $i => $b) {
        $dapat = $barisJurnal($invoices[$i]);
        $mau   = $harapan($b);
        $sama  = $dapat === $mau;
        $cek('jurnal BLT '.($i + 1).' ('.$b[0].' — '.$b[1].') sama persis',
            $sama, $sama ? '' : "\n        dapat  : ".json_encode($dapat, JSON_UNESCAPED_UNICODE)
                ."\n        harusnya: ".json_encode($mau, JSON_UNESCAPED_UNICODE));
    }

    echo "\n=== 3. Sisa tagihan AP = kredit Utang Usaha di jurnalnya ===\n";

    // Kalau invoice menagih BRUTO sementara jurnalnya mengakui NETT, Aging Hutang dan Buku
    // Besar berbeda angka — persis celah yang menghilangkan PPh dari pembukuan.
    foreach ($isi as $i => $b) {
        [, , , $bruto, $pph] = $b;
        $inv = $invoices[$i];
        $kreditUtang = round((float) DB::table('bs_journal_lines')
            ->where('journal_id', $inv->journal_id)->where('account_id', $UTANG)->sum('credit'), 2);
        $cek('BLT '.($i + 1).': net_payable = kredit Utang Usaha',
            round((float) $inv->net_payable, 2) === $kreditUtang && $kreditUtang === round($bruto - $pph, 2),
            'net_payable='.$inv->net_payable.' kredit='.$kreditUtang);
        $cek('BLT '.($i + 1).': PPh tercatat di invoice-nya',
            round((float) $inv->pph_amount, 2) === $pph && $inv->pph_mode === 'withheld'
                && (int) $inv->pph_account_id === $PPH23,
            'pph_amount='.$inv->pph_amount.' mode='.$inv->pph_mode);
        $cek('BLT '.($i + 1).': total tetap BRUTO (beban diakui penuh)',
            round((float) $inv->total, 2) === $bruto, 'total='.$inv->total);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    echo "\n=== 4. Posting gabungan: satu invoice, rincian kredit tetap per BLT ===\n";

    $ids2 = [];
    foreach ($isi as $i => $b) {
        $ids2[] = $antre($b, 10 + $i);
    }
    $reqBulk = Request::create('/', 'POST', [
        'items' => array_map(fn ($id) => ['id' => $id, 'supplier_id' => $sup->id], $ids2),
    ]);
    $reqBulk->setUserResolver(fn () => $user);
    $ctrl->bulkRefund($reqBulk, $journal);

    $invGab = BsApInvoice::find((int) DB::table('bs_ap_refund_queue')->where('id', $ids2[0])->value('ap_invoice_id'));
    $cek('ketiganya masuk ke SATU invoice gabungan', $invGab !== null
        && DB::table('bs_ap_refund_queue')->whereIn('id', $ids2)->distinct()->count('ap_invoice_id') === 1);

    $mauGab = [];
    foreach ($isi as $b) {
        foreach ($harapan($b) as $baris) {
            $mauGab[] = $baris;
        }
    }
    $dapatGab = $barisJurnal($invGab);
    // Urutan di jurnal gabungan: per item (debit lalu kreditnya) — sama dengan urutan harapan.
    $samaGab = $dapatGab === $mauGab;
    $cek('jurnal gabungan = gabungan jurnal ketiganya, tanpa ada yang diringkas', $samaGab,
        $samaGab ? '' : "\n        dapat  : ".json_encode($dapatGab, JSON_UNESCAPED_UNICODE)
            ."\n        harusnya: ".json_encode($mauGab, JSON_UNESCAPED_UNICODE));

    $totalBruto = array_sum(array_column($isi, 3));
    $totalPph   = array_sum(array_column($isi, 4));
    $cek('invoice gabungan: net_payable = total − PPh',
        round((float) $invGab->net_payable, 2) === round($totalBruto - $totalPph, 2),
        'net_payable='.$invGab->net_payable.' harusnya='.($totalBruto - $totalPph));
    $cek('invoice gabungan: PPh terjumlah benar',
        round((float) $invGab->pph_amount, 2) === round($totalPph, 2), 'pph='.$invGab->pph_amount);

    echo "\n=== 5. Jurnalnya balance ===\n";

    foreach (array_merge($invoices, [$invGab]) as $i => $inv) {
        $d = round((float) DB::table('bs_journal_lines')->where('journal_id', $inv->journal_id)->sum('debit'), 2);
        $k = round((float) DB::table('bs_journal_lines')->where('journal_id', $inv->journal_id)->sum('credit'), 2);
        $cek('jurnal #'.($i + 1).' balance', $d === $k && $d > 0, "debit={$d} kredit={$k}");
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    echo "\n=== 6. Sumber lama tidak ikut berubah ===\n";

    // Antrian TANPA rincian kredit — bentuk seluruh sumber yang sudah ada (gimmick, komisi,
    // refund). Jurnalnya harus tetap Dr lawan / Cr Utang Usaha penuh, dengan keterangan
    // ber-em dash seperti sebelumnya.
    $idLama = (int) DB::table('bs_ap_refund_queue')->insertGetId([
        'tenant_id' => $tenantId, 'kind' => 'gmc', 'refund_no' => 'UJI-GMC-LAMA-1',
        'customer_name' => 'PEMBELI LAMA', 'amount' => 3000000,
        'payable_account_id' => $UTANG, 'offset_account_id' => $KOMISI, 'account_split' => null,
        'tgl_refund' => '2026-09-01', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $posting($idLama);
    $invLama = BsApInvoice::find((int) DB::table('bs_ap_refund_queue')->where('id', $idLama)->value('ap_invoice_id'));
    $barisLama = $barisJurnal($invLama);

    $cek('sumber lama tetap 2 baris (Dr lawan / Cr Utang Usaha penuh)',
        count($barisLama) === 2 && $barisLama[0][2] === 3000000.0 && $barisLama[1][3] === 3000000.0,
        json_encode($barisLama, JSON_UNESCAPED_UNICODE));
    $cek('keterangan kredit sumber lama masih berbentuk "Hutang X — ..."',
        str_starts_with($barisLama[1][1], 'Hutang Gimmick — '), $barisLama[1][1]);
    $cek('sumber lama: net_payable tetap = total, tanpa PPh',
        round((float) $invLama->net_payable, 2) === 3000000.0 && $invLama->pph_mode === 'none'
            && round((float) $invLama->pph_amount, 2) === 0.0,
        'net='.$invLama->net_payable.' mode='.$invLama->pph_mode);

    // ═════════════════════════════════════════════════════════════════════════════════════
    echo "\n=== 7. Rincian kredit yang meleset DITOLAK ===\n";

    // Menerima rincian yang jumlahnya beda dari nilai antriannya berarti menerbitkan jurnal
    // pincang atau membuang selisihnya diam-diam. Keduanya lebih buruk daripada satu baris
    // yang gagal dan terlihat.
    $idSalah = (int) DB::table('bs_ap_refund_queue')->insertGetId([
        'tenant_id' => $tenantId, 'kind' => 'blt', 'refund_no' => 'UJI-BLT-MELESET',
        'customer_name' => 'X', 'amount' => 1000000,
        'payable_account_id' => $UTANG, 'offset_account_id' => $KOMISI,
        'account_split' => json_encode([
            ['side' => 'credit', 'role' => 'payable', 'code' => '20101010101', 'amount' => 900000, 'label' => 'Hutang Usaha'],
            // sengaja kurang 50.000 dari nilai antriannya
            ['side' => 'credit', 'role' => 'pph', 'code' => '20104040403', 'amount' => 50000, 'label' => 'Hutang PPh 23'],
        ]),
        'tgl_refund' => '2026-09-01', 'keterangan' => 'UJI meleset',
        'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $postingBolehTolak($idSalah);
    $qSalah = DB::table('bs_ap_refund_queue')->where('id', $idSalah)->first();
    $cek('antrian dengan rincian kredit meleset tidak terposting',
        ($qSalah->status ?? '') !== 'posted' && empty($qSalah->ap_invoice_id),
        'status='.($qSalah->status ?? '?'));
    $cek('penolakannya rapi — bukan exception di tengah transaksi', $ledak === null, (string) $ledak);

    // Akun kredit yang juga dipakai di sisi debit — nilainya saling meniadakan.
    $idBentrok = (int) DB::table('bs_ap_refund_queue')->insertGetId([
        'tenant_id' => $tenantId, 'kind' => 'blt', 'refund_no' => 'UJI-BLT-BENTROK',
        'customer_name' => 'X', 'amount' => 1000000,
        'payable_account_id' => $UTANG, 'offset_account_id' => $KOMISI,
        'account_split' => json_encode([
            ['side' => 'credit', 'role' => 'payable', 'code' => '50201010103', 'amount' => 1000000, 'label' => 'Hutang Usaha'],
        ]),
        'tgl_refund' => '2026-09-01', 'keterangan' => 'UJI bentrok',
        'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $postingBolehTolak($idBentrok);
    $cek('akun yang dipakai di debit sekaligus kredit ditolak',
        DB::table('bs_ap_refund_queue')->where('id', $idBentrok)->value('status') !== 'posted');
    $cek('penolakan akun bentrok rapi', $ledak === null, (string) $ledak);

    // Akun disebut dengan kode yang tidak ada di Bagan Akun.
    $idAkunHilang = (int) DB::table('bs_ap_refund_queue')->insertGetId([
        'tenant_id' => $tenantId, 'kind' => 'blt', 'refund_no' => 'UJI-BLT-AKUN-HILANG',
        'customer_name' => 'X', 'amount' => 1000000,
        'payable_account_id' => $UTANG, 'offset_account_id' => $KOMISI,
        'account_split' => json_encode([
            ['side' => 'credit', 'role' => 'payable', 'code' => '99999999999', 'amount' => 1000000, 'label' => 'Hutang Usaha'],
        ]),
        'tgl_refund' => '2026-09-01', 'keterangan' => 'UJI akun hilang',
        'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $postingBolehTolak($idAkunHilang);
    $cek('kode akun yang tidak ada di Bagan Akun ditolak',
        DB::table('bs_ap_refund_queue')->where('id', $idAkunHilang)->value('status') !== 'posted');
    $cek('penolakan kode akun asing rapi', $ledak === null, (string) $ledak);

    echo "\n".str_repeat('─', 78)."\n";
    echo $gagal === 0 ? "SEMUA LULUS ({$lulus})\n" : "GAGAL: {$gagal}, lulus: {$lulus}\n";
} catch (\Throwable $e) {
    echo "\nGALAT: ".$e->getMessage()."\n  di ".$e->getFile().':'.$e->getLine()."\n";
    $gagal++;
} finally {
    DB::rollBack();
    echo "(data uji dibatalkan)\n";
}

exit($gagal === 0 ? 0 : 1);
