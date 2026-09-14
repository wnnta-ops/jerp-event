<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BankLoan;

use App\Domain\BankLoan\Enums\KprStatus;
use App\Domain\BankLoan\Models\KprCollectionTarget;
use App\Http\Controllers\Controller;
use App\Support\Import\TabelImpor;
use App\Support\Xlsx\SimpleXlsx;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * REALISASI vs BUDGET — target akad KPR sebulan dibandingkan realisasinya.
 *
 * "Budget" di sini adalah TARGET AKAD (unit & nilai) yang disusun manual dan diisi lewat import
 * Excel; "realisasi" adalah akad yang benar-benar terjadi pada bulan itu.
 *
 * ═══ DUA JEBAKAN YANG MENENTUKAN BENAR-SALAHNYA ANGKA ═══
 *
 * 1. SATU BOOKING BISA PUNYA BEBERAPA APLIKASI KPR (ganti bank). Menghitung langsung dari
 *    kpr_akads membuat satu unit terhitung dua kali — dan jumlahnya tetap terlihat wajar, jadi
 *    tidak ada yang memberi tahu. Karena itu yang dihitung adalah APLIKASI TERBARU per booking,
 *    sama seperti yang sudah dilakukan seluruh kode KPR lain.
 *
 * 2. AKAD YANG KEMUDIAN BATAL BUKAN REALISASI. Aplikasi ini punya dua definisi "sudah akad" yang
 *    berbeda: BankApprovalController::akadStatusFrom() — yang menyebut dirinya sumber tunggal —
 *    memperlakukan rejected/cancelled sebagai BATAL walau akadnya sempat terjadi, sedangkan
 *    KprLifecycleReport tetap menampilkan tahap akad yang sempat dicapai. Untuk laporan
 *    realisasi, yang berlaku adalah yang pertama.
 *
 * Nilainya memakai kpr_applications.plafon_approved — angka yang sama yang sudah dipakai layar
 * Proses KPR dan unduhan Excel-nya. Nilai PENCAIRAN (kpr_realizations.disbursed_amount) sengaja
 * TIDAK dipakai: satu akad bisa punya beberapa baris pencairan bertahap, dan menjumlahkannya
 * menjawab pertanyaan yang berbeda.
 */
class KprTargetRealisasiController extends Controller
{
    /**
     * Status yang berarti akadnya sudah terjadi DAN belum dibatalkan.
     *
     * rejected & cancelled sengaja TIDAK ada di sini walau akadnya sempat terjadi — itulah
     * yang membedakan laporan realisasi dari laporan riwayat tahapan.
     *
     * @return array<int, string>
     */
    private static function statusSudahAkad(): array
    {
        return [KprStatus::AkadDone->value, KprStatus::Disbursed->value, KprStatus::Active->value];
    }

    public const IMPORT_HEADER = ['BULAN', 'TARGET_UNIT', 'TARGET_VALUE'];

    private const NAMA_BULAN = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);

        $year  = (int) ($request->integer('year') ?: now()->year);
        $bulan = (int) $request->integer('month'); // 0 / kosong = seluruh 12 bulan

        $realisasi = $this->realisasiPerBulan($year);
        $target    = KprCollectionTarget::where('period_year', $year)
            ->get()->keyBy('period_month');

        $rows = [];
        for ($m = 1; $m <= 12; $m++) {
            $tUnit  = (int) ($target[$m]->target_unit ?? 0);
            $tValue = (float) ($target[$m]->target_value ?? 0);
            $rUnit  = (int) ($realisasi[$m]['unit'] ?? 0);
            $rValue = (float) ($realisasi[$m]['value'] ?? 0);

            $rows[] = [
                'month'          => $m,
                'label'          => self::NAMA_BULAN[$m],
                'target_unit'    => $tUnit,
                'realisasi_unit' => $rUnit,
                'target_value'   => $tValue,
                'realisasi_value' => $rValue,
                // Bulan yang belum dilewati TIDAK ditampilkan 0% — nol terbaca sebagai "gagal
                // total", padahal bulannya memang belum terjadi. null = tanda pisah di layar.
                'pencapaian'     => $tUnit > 0 && $rUnit > 0 ? round($rUnit / $tUnit * 100) : null,
                'ada_realisasi'  => $rUnit > 0 || $rValue > 0,
            ];
        }

        /* Batas YTD = bulan TERAKHIR yang punya realisasi, bukan bulan berjalan. Memakai bulan
           berjalan membuat baris YTD memuat bulan-bulan kosong di ujungnya dan pencapaiannya
           terbaca lebih buruk daripada yang sebenarnya. */
        $ytdSampai = 0;
        foreach ($rows as $r) {
            if ($r['ada_realisasi']) {
                $ytdSampai = $r['month'];
            }
        }

        $jml = fn (array $keys, int $dari, int $sampai) => array_map(
            fn ($k) => array_sum(array_map(fn ($r) => $r[$k], array_filter($rows, fn ($r) => $r['month'] >= $dari && $r['month'] <= $sampai))),
            $keys,
        );

        [$ytdTU, $ytdRU, $ytdTV, $ytdRV] = $ytdSampai > 0
            ? $jml(['target_unit', 'realisasi_unit', 'target_value', 'realisasi_value'], 1, $ytdSampai)
            : [0, 0, 0.0, 0.0];
        [$totTU, $totRU, $totTV, $totRV] = $jml(['target_unit', 'realisasi_unit', 'target_value', 'realisasi_value'], 1, 12);

        return Inertia::render('BankLoan/Database/RealisasiVsBudget', [
            'year'  => $year,
            'month' => $bulan >= 1 && $bulan <= 12 ? $bulan : 0,
            'years' => range(now()->year + 1, now()->year - 4),
            'rows'  => $rows,
            'ytd'   => [
                'sampai'          => $ytdSampai,
                'label'           => $ytdSampai > 0 ? 'YTD Jan–'.substr(self::NAMA_BULAN[$ytdSampai], 0, 3) : 'YTD',
                'target_unit'     => $ytdTU,
                'realisasi_unit'  => $ytdRU,
                'target_value'    => $ytdTV,
                'realisasi_value' => $ytdRV,
                'pencapaian'      => $ytdTU > 0 ? round($ytdRU / $ytdTU * 100) : null,
            ],
            'total' => [
                'target_unit'     => $totTU,
                'realisasi_unit'  => $totRU,
                'target_value'    => $totTV,
                'realisasi_value' => $totRV,
                'pencapaian'      => $totTU > 0 ? round($totRU / $totTU * 100) : null,
                'pencapaian_value' => $totTV > 0 ? round($totRV / $totTV * 100) : null,
            ],
            // Akad yang sudah terjadi tetapi tanggalnya kosong tidak bisa ditempatkan pada bulan
            // mana pun. Disebutkan, bukan didiamkan — kalau tidak, selisihnya terbaca sebagai
            // realisasi yang kurang.
            'tanpaTanggal' => $this->akadTanpaTanggal(),
        ]);
    }

    /**
     * Realisasi akad per bulan pada satu tahun.
     *
     * @return array<int, array{unit:int, value:float}>
     */
    private function realisasiPerBulan(int $year): array
    {
        /* DB::table MELEWATI global scope tenant — Eloquent-lah yang memasangnya, bukan query
           builder. Tanpa penyaring eksplisit di bawah, laporan satu tenant akan memuat akad
           tenant lain: angkanya tetap terlihat wajar, dan tidak ada galat apa pun. Pola yang sama
           dipakai DashboardController pada kueri mentahnya. */
        $baris = DB::table('kpr_applications as a')
            ->join('kpr_akads as k', 'k.kpr_application_id', '=', 'a.id')
            ->when(tenant_id() !== null, fn ($q) => $q->where('a.tenant_id', tenant_id()))
            ->whereIn('a.status', self::statusSudahAkad())
            ->whereNotNull('k.akad_at')
            ->whereYear('k.akad_at', $year)
            // Aplikasi TERBARU per booking — lihat jebakan 1 di kepala kelas.
            ->whereRaw('a.id = (select max(a2.id) from kpr_applications a2 where a2.booking_id = a.booking_id)')
            ->selectRaw('MONTH(k.akad_at) as bln, COUNT(DISTINCT a.booking_id) as unit, COALESCE(SUM(a.plafon_approved),0) as nilai')
            ->groupBy('bln')
            ->get();

        $out = [];
        foreach ($baris as $b) {
            $out[(int) $b->bln] = ['unit' => (int) $b->unit, 'value' => (float) $b->nilai];
        }

        return $out;
    }

    /** Berapa akad yang sudah terjadi tetapi tanggalnya kosong — tidak bisa masuk bulan mana pun. */
    private function akadTanpaTanggal(): int
    {
        return (int) DB::table('kpr_applications as a')
            ->leftJoin('kpr_akads as k', 'k.kpr_application_id', '=', 'a.id')
            ->when(tenant_id() !== null, fn ($q) => $q->where('a.tenant_id', tenant_id()))
            ->whereIn('a.status', self::statusSudahAkad())
            ->where(fn ($q) => $q->whereNull('k.id')->orWhereNull('k.akad_at'))
            ->whereRaw('a.id = (select max(a2.id) from kpr_applications a2 where a2.booking_id = a.booking_id)')
            ->distinct()->count('a.booking_id');
    }

    /** Template import target (.xlsx sungguhan) — 12 baris bulan, siap diisi. */
    public function template(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);

        $rows = [self::IMPORT_HEADER];
        foreach (self::NAMA_BULAN as $m => $nama) {
            $rows[] = [$nama, '0', '0'];
        }

        return response(SimpleXlsx::toString($rows), 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Template-Target-Akad-KPR.xlsx"',
        ]);
    }

    /**
     * Import target setahun.
     *
     * TARGET_VALUE diisi dalam RUPIAH PENUH. Layar menampilkannya sebagai "Rp M", tetapi meminta
     * orang mengetik angka miliar berkoma akan membuat 50,6 dan 50.6 berarti hal berbeda di dua
     * komputer — dan selisihnya ratusan juta.
     */
    public function import(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);
        $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'file' => ['required', 'file', 'max:8192'],
        ]);

        $year = (int) $request->integer('year');

        // .xls (BIFF) tidak bisa dibaca mekanisme mana pun di aplikasi ini — ditolak apa adanya,
        // bukan dibiarkan gagal dengan pesan "kolom tidak ditemukan" atas berkas yang benar.
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

        $header = TabelImpor::header(array_shift($rows));
        $iBulan = TabelImpor::kolom($header, ['BULAN', 'MONTH', 'PERIODE']);
        $iUnit  = TabelImpor::kolom($header, ['TARGET_UNIT', 'TARGET UNIT', 'UNIT']);
        $iValue = TabelImpor::kolom($header, ['TARGET_VALUE', 'TARGET VALUE', 'VALUE', 'NILAI']);

        $kurang = [];
        if ($iBulan === null) { $kurang[] = 'BULAN'; }
        if ($iUnit === null && $iValue === null) { $kurang[] = 'TARGET_UNIT / TARGET_VALUE'; }
        if ($kurang) {
            return back()->with('error', 'Kolom wajib tidak ditemukan: '.implode(', ', $kurang).'. Unduh template untuk format yang benar.');
        }

        $petaBulan = [];
        foreach (self::NAMA_BULAN as $m => $nama) {
            $petaBulan[strtoupper($nama)] = $m;
            $petaBulan[strtoupper(substr($nama, 0, 3))] = $m;
            $petaBulan[(string) $m] = $m;
            $petaBulan[sprintf('%02d', $m)] = $m;
        }

        $payload = [];
        $galat   = [];
        foreach ($rows as $n => $r) {
            $baris = $n + 2;
            $bRaw  = strtoupper(trim(TabelImpor::sel($r, $iBulan)));
            if ($bRaw === '') {
                continue;
            }

            $m = $petaBulan[$bRaw] ?? null;
            if ($m === null) {
                $galat[] = "Baris {$baris}: bulan '{$bRaw}' tidak dikenal.";

                continue;
            }
            if (isset($payload[$m])) {
                $galat[] = "Baris {$baris}: bulan ".self::NAMA_BULAN[$m].' muncul dua kali.';

                continue;
            }

            $unit  = $iUnit === null ? 0.0 : (TabelImpor::angka(TabelImpor::sel($r, $iUnit)) ?? 0.0);
            $value = $iValue === null ? 0.0 : (TabelImpor::angka(TabelImpor::sel($r, $iValue)) ?? 0.0);
            if ($unit < 0 || $value < 0) {
                $galat[] = "Baris {$baris}: target tidak boleh negatif.";

                continue;
            }

            $payload[$m] = ['unit' => (int) round($unit), 'value' => round($value, 2)];
        }

        if ($galat) {
            return back()->with('error', 'Import dibatalkan — '.implode(' · ', array_slice($galat, 0, 8))
                .(count($galat) > 8 ? ' … (+'.(count($galat) - 8).' lainnya)' : ''));
        }
        if (! $payload) {
            return back()->with('error', 'Tidak ada baris target yang bisa dibaca dari file.');
        }

        $userId = $request->user()->id;
        DB::transaction(function () use ($payload, $year, $userId): void {
            foreach ($payload as $m => $v) {
                // updateOrCreate pada kunci uniknya: import ulang MEMPERBARUI targetnya, bukan
                // menumpuk baris kedua untuk bulan yang sama.
                KprCollectionTarget::updateOrCreate(
                    ['period_year' => $year, 'period_month' => $m],
                    ['target_unit' => $v['unit'], 'target_value' => $v['value'], 'created_by' => $userId],
                );
            }
        });

        return back()->with('success', count($payload).' bulan target akad '.$year.' tersimpan.');
    }
}
