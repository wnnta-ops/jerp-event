<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BankLoan;

use App\Domain\BankLoan\Models\KprClusterOutstanding;
use App\Domain\BankLoan\Models\KprClusterStatus;
use App\Http\Controllers\Controller;
use App\Support\Import\TabelImpor;
use App\Support\Xlsx\SimpleXlsx;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * REALISASI KPR PER CLUSTER — dua tabel dari Exsum Collection, diisi lewat import Excel.
 *
 * KENAPA DIIMPOR, BUKAN DIHITUNG. Sebagian kolomnya memang tidak bisa diturunkan dari basis data
 * mana pun: "ESCROW / SISA RETENSI" berisi kalimat seperti "Sisa Retensi 4,2 M · MUI 5", dan
 * "LAIN-LAIN" berisi "Clear" atau "Retensi TO + BAST". Itu catatan yang ditulis orang saat
 * menyusun Exsum, bukan angka yang bisa dihitung mesin. Kolom seperti HOLD dan OPEN STOCK pun
 * tidak punya padanan transaksi di aplikasi ini.
 *
 * Berbeda dari layar Realisasi KPR per Bank, yang justru DIHITUNG karena seluruh kolomnya
 * memang ada di data akad.
 *
 * IMPORT MENGGANTI SELURUH ISI TABELNYA, bukan menambahkan. Exsum adalah potret bulanan: baris
 * cluster yang sudah tidak ada lagi di Exsum terbaru harus ikut hilang, bukan tertinggal
 * sebagai sisa bulan lalu yang diam-diam ikut terbaca sebagai keadaan sekarang.
 */
class KprRealisasiClusterController extends Controller
{
    public const HEADER_STATUS = ['URUTAN', 'CLUSTER', 'TOTAL_UNIT', 'REALISASI', 'KPR', 'CASH', 'PROSES', 'OPEN_STOCK', 'HOLD', 'ESCROW', 'CATATAN'];
    public const HEADER_OS     = ['URUTAN', 'CLUSTER', 'RETENSI', 'PROSES', 'OPEN_STOK', 'LAIN_LAIN', 'CATATAN'];

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);

        $status = KprClusterStatus::orderBy('urutan')->orderBy('id')->get();
        $os     = KprClusterOutstanding::orderBy('urutan')->orderBy('id')->get();

        return Inertia::render('BankLoan/Database/RealisasiPerCluster', [
            'status' => $status->map(fn ($r) => [
                'label' => $r->label, 'total_unit' => $r->total_unit, 'realisasi' => $r->realisasi,
                'kpr' => $r->kpr, 'cash' => $r->cash, 'proses' => $r->proses,
                'open_stock' => $r->open_stock, 'hold' => $r->hold, 'escrow' => $r->escrow,
            ])->values(),
            'statusTotal' => [
                'total_unit' => (int) $status->sum('total_unit'),
                'realisasi'  => (int) $status->sum('realisasi'),
                'kpr'        => (int) $status->sum('kpr'),
                'cash'       => (int) $status->sum('cash'),
                'proses'     => (int) $status->sum('proses'),
                'open_stock' => (int) $status->sum('open_stock'),
                'hold'       => (int) $status->sum('hold'),
            ],
            // Catatan kaki diambil dari baris pertama yang mengisinya — Exsum menuliskannya
            // sekali untuk seluruh tabel, bukan per baris.
            'statusCatatan' => $status->firstWhere(fn ($r) => filled($r->catatan))?->catatan,
            'os' => $os->map(fn ($r) => [
                'label' => $r->label, 'retensi' => $r->retensi, 'proses' => $r->proses,
                'open_stok' => $r->open_stok, 'lain_lain' => $r->lain_lain,
            ])->values(),
            'osCatatan' => $os->firstWhere(fn ($r) => filled($r->catatan))?->catatan,
        ]);
    }

    /** Template import — dua berkas terpisah, dipilih lewat ?jenis=. */
    public function template(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);

        $status = $request->query('jenis') !== 'os';

        $rows = $status
            ? [self::HEADER_STATUS, ['1', 'Cluster 2.2 (JRK2 T2)', '169', '157', '158', '9', '0', '2', '0', 'Escrow 132 jt', 'CONTOH — hapus baris ini sebelum mengisi data Anda']]
            : [self::HEADER_OS, ['1', 'JRK 2 — Tahap 2', 'Rp 132 jt', '-', 'Rp 1,2 M (2 unit RUHA)', 'Retensi TO + BAST', 'CONTOH — hapus baris ini sebelum mengisi data Anda']];

        return response(SimpleXlsx::toString($rows), 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Template-KPR-per-Cluster-'.($status ? 'Status' : 'Outstanding').'.xlsx"',
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);
        $request->validate([
            'jenis' => ['required', 'in:status,os'],
            'file'  => ['required', 'file', 'max:8192'],
        ]);

        $status = $request->input('jenis') === 'status';

        $ext = strtolower((string) $request->file('file')->getClientOriginalExtension());
        if (! in_array($ext, ['xlsx', 'csv', 'txt'], true)) {
            return back()->with('error', 'Format file harus .xlsx atau .csv. Berkas .xls lama tidak didukung — simpan ulang sebagai .xlsx.');
        }

        try {
            $rows = TabelImpor::baca($request->file('file'));
        } catch (\Throwable $e) {
            return back()->with('error', 'File tidak dapat dibaca: '.$e->getMessage());
        }
        if (count($rows) < 2) {
            return back()->with('error', 'File kosong atau hanya berisi baris header.');
        }

        $header  = TabelImpor::header(array_shift($rows));
        $iLabel  = TabelImpor::kolom($header, ['CLUSTER', 'TAHAP', 'LABEL']);
        if ($iLabel === null) {
            return back()->with('error', 'Kolom wajib tidak ditemukan: CLUSTER. Unduh template untuk format yang benar.');
        }
        $iUrut    = TabelImpor::kolom($header, ['URUTAN', 'NO']);
        $iCatatan = TabelImpor::kolom($header, ['CATATAN', 'KETERANGAN']);

        $kol = $status
            ? [
                'total_unit' => TabelImpor::kolom($header, ['TOTAL_UNIT', 'TOTAL UNIT', 'TOTAL']),
                'realisasi'  => TabelImpor::kolom($header, ['REALISASI']),
                'kpr'        => TabelImpor::kolom($header, ['KPR'], ['TOTAL']),
                'cash'       => TabelImpor::kolom($header, ['CASH']),
                'proses'     => TabelImpor::kolom($header, ['PROSES']),
                'open_stock' => TabelImpor::kolom($header, ['OPEN_STOCK', 'OPEN STOCK', 'OPEN']),
                'hold'       => TabelImpor::kolom($header, ['HOLD']),
            ]
            : [
                'retensi'   => TabelImpor::kolom($header, ['RETENSI']),
                'proses'    => TabelImpor::kolom($header, ['PROSES']),
                'open_stok' => TabelImpor::kolom($header, ['OPEN_STOK', 'OPEN STOK', 'OPEN']),
                'lain_lain' => TabelImpor::kolom($header, ['LAIN_LAIN', 'LAIN-LAIN', 'LAIN']),
            ];
        $iEscrow = $status ? TabelImpor::kolom($header, ['ESCROW', 'SISA RETENSI']) : null;

        $payload = [];
        $galat   = [];
        $urut    = 0;
        foreach ($rows as $n => $r) {
            $baris = $n + 2;
            $label = trim(TabelImpor::sel($r, $iLabel));
            if ($label === '') {
                continue;
            }
            if (isset($payload[$label])) {
                $galat[] = "Baris {$baris}: '{$label}' muncul dua kali.";

                continue;
            }
            $urut++;

            $isi = ['label' => $label, 'urutan' => $iUrut !== null
                ? (int) (TabelImpor::angka(TabelImpor::sel($r, $iUrut)) ?? $urut)
                : $urut];

            foreach ($kol as $nama => $idx) {
                $mentah = $idx === null ? '' : trim(TabelImpor::sel($r, $idx));
                if ($status) {
                    // Tanda pisah "–" pada Exsum berarti nol, bukan angka yang hilang.
                    $isi[$nama] = (int) round(TabelImpor::angka($mentah) ?? 0);
                } else {
                    // Disimpan APA ADANYA: "Rp 1,2 M (2 unit RUHA)" kehilangan artinya bila
                    // dipaksa menjadi desimal.
                    $isi[$nama] = $mentah !== '' ? $mentah : null;
                }
            }
            if ($status) {
                $isi['escrow'] = $iEscrow === null ? null : (trim(TabelImpor::sel($r, $iEscrow)) ?: null);
            }
            $isi['catatan'] = $iCatatan === null ? null : (trim(TabelImpor::sel($r, $iCatatan)) ?: null);

            $payload[$label] = $isi;
        }

        if ($galat) {
            return back()->with('error', 'Import dibatalkan — '.implode(' · ', array_slice($galat, 0, 8))
                .(count($galat) > 8 ? ' … (+'.(count($galat) - 8).' lainnya)' : ''));
        }
        if (! $payload) {
            return back()->with('error', 'Tidak ada baris yang bisa dibaca dari file.');
        }

        $userId = $request->user()->id;
        $kelas  = $status ? KprClusterStatus::class : KprClusterOutstanding::class;

        DB::transaction(function () use ($payload, $kelas, $userId): void {
            /* SELURUH ISI DIGANTI. Exsum adalah potret bulanan — baris cluster yang sudah tidak
               ada lagi di Exsum terbaru harus ikut hilang, bukan tertinggal sebagai sisa bulan
               lalu yang diam-diam terbaca sebagai keadaan sekarang. */
            $kelas::query()->delete();
            foreach ($payload as $isi) {
                $kelas::create($isi + ['created_by' => $userId]);
            }
        });

        return back()->with('success', count($payload).' baris '
            .($status ? 'Proses KPR per Cluster' : 'Outstanding KPR per Cluster').' tersimpan.');
    }
}
