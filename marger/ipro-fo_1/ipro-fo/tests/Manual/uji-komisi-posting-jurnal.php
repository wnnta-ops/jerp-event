<?php

declare(strict_types=1);

/*
 * Uji Posting BO Realisasi Komisi → Posting Invoice → jurnal AP.
 *
 * Sama seperti BLT, dua hal yang dijaga:
 *
 *  1. TIDAK ADA KOMISI YANG TERTINGGAL. Sebelumnya satu pengajuan (mis. KOMPENG-202609-00001,
 *     berisi 3 komisi untuk 3 pembeli) dikirim sebagai SATU baris antrian berisi total NETT,
 *     dengan nama pembelinya diringkas jadi satu untai. Hasilnya satu invoice gelondongan:
 *     tidak terbaca milik siapa, dan tidak bisa dibayarkan ke rekening masing-masing agen.
 *
 *  2. POTONGAN PPh 23 TIDAK BOLEH HILANG. Jurnal lama hanya Dr Komisi / Cr Utang Usaha
 *     sebesar BRUTO — PPh yang dipotong dan tercetak di permohonannya tidak muncul di buku
 *     besar sama sekali, dan hutang ke agen kelebihan sebesar potongannya.
 *
 * Acuan angkanya "Jurnal AP untuk KOMISI.xlsx" — dicocokkan BARIS DEMI BARIS: akun,
 * keterangan, debit, dan kredit. Dua jalur posting diuji: satuan (fromRefund) dan gabungan
 * (bulkRefund), karena keduanya membangun sisi kredit dengan kode yang berbeda.
 *
 * BEDA DENGAN BLT: keterangan komisi tidak memuat kata jenjang ("Komisi <pengajuan> …",
 * bukan "BLT Sales <pengajuan> …"), dan pasalnya selalu PPh 23 — komisi dihitung sebagai
 * PPh atas jasa oleh KomisiCalculatorService.
 *
 * Mesin pecahan kreditnya sendiri diuji tuntas di tests/Manual/uji-blt-posting-jurnal.php
 * (penolakan rincian meleset, akun bentrok, kode akun asing, sumber lama tak berubah);
 * berkas ini menguji bahwa jalur SUMBER KOMISI benar-benar memakainya.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan:  docker exec ipro-fo-app php tests/Manual/uji-komisi-posting-jurnal.php
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

    $sup = BsSupplier::create(['code' => 'UJI-KOM-1', 'name' => 'UJI Agen Komisi', 'is_active' => true]);

    // ── Isi pengajuan KOMPENG-202609-00001, persis seperti di Excel-nya ───────────────────
    // [pembeli, unit, bruto, pph]
    $PENGAJUAN = 'KOMPENG-202609-00001';
    $isi = [
        ['ARIE YOGI AN',     'JRK3-004-XVIII-21',  9346667.0, 186934.0],
        ['MOCHAMAD NASHIR',  'JRK3-004-XIX-21',   11044792.0, 220896.0],
        ['DEDE SANJAYA',     'JRK3-004-XIX-27',   11044792.0, 220896.0],
    ];

    /**
     * Bentuk baris antrian PERSIS seperti yang ditulis sisi FO
     * (App\Modules\BO\IproBridge\Services\IproApPoster::queueKomisiRow). Bila sisi FO diubah,
     * uji pendampingnya di repo FO — tests/Manual/uji-komisi-antre-per-baris.php — yang gagal.
     */
    $antre = function (array $b, int $ke) use ($PENGAJUAN, $tenantId, $KOMISI, $UTANG) {
        [$pembeli, $unit, $bruto, $pph] = $b;
        $nett = round($bruto - $pph, 2);
        $split = [['side' => 'credit', 'role' => 'payable', 'code' => '20101010101', 'amount' => $nett, 'label' => 'Hutang Usaha']];
        if ($pph > 0) {
            $split[] = ['side' => 'credit', 'role' => 'pph', 'code' => '20104040403', 'amount' => $pph, 'label' => 'Hutang PPh 23'];
        }

        return (int) DB::table('bs_ap_refund_queue')->insertGetId([
            'tenant_id' => $tenantId, 'kind' => 'komisi',
            'refund_no' => $PENGAJUAN.'#KOM-UJI-'.$ke,
            'customer_name' => $pembeli, 'amount' => $bruto,
            'payable_account_id' => $UTANG, 'offset_account_id' => $KOMISI,
            'account_split' => json_encode($split),
            'tgl_refund' => '2026-09-01',
            'keterangan' => 'Komisi '.$PENGAJUAN.' '.$pembeli.', '.$unit,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $ids = [];
    foreach ($isi as $i => $b) {
        $ids[] = $antre($b, $i + 1);
    }

    /** Harapan 3 baris jurnal untuk satu komisi — bentuk yang diminta Excel-nya. */
    $harapan = function (array $b) use ($PENGAJUAN, $KOMISI, $UTANG, $PPH23) {
        [$pembeli, $unit, $bruto, $pph] = $b;
        $ket = 'Komisi '.$PENGAJUAN.' '.$pembeli.', '.$unit;

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

    // ═════════════════════════════════════════════════════════════════════════════════════
    echo "\n=== 1. Posting satuan: tiap komisi jadi invoice & jurnalnya sendiri ===\n";

    $invoices = [];
    foreach ($ids as $i => $id) {
        $posting($id);
        $q = DB::table('bs_ap_refund_queue')->where('id', $id)->first();
        $cek('komisi '.($i + 1).' terposting', ($q->status ?? '') === 'posted' && ! empty($q->ap_invoice_id),
            'status='.($q->status ?? '?'));
        $invoices[$i] = BsApInvoice::find($q->ap_invoice_id);
    }
    $cek('3 komisi → 3 invoice berbeda (tidak ada yang digabung/tertinggal)',
        count(array_unique(array_map(fn ($v) => $v?->id, $invoices))) === 3);

    echo "\n=== 2. Jurnalnya sama persis dengan Excel — baris demi baris ===\n";

    foreach ($isi as $i => $b) {
        $dapat = $barisJurnal($invoices[$i]);
        $mau   = $harapan($b);
        $sama  = $dapat === $mau;
        $cek('jurnal komisi '.($i + 1).' ('.$b[0].') sama persis',
            $sama, $sama ? '' : "\n        dapat  : ".json_encode($dapat, JSON_UNESCAPED_UNICODE)
                ."\n        harusnya: ".json_encode($mau, JSON_UNESCAPED_UNICODE));
    }

    echo "\n=== 3. Sisa tagihan AP = kredit Utang Usaha di jurnalnya ===\n";

    // Kalau invoice menagih BRUTO sementara jurnalnya mengakui NETT, Aging Hutang dan Buku
    // Besar berbeda angka — persis celah yang menghilangkan PPh dari pembukuan.
    foreach ($isi as $i => $b) {
        [, , $bruto, $pph] = $b;
        $inv = $invoices[$i];
        $kreditUtang = round((float) DB::table('bs_journal_lines')
            ->where('journal_id', $inv->journal_id)->where('account_id', $UTANG)->sum('credit'), 2);
        $cek('komisi '.($i + 1).': net_payable = kredit Utang Usaha',
            round((float) $inv->net_payable, 2) === $kreditUtang && $kreditUtang === round($bruto - $pph, 2),
            'net_payable='.$inv->net_payable.' kredit='.$kreditUtang);
        $cek('komisi '.($i + 1).': PPh 23 tercatat di invoice-nya',
            round((float) $inv->pph_amount, 2) === $pph && $inv->pph_mode === 'withheld'
                && (int) $inv->pph_account_id === $PPH23,
            'pph_amount='.$inv->pph_amount.' mode='.$inv->pph_mode.' akun='.$inv->pph_account_id);
        $cek('komisi '.($i + 1).': total tetap BRUTO (beban diakui penuh)',
            round((float) $inv->total, 2) === $bruto, 'total='.$inv->total);
    }

    // ═════════════════════════════════════════════════════════════════════════════════════
    echo "\n=== 4. Posting gabungan: satu invoice, rincian kredit tetap per komisi ===\n";

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
    $dapatGab = $invGab ? $barisJurnal($invGab) : [];
    $samaGab = $dapatGab === $mauGab;
    $cek('jurnal gabungan = gabungan jurnal ketiganya, tanpa ada yang diringkas', $samaGab,
        $samaGab ? '' : "\n        dapat  : ".json_encode($dapatGab, JSON_UNESCAPED_UNICODE)
            ."\n        harusnya: ".json_encode($mauGab, JSON_UNESCAPED_UNICODE));

    $totalBruto = array_sum(array_column($isi, 2));
    $totalPph   = array_sum(array_column($isi, 3));
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

    echo "\n=== 6. Sumber tetap terbaca Komisi ===\n";

    // Sumber dipakai layar Posting Invoice & penelusuran balik dari invoice ke dokumennya.
    // Bila tertukar dengan BLT, komisi tidak lagi bisa ditemukan dari sumbernya.
    foreach ($invoices as $i => $inv) {
        $cek('invoice '.($i + 1).' bersumber komisi', $inv->source_type === 'komisi', (string) $inv->source_type);
    }

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
