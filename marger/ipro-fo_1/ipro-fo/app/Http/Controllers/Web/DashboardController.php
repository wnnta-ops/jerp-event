<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /** Nama bulan singkat (ID). */
    private const MONTHS_ID = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    /**
     * Batas jumlah batang pada grafik bulanan.
     *
     * Rentang boleh sepanjang apa pun, tetapi 120 batang di kartu selebar setengah layar
     * tidak lagi bisa dibaca. Yang dipotong adalah bulan-bulan TERAWAL, dan pemotongannya
     * dikatakan di kepala halaman — bukan didiamkan.
     */
    private const MAKS_BULAN = 36;

    /**
     * EMPAT SUB-DASHBOARD — isi tiap kelompok, berurutan seperti yang tampil di layar.
     *
     * Delapan belas grafik pada satu halaman membuat yang dicari selalu berada jauh di bawah.
     * Pengelompokan ini memberi tiap peran pintu masuknya sendiri, dan — karena tiap grafiknya
     * dibungkus closure di charts() — sub-dashboard hanya menghitung grafik yang ditampilkannya.
     *
     * Keempatnya bersama-sama memuat SELURUH kunci charts(); tidak ada grafik yang menjadi yatim
     * ketika halaman gabungannya tidak lagi tertaut dari sidebar.
     */
    public const GRUP = [
        'kpr'        => ['inisiasi_kpr', 'proses_kpr', 'penolakan'],                                   // 1–3
        'cash'       => ['rekening', 'kasbon', 'aging'],                                               // 4–6
        'accounting' => ['neraca', 'laba_rugi', 'aset_tetap', 'budget', 'bast_ajb_br', 'piutang', 'hpp'], // 7–10, 14–18
        'project'    => ['spk', 'progress', 'retensi'],                                                // 11–13
    ];

    /** Judul tiap sub-dashboard — dipakai kepala halaman supaya jelas sedang melihat kelompok apa. */
    public const GRUP_JUDUL = [
        'kpr'        => 'KPR & Collection',
        'cash'       => 'Cash Management',
        'accounting' => 'Accounting, Tax & Budget',
        'project'    => 'Project',
    ];

    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        /* HAK AKSES — satu izin per sub-dashboard (dashboard.{grup}.view, Grup Akses BO).
           Tanpa satu pun izin, halamannya "Selamat Datang": tidak ada grafik yang dihitung
           maupun dikirim ke browser. SuperAdmin lolos lewat Gate::before. */
        $boleh = array_values(array_filter(array_keys(self::GRUP), fn ($g) => (bool) $user?->can("dashboard.{$g}.view")));
        if ($boleh === []) {
            return Inertia::render('Dashboard/Selamat', [
                'nama' => $user?->name,
                'hariIni' => now()->locale('id')->translatedFormat('l, d-m-Y'),
                // Diatur di Settings → Konfigurasi Sistem → Halaman Selamat Datang.
                'motivasi' => \App\Http\Controllers\Web\Settings\SettingsConfigController::motivasiHariIni(),
            ]);
        }

        /* Tanpa ?grup= halaman memuat seluruh grafik dari sub-dashboard yang DIIZINKAN.
           Sub-dashboard hanyalah penyaring di atas halaman yang sama, bukan halaman baru —
           satu tata letak, satu saringan periode, satu tempat memperbaiki bila ada yang salah. */
        $grup = (string) $request->query('grup', '');
        $grup = isset(self::GRUP[$grup]) ? $grup : null;
        if ($grup !== null && ! in_array($grup, $boleh, true)) {
            // Alamat sub-dashboard yang tidak diizinkan → halaman dashboard miliknya sendiri.
            return redirect()->route('dashboard', count($boleh) === 1 ? ['grup' => $boleh[0]] : []);
        }

        $p = $this->periode($request);

        return Inertia::render('Dashboard/Index', [
            'greeting' => [
                'name'          => $user?->name,
                'last_login_at' => $user?->last_login_at?->toIso8601String(),
                'last_login_ip' => $user?->last_login_ip,
            ],
            'periode' => [
                // Rentang batang bulanan; ditulis di kepala halaman supaya pembaca tahu
                // angka yang dilihatnya mencakup periode apa.
                'label'   => $p['label'],
                'awal'    => $p['awal']->toDateString(),
                'akhir'   => $p['akhir']->toDateString(),
                'bulan'   => count($p['bulan']),
                // Rentang yang terlalu panjang dipotong agar batang bulanannya masih terbaca.
                // Pemotongannya DIKATAKAN, bukan didiamkan: grafik yang diam-diam memendek
                // terbaca sebagai "datanya memang segitu".
                'dipotong'  => $p['dipotong'],
                'maks_bulan' => self::MAKS_BULAN,
                'project' => (string) (DB::table('projects')->value('name') ?? 'Semua Project'),
            ],
            'grup'       => $grup,
            'grupJudul'  => $grup !== null ? self::GRUP_JUDUL[$grup] : null,
            'charts'     => $this->charts($p, $grup !== null ? [$grup] : $boleh),
        ]);
    }

    /**
     * Rentang periode dashboard: tanggal awal, tanggal akhir, dan bulan-bulan di antaranya.
     *
     * Bawaannya SAMA PERSIS dengan perilaku sebelum periode bisa dipilih — delapan bulan
     * terakhir sampai hari ini — sehingga membuka dashboard tanpa memilih apa pun
     * memberikan angka yang sama seperti dulu.
     *
     * Rentang terbalik (awal > akhir) diperlakukan sebagai salah ketik dan ditukar, bukan
     * dibiarkan memulangkan dashboard kosong yang terbaca seperti "tidak ada transaksi".
     *
     * @return array{awal: Carbon, akhir: Carbon, bulan: array<int, Carbon>, label: string, dipotong: bool}
     */
    private function periode(Request $request): array
    {
        $akhir = $request->date('date_to') ?? now();
        $awal  = $request->date('date_from') ?? $akhir->copy()->startOfMonth()->subMonths(7);
        if ($awal->greaterThan($akhir)) {
            [$awal, $akhir] = [$akhir, $awal];
        }
        $awal  = $awal->startOfDay();
        $akhir = $akhir->endOfDay();

        // Bulan yang tersentuh rentangnya — inilah sumbu waktu seluruh grafik bulanan.
        $bulan = [];
        $kursor = $awal->copy()->startOfMonth();
        while ($kursor->lessThanOrEqualTo($akhir)) {
            $bulan[] = $kursor->copy();
            $kursor->addMonthNoOverflow();
        }
        if ($bulan === []) {
            $bulan[] = $awal->copy()->startOfMonth();
        }

        // Rentang sangat panjang dipotong pada bulan-bulan TERAKHIR: yang paling sering
        // dicari orang adalah keadaan terkini, bukan awal rentangnya.
        $dipotong = count($bulan) > self::MAKS_BULAN;
        if ($dipotong) {
            $bulan = array_slice($bulan, -self::MAKS_BULAN);
        }

        $b1 = $bulan[0];
        $b2 = $bulan[count($bulan) - 1];
        // Tahun dicetak di KEDUA ujung bila rentangnya melintasi pergantian tahun. Menempelkan
        // satu tahun di belakang rentang akan berbohong mulai September: "Jan–Agu 2026" masih
        // benar, tetapi "Agu–Mar 2026" tidak — bulan Mar-nya sudah 2027.
        $label = $b1->year === $b2->year
            ? self::MONTHS_ID[$b1->month].'–'.self::MONTHS_ID[$b2->month].' '.$b1->year
            : self::MONTHS_ID[$b1->month].' '.$b1->year.'–'.self::MONTHS_ID[$b2->month].' '.$b2->year;

        return ['awal' => $awal, 'akhir' => $akhir, 'bulan' => $bulan, 'label' => $label, 'dipotong' => $dipotong];
    }

    /**
     * Saring ALIRAN: hanya baris yang tanggalnya jatuh di dalam rentang periode.
     *
     * Kolomnya diperiksa dulu — di lingkungan yang migrasinya belum penuh, kolom yang hilang
     * harus berarti "tidak disaring", bukan QueryException yang menjatuhkan seluruh dashboard.
     * Itu pola yang sudah berlaku di seluruh controller ini.
     *
     * @param  array{awal: Carbon, akhir: Carbon, bulan: array<int, Carbon>, label: string, dipotong: bool}  $p
     */
    private function dalamPeriode(\Illuminate\Database\Query\Builder $q, string $kolom, array $p, string $tabel, string $alias = ''): void
    {
        if (! Schema::hasColumn($tabel, $kolom)) {
            return;
        }
        $q->whereBetween(($alias !== '' ? $alias.'.' : '').$kolom, [$p['awal'], $p['akhir']]);
    }

    /**
     * Saring POSISI: hanya baris yang tanggalnya SUDAH terjadi pada tanggal akhir periode.
     *
     * Dipakai kartu yang menyatakan keadaan pada satu titik waktu — saldo, aging, neraca,
     * aset tetap, progres. Batas bawahnya sengaja tidak ada: saldo hari ini dibentuk juga
     * oleh transaksi sebelum periode, dan membuangnya membuat angkanya salah.
     *
     * @param  array{awal: Carbon, akhir: Carbon, bulan: array<int, Carbon>, label: string, dipotong: bool}  $p
     */
    private function sampaiPeriode(\Illuminate\Database\Query\Builder $q, string $kolom, array $p, string $tabel, string $alias = ''): void
    {
        if (! Schema::hasColumn($tabel, $kolom)) {
            return;
        }
        $q->whereDate(($alias !== '' ? $alias.'.' : '').$kolom, '<=', $p['akhir']->toDateString());
    }

    /**
     * Label sumbu bulan. Tahun ikut dicetak bila rentangnya melintasi tahun — tanpa itu,
     * "Jan" pada rentang dua tahun tidak bisa dibedakan Jan yang mana.
     *
     * @param  array<int, Carbon>  $bulan
     * @return array<int, string>
     */
    private function labelBulan(array $bulan): array
    {
        $lintasTahun = $bulan[0]->year !== $bulan[count($bulan) - 1]->year;

        return array_map(
            fn (Carbon $m) => self::MONTHS_ID[$m->month].($lintasTahun ? " '".substr((string) $m->year, 2) : ''),
            $bulan,
        );
    }

    /**
     * Data 16 kartu grafik Dashboard Back Office.
     *
     * Nama kuncinya adalah KONTRAK dengan resources/js/Pages/Dashboard/Index.vue —
     * komponen di sana mengambilnya langsung tanpa optional chaining, jadi setiap kunci
     * WAJIB ada walau isinya kosong. Tiap method sudah mengembalikan struktur kosong yang
     * jujur bila tabelnya belum berisi; layar menampilkan keadaan kosong, bukan angka contoh.
     *
     * Periode dipakai dengan DUA cara yang berbeda, dan keduanya benar:
     *
     *   ALIRAN  — kartu yang mengukur kejadian SELAMA periode (corong KPR, penolakan, bon,
     *             laba rugi, SPK, serah terima, retensi) disaring pada rentang awal–akhir.
     *   POSISI  — kartu yang menyatakan keadaan PADA SATU TITIK waktu (saldo rekening,
     *             aging hutang, neraca, aset tetap, progres lapangan) memakai TANGGAL AKHIR
     *             sebagai tanggal potretnya. Menyaringnya dengan rentang akan membuang saldo
     *             yang terbentuk sebelum periode dan angkanya jadi salah, bukan sekadar sempit.
     *
     * Dua kartu SENGAJA tidak mengikuti periode karena tidak punya dimensi waktu yang jujur:
     * Rekonsiliasi Piutang (sisa piutang berjalan, bukan kejadian) dan Summary HPP (baris HPP
     * terakhir per unit, apa pun kapan di-generate). Memaksakan rentang pada keduanya hanya
     * akan mengubah artinya diam-diam.
     *
     * @param  array{awal: Carbon, akhir: Carbon, bulan: array<int, Carbon>, label: string, dipotong: bool}  $p
     * @return array<string, mixed>
     */
    /** @param list<string> $grupList  sub-dashboard yang grafiknya dihitung */
    private function charts(array $p, array $grupList): array
    {
        $semua = [
            'inisiasi_kpr' => fn () => $this->chartInisiasiKpr($p),      // 1
            'proses_kpr'   => fn () => $this->chartProsesKpr($p),        // 2
            'penolakan'    => fn () => $this->chartPenolakanKpr($p),     // 3
            'rekening'     => fn () => $this->chartSaldoRekeningKas($p), // 4
            'kasbon'       => fn () => $this->chartBonSementara($p),     // 5
            'aging'        => fn () => $this->chartAgingHutang($p),      // 6
            'neraca'       => fn () => $this->chartSummaryNeraca($p),    // 7
            'laba_rugi'    => fn () => $this->chartSummaryLabaRugi($p),  // 8
            'aset_tetap'   => fn () => $this->chartAsetTetap($p),        // 9
            'budget'       => fn () => $this->chartRealisasiBudget($p),  // 10
            'spk'          => fn () => $this->chartSpkPerCluster($p),    // 11
            'progress'     => fn () => $this->chartProgressLapangan($p), // 12
            'retensi'      => fn () => $this->chartSummaryRetensi($p),   // 13
            'bast_ajb_br'  => fn () => $this->chartBastAjbBooked($p),    // 14–16
            'piutang'      => fn () => $this->chartRekonsiliasiPiutang($p), // 17
            'hpp'          => fn () => $this->chartSummaryHpp($p),         // 18
        ];

        /* Tiap grafik dibungkus closure supaya sub-dashboard hanya MENGHITUNG yang ditampilkannya.
           Satu pemuatan penuh menjalankan ±25 kueri data dan ratusan panggilan introspeksi skema;
           menghitung keenam belasnya untuk halaman yang hanya menampilkan tiga adalah beban yang
           tidak pernah terlihat di layar — hanya terasa sebagai halaman yang lambat. */
        $pilih = array_merge(...array_map(fn ($g) => self::GRUP[$g] ?? [], $grupList));

        $out = [];
        foreach ($pilih as $k) {
            if (isset($semua[$k])) {
                $out[$k] = ($semua[$k])();
            }
        }

        return $out;
    }


    // ── Grafik keuangan: Hutang (AP) & Buku Besar (GL) ────────


    // ── Helper umum ───────────────────────────────────────────



    // ── Grafik KPR ────────────────────────────────────────────────────────────


    // ── Grafik piutang & harga pokok (AR / COGS) ──────────────

    /**
     * GRAFIK 17 — Rekonsiliasi Piutang per cluster: terbayar vs sisa piutang.
     *
     * "Terbayar" diambil dari kolom paid_amount pada faktur, BUKAN SUM(bs_ar_payments):
     * pelunasan juga masuk lewat kuitansi (ArKuitansiService) dan memorial
     * (ArMemorialService) yang tidak menulis baris di bs_ar_payments, sehingga memakai
     * tabel pembayaran akan melaporkan sisa piutang lebih besar daripada kenyataan.
     *
     * Nilai tagihan memakai net_receivable (total − PPh yang dipungut pelanggan) karena
     * itulah yang benar-benar ditagih; sama dengan yang dipakai kartu di modul AR.
     *
     * Cluster dicapai lewat faktur → booking → unit. Faktur non-penjualan (booking_id
     * kosong) sengaja TIDAK dibuang, melainkan masuk ember "Tanpa cluster", agar jumlah
     * di grafik tetap sama dengan total piutang di modul AR.
     *
     * Satu query agregat (GROUP BY cluster) — maksimal 6 baris terbesar.
     *
     * @return array{rows: array<int, array{n: string, a: float, b: float}>, keys: array<int, array{k: string, n: string, c: int}>}
     */
    private function chartRekonsiliasiPiutang(array $p): array
    {
        $keys = [
            ['k' => 'a', 'n' => 'Terbayar',     'c' => 2],
            ['k' => 'b', 'n' => 'Sisa piutang', 'c' => 1],
        ];
        $kosong = ['rows' => [], 'keys' => $keys];

        $t = 'bs_ar_invoices';
        if (! Schema::hasTable($t) || ! Schema::hasColumn($t, 'paid_amount') || ! Schema::hasColumn($t, 'total')) {
            return $kosong;
        }

        // Data lama bisa menyimpan net_receivable = 0; di situ 'total' yang berlaku.
        $tagih = Schema::hasColumn($t, 'net_receivable')
            ? 'CASE WHEN i.net_receivable > 0 THEN i.net_receivable ELSE i.total END'
            : 'i.total';

        // UANG MUKA TERKOMPENSASI ikut mengurangi sisa piutang. Ini bukan tambahan hiasan:
        // ArRekonsiliasiController mendefinisikan Sisa Piutang = Σ Invoice − Σ Pembayaran
        // − Σ UM terkompensasi. Tanpa suku ketiga, kartu ini melaporkan customer masih
        // berhutang padahal uang mukanya sudah dipakai menutup tagihan — persis selisih
        // yang membuat dashboard dan halaman rekonsiliasi resmi bercerita beda.
        $adaUm = Schema::hasTable('bs_ar_um_compensations')
            && Schema::hasColumn('bs_ar_um_compensations', 'booking_id')
            && Schema::hasColumn('bs_ar_um_compensations', 'amount')
            && Schema::hasColumn($t, 'booking_id');

        // GREATEST(...,0): faktur yang kelebihan bayar tidak boleh membuat sisa jadi minus.
        //
        // Faktur diringkas PER BOOKING lebih dulu, baru UM dikurangkan. Kalau UM di-join
        // langsung ke tabel faktur, satu UM akan tergandakan sebanyak jumlah faktur booking
        // itu; kalau dikurangkan dengan MAX() di tingkat cluster, hanya UM terbesar dalam
        // cluster yang terpakai. Meringkas per booking dulu membuat tiap UM terpakai
        // tepat sekali.
        // Alias sub-query sengaja DIBEDAKAN dari alias hasil akhir (terbayar/sisa): MySQL
        // menyelesaikan nama di ORDER BY terhadap kolom sumber lebih dulu, sehingga nama
        // yang sama membuat ORDER BY menunjuk kolom sub-query yang tidak teragregasi dan
        // ditolak only_full_group_by.
        $perBooking = DB::table($t.' as i')
            ->selectRaw(
                'i.booking_id,'
                .' SUM(COALESCE(i.paid_amount,0)) AS bayar_bk,'
                ." SUM(GREATEST({$tagih} - COALESCE(i.paid_amount,0), 0)) AS sisa_bk"
            )
            ->when(Schema::hasColumn($t, 'deleted_at'), fn ($w) => $w->whereNull('i.deleted_at'))
            ->groupBy('i.booking_id');

        // Kartu POSISI: hanya faktur yang sudah terbit sampai tanggal akhir periode.
        //
        // BATAS YANG PERLU DIKETAHUI: paid_amount adalah kolom berjalan, bukan catatan
        // bertanggal, jadi "Terbayar" di sini adalah pembayaran SAMPAI HARI INI atas faktur
        // yang terbit sampai tanggal akhir — bukan posisi pembayaran pada tanggal itu.
        // Memakai bs_ar_payments memang bisa dipotong per tanggal, tetapi pelunasan juga
        // masuk lewat kuitansi & memorial yang tidak menulis baris di sana (lihat catatan di
        // atas), sehingga hasilnya justru melaporkan sisa piutang lebih besar dari kenyataan.
        // Dari dua ketidaksempurnaan, yang dipilih adalah yang tidak mengarang angka.
        $this->sampaiPeriode($perBooking, 'invoice_date', $p, $t, 'i');

        // UM yang terpakai dibatasi sebesar sisa tagihannya (LEAST): kelebihan uang muka
        // bukan "terbayar", dan tidak boleh membuat sisa piutang jadi minus.
        $sisa     = 'SUM(GREATEST(f.sisa_bk - COALESCE(um.dipakai, 0), 0))';
        $terbayar = 'SUM(f.bayar_bk + LEAST(COALESCE(um.dipakai, 0), f.sisa_bk))';
        if (! $adaUm) {
            $sisa     = 'SUM(f.sisa_bk)';
            $terbayar = 'SUM(f.bayar_bk)';
        }

        // Rantai cluster hanya dipasang bila SEMUA tabel perantaranya ada; bila tidak,
        // grafik tetap tampil sebagai satu ember daripada gagal total.
        $adaRantai = Schema::hasTable('bookings') && Schema::hasColumn('bookings', 'unit_id')
            && Schema::hasTable('units') && Schema::hasColumn('units', 'cluster_id')
            && Schema::hasTable('clusters') && Schema::hasColumn('clusters', 'name')
            && Schema::hasColumn($t, 'booking_id');

        $label = $adaRantai ? "COALESCE(c.name, 'Tanpa cluster')" : "'Semua'";

        $q = DB::query()->fromSub($perBooking, 'f')
            ->selectRaw("{$label} AS n, {$terbayar} AS terbayar, {$sisa} AS sisa")
            ->groupByRaw($label)
            ->orderByRaw('(terbayar + sisa) DESC')
            ->limit(5);

        if ($adaUm) {
            $umPerBooking = DB::table('bs_ar_um_compensations')
                ->selectRaw('booking_id, SUM(COALESCE(amount,0)) AS dipakai')
                ->when(
                    Schema::hasColumn('bs_ar_um_compensations', 'deleted_at'),
                    fn ($w) => $w->whereNull('deleted_at')
                )
                ->groupBy('booking_id');
            // Kompensasi yang terjadi SETELAH tanggal akhir belum boleh mengurangi sisa
            // piutang pada tanggal itu — kalau ikut, kartunya melaporkan piutang sudah
            // tertutup padahal pada tanggal yang diminta belum.
            $this->sampaiPeriode($umPerBooking, 'compensated_at', $p, 'bs_ar_um_compensations');
            $q->leftJoinSub($umPerBooking, 'um', fn ($j) => $j->on('um.booking_id', '=', 'f.booking_id'));
        }

        if ($adaRantai) {
            $q->leftJoin('bookings as b', function ($j) {
                $j->on('b.id', '=', 'f.booking_id');
                if (Schema::hasColumn('bookings', 'deleted_at')) {
                    $j->whereNull('b.deleted_at');
                }
            });
            $q->leftJoin('units as u', function ($j) {
                $j->on('u.id', '=', 'b.unit_id');
                if (Schema::hasColumn('units', 'deleted_at')) {
                    $j->whereNull('u.deleted_at');
                }
            });
            $q->leftJoin('clusters as c', function ($j) {
                $j->on('c.id', '=', 'u.cluster_id');
                if (Schema::hasColumn('clusters', 'deleted_at')) {
                    $j->whereNull('c.deleted_at');
                }
            });
        }

        $rows = [];
        foreach ($q->get() as $r) {
            $rows[] = [
                'n' => (string) $r->n,
                'a' => round((float) $r->terbayar, 2),
                'b' => round((float) $r->sisa, 2),
            ];
        }

        // Basis data masih kosong → kembalikan daftar kosong, JANGAN baris nol palsu yang
        // di layar terlihat seperti cluster yang memang tidak punya piutang.
        return ['rows' => $rows, 'keys' => $keys];
    }

    /**
     * GRAFIK 18 — Summary HPP: susunan harga pokok per cluster (batang bertumpuk).
     *
     * bs_cogs_categories menyimpan kategori BUDGET, dan setiap kategori menunjuk salah satu
     * dari tujuh komponen HPP. Modul COGS Lama yang menulisnya sudah dihapus (12 Okt 2026); kolomnya
     * meringkas budget itu ke tujuh kolom hpp_* di bs_cogs_hpp, jadi angka HPP per unit
     * diambil langsung dari sana — tidak perlu menelusuri ulang kategori (dan tidak akan
     * salah hitung bila kategori dihapus/diganti setelah HPP terlanjur di-generate).
     *
     * Pemetaan tujuh komponen ke tiga ember layar (lengkap, tidak ada yang terbuang,
     * sehingga a+b+c = total_hpp):
     *   Tanah      = hpp_tanah
     *   Konstruksi = hpp_bangunan + hpp_sarana        (biaya fisik yang dibangun)
     *   Overhead   = hpp_permit + hpp_tools + hpp_kpr + hpp_bphtb  (perizinan, alat, biaya jual)
     *
     * Cluster diambil lewat unit_id → units.cluster_id, BUKAN lewat kolom location_id,
     * karena location_id menunjuk bs_cogs_locations yang tidak selalu diisi.
     *
     * Satu query agregat (GROUP BY cluster) — maksimal 5 baris terbesar.
     *
     * @return array{rows: array<int, array{n: string, a: float, b: float, c: float}>, keys: array<int, array{k: string, n: string, c: int}>}
     */
    private function chartSummaryHpp(array $p): array
    {
        $keys = [
            ['k' => 'a', 'n' => 'Tanah',      'c' => 0],
            ['k' => 'b', 'n' => 'Konstruksi', 'c' => 2],
            ['k' => 'c', 'n' => 'Overhead',   'c' => 3],
        ];
        $kosong = ['rows' => [], 'keys' => $keys];

        $t = 'bs_cogs_hpp';
        if (! Schema::hasTable($t) || ! Schema::hasColumn($t, 'unit_id')) {
            return $kosong;
        }

        /** @var array<string, array<int, string>> $ember */
        $ember = [
            'a' => ['hpp_tanah'],
            'b' => ['hpp_bangunan', 'hpp_sarana'],
            'c' => ['hpp_permit', 'hpp_tools', 'hpp_kpr', 'hpp_bphtb'],
        ];

        // Hanya kolom yang benar-benar ada yang dijumlahkan; ember tanpa kolom bernilai 0.
        $pilih  = [];
        $adaSatu = false;
        foreach ($ember as $k => $kolom) {
            $ada = array_values(array_filter($kolom, static fn (string $c): bool => Schema::hasColumn($t, $c)));
            if ($ada === []) {
                $pilih[] = "0 AS {$k}";

                continue;
            }
            $adaSatu = true;
            $jml = implode(' + ', array_map(static fn (string $c): string => "COALESCE(h.{$c},0)", $ada));
            $pilih[] = "SUM({$jml}) AS {$k}";
        }
        if (! $adaSatu) {
            return $kosong;
        }

        $adaRantai = Schema::hasTable('units') && Schema::hasColumn('units', 'cluster_id')
            && Schema::hasTable('clusters') && Schema::hasColumn('clusters', 'name');

        $label = $adaRantai ? "COALESCE(c.name, 'Tanpa cluster')" : "'Semua'";

        // ── PERIODE TERAKHIR SAJA, SATU BARIS PER UNIT ──────────────────────────────
        // bs_cogs_hpp bergrain (unit_id, period_year, period_month) dan SETIAP baris memuat
        // HPP PENUH unit tersebut — bukan angka tambahan (modul COGS Lama dulu menulis
        // pool anggaran tahunan x share tiap kali di-generate untuk periode baru). Menjumlah
        // seluruh baris berarti MENGALIKAN HPP dengan jumlah periode yang pernah di-generate.
        // Konvensi repo memang mengambil satu baris terakhir per unit — lihat
        // CogsQueryController::hppUnit() yang groupBy('unit_id') lalu sortByDesc('id')->first().
        //
        // Dipilih dengan ROW_NUMBER, BUKAN sub-query skalar berkorelasi. Dua sebabnya:
        //  - kinerja: sub-query berkorelasi dijalankan MySQL sekali PER BARIS induk, jadi
        //    biayanya tumbuh kuadratik seiring bertambahnya periode;
        //  - kebenaran: korelasi `h3.unit_id = h2.unit_id` tidak pernah benar untuk unit_id
        //    NULL (logika tiga-nilai), sehingga baris HPP tanpa unit HILANG seluruhnya.
        //    Window function tidak memakai perbandingan kesetaraan, jadi baris itu tetap
        //    terpilih lalu jatuh ke ember 'Tanpa cluster'.
        $punyaPeriode = Schema::hasColumn($t, 'period_year') && Schema::hasColumn($t, 'period_month');
        $urut = $punyaPeriode ? 'period_year DESC, period_month DESC, id DESC' : 'id DESC';

        $berperingkat = DB::table($t)
            ->selectRaw("id, ROW_NUMBER() OVER (PARTITION BY unit_id ORDER BY {$urut}) AS rn")
            ->when(Schema::hasColumn($t, 'deleted_at'), fn ($w) => $w->whereNull('deleted_at'));

        // ── MENGIKUTI PERIODE ───────────────────────────────────────────────────────────
        // Tabelnya sudah bergrain (unit_id, period_year, period_month), jadi periodenya tidak
        // perlu kolom tanggal baru: yang diambil adalah HPP terakhir per unit SAMPAI masa
        // akhir periode. Memilih periode lampau kini menunjukkan HPP sebagaimana tercatat
        // saat itu, bukan HPP terbaru hari ini — dan unit yang HPP-nya baru di-generate
        // setelah periode itu memang belum muncul, sebagaimana mestinya.
        //
        // Perbandingannya (tahun*12 + bulan) supaya batas tahunnya benar; membandingkan tahun
        // dan bulan secara terpisah akan ikut membawa Desember tahun berikutnya.
        if ($punyaPeriode) {
            $batas = ((int) $p['akhir']->year) * 12 + (int) $p['akhir']->month;
            $berperingkat->whereRaw('(period_year * 12 + period_month) <= ?', [$batas]);
        }

        $terakhir = DB::query()->fromSub($berperingkat, 'r')->select('r.id')->where('r.rn', 1);

        $q = DB::table($t.' as h')
            ->joinSub($terakhir, 'p', fn ($j) => $j->on('p.id', '=', 'h.id'))
            ->selectRaw($label.' AS n, '.implode(', ', $pilih))
            ->groupByRaw($label)
            ->orderByRaw('(a + b + c) DESC')
            ->limit(5);

        if ($adaRantai) {
            $q->leftJoin('units as u', function ($j) {
                $j->on('u.id', '=', 'h.unit_id');
                if (Schema::hasColumn('units', 'deleted_at')) {
                    $j->whereNull('u.deleted_at');
                }
            });
            $q->leftJoin('clusters as c', function ($j) {
                $j->on('c.id', '=', 'u.cluster_id');
                if (Schema::hasColumn('clusters', 'deleted_at')) {
                    $j->whereNull('c.deleted_at');
                }
            });
        }

        if (Schema::hasColumn($t, 'deleted_at')) {
            $q->whereNull('h.deleted_at');
        }

        $rows = [];
        foreach ($q->get() as $r) {
            $rows[] = [
                'n' => (string) $r->n,
                'a' => round((float) $r->a, 2),
                'b' => round((float) $r->b, 2),
                'c' => round((float) $r->c, 2),
            ];
        }

        // Belum ada HPP ter-generate → daftar kosong, bukan tiga cluster bernilai nol.
        return ['rows' => $rows, 'keys' => $keys];
    }


    // ── Grafik KPR ────────────────────────────────────────────────────────────

    /**
     * Grafik 1 — corong "Inisiasi KPR".
     *
     * Sumber kebenaran tahap ada di KprInitiationController::STAGES (7 tahap, 1-based):
     * 1 sprs · 2 bi_checking · 3 collect_berkas · 4 analisa · 5 profiling_berkas
     * · 6 profiling (Profiling Bank) · 7 kpr_application. Corong dashboard hanya menampilkan
     * 6 dari 7 — "Profiling Bank" sengaja dilewati karena bukan gerbang keputusan bagi customer.
     *
     * current_stage berisi tahap yang SEDANG dijalani (saveStage menaikkannya ke idx+2 setelah
     * sebuah tahap disimpan), jadi "sudah mencapai tahap N" = current_stage >= N — kumulatif dan
     * otomatis menurun. Inisiasi yang di-reject tetap dihitung pada tahap yang sempat dicapainya
     * (KprInitiationController::reject() hanya mengubah status, current_stage tidak disentuh),
     * sebab corong ini mengukur volume yang lewat, bukan yang bertahan.
     *
     * Satu query agregat (GROUP BY current_stage, maksimal 7 baris) — kumulatif dihitung di PHP.
     *
     * @return array{rows: array<int, array{n: string, v: int}>}
     */
    private function chartInisiasiKpr(array $p): array
    {
        $tahap = [
            ['n' => 'SPRS',               'min' => 1],
            ['n' => 'BI Checking',        'min' => 2],
            ['n' => 'Collect Berkas',     'min' => 3],
            ['n' => 'Analisa',            'min' => 4],
            ['n' => 'Profiling Customer', 'min' => 5],
            ['n' => 'Proses KPR',         'min' => 7],
        ];

        if (! Schema::hasTable('kpr_initiations') || ! Schema::hasColumn('kpr_initiations', 'current_stage')) {
            return ['rows' => []];
        }

        $q = DB::table('kpr_initiations')
            ->select('current_stage', DB::raw('COUNT(*) AS jml'))
            ->groupBy('current_stage');
        if (Schema::hasColumn('kpr_initiations', 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        // ALIRAN: corong ini mengukur berkas yang MASUK pada periode itu, jadi disaring pada
        // tanggal berkasnya dibuat — bukan pada tahap yang sedang dijalaninya sekarang.
        $this->dalamPeriode($q, 'created_at', $p, 'kpr_initiations');

        /** @var array<int, int> $perTahap */
        $perTahap = [];
        foreach ($q->get() as $row) {
            $perTahap[(int) $row->current_stage] = (int) $row->jml;
        }

        // Basis data masih kosong → kembalikan corong kosong, JANGAN nol-nol palsu yang
        // terlihat seperti data nyata.
        if ($perTahap === []) {
            return ['rows' => []];
        }

        $rows = [];
        foreach ($tahap as $t) {
            $v = 0;
            foreach ($perTahap as $stage => $jml) {
                if ($stage >= $t['min']) {
                    $v += $jml;
                }
            }
            $rows[] = ['n' => $t['n'], 'v' => $v];
        }

        return ['rows' => $rows];
    }

    /**
     * Grafik 2 — corong "Proses KPR" (kpr_applications).
     *
     * Peringkat tahap mengikuti KprStatus::allowedTransitions(): draft → submitted →
     * (wawancara/input_bank/verifikasi/analis/banding, legacy in_review) → sp3k → si → akad →
     * disbursed/active.
     *
     * Corong ini menghitung BERAPA BERKAS PERNAH SAMPAI tiap tahap, bukan berapa yang masih
     * bertahan di sana. Jejak tahap bisa hilang di dua tempat:
     *  - KprApplicationController::cancel() menyimpan tahap terakhir di status_before_cancel;
     *  - tetapi reject() TIDAK menyimpannya, padahal REJECTABLE_STATUSES memuat 'sp3k' —
     *    menolak berkas yang SP3K-nya sudah terbit adalah jalur yang resmi didukung.
     *
     * Karena itu peringkat ditetapkan PER BERKAS sebagai nilai TERBESAR dari tiga petunjuk:
     * status sekarang · status_before_cancel · keberadaan dokumen bukti (kpr_sp3k → 3,
     * kpr_akads → 5, kpr_realizations → 6). Setiap berkas lalu dihitung SEKALI pada satu
     * peringkat saja, sehingga corong monoton menurun DENGAN SENDIRINYA — tidak ada
     * pemangkasan pasca-hitung yang bisa membuang bukti keras.
     *
     * PENTING bagi yang mengubah kode ini nanti: jangan menegakkan monotonisitas dengan min()
     * terhadap tahap sebelumnya sesudah menaikkan satu tahap dari tabel bukti. Cara itu
     * membatalkan koreksinya sendiri (berkas 'rejected' terlempar ke peringkat 1, sehingga
     * "Dalam Review" kecil dan memangkas kembali "SP3K Terbit"). Menaikkan tahap awal dengan
     * max() juga tidak cukup: koreksi bertingkat-TOTAL tak bisa MENJUMLAHKAN dua sumber bukti
     * berbeda — mis. 5 berkas berstatus akad yang tak punya baris kpr_sp3k + 3 berkas ditolak
     * yang punya dokumen SP3K seharusnya 8, cara itu hanya menghasilkan 5.
     *
     * Satu query agregat: EXISTS ke tiap tabel bukti dihitung di dalam sub-query, lalu
     * dikelompokkan (paling banyak belasan baris). Tidak ada query per berkas.
     *
     * @return array{rows: array<int, array{n: string, v: int}>}
     */
    private function chartProsesKpr(array $p): array
    {
        if (! Schema::hasTable('kpr_applications') || ! Schema::hasColumn('kpr_applications', 'status')) {
            return ['rows' => []];
        }

        // Peringkat kumulatif: 1 Diajukan · 2 Dalam Review · 3 SP3K · 4 SI · 5 Akad · 6 Pencairan.
        $peringkat = [
            'draft'      => 0,
            'submitted'  => 1,
            'in_review'  => 2, 'wawancara' => 2, 'input_bank' => 2, 'verifikasi' => 2,
            'analis'     => 2, 'banding'   => 2,
            'sp3k'       => 3,
            'si'         => 4,
            'akad'       => 5,
            'disbursed'  => 6, 'active' => 6,
        ];

        $punyaBefore = Schema::hasColumn('kpr_applications', 'status_before_cancel');

        // Sub-query per berkas: status + jejak dokumen. Semua nama tabel/kolom di sini KONSTAN
        // (tidak ada input pengguna sama sekali), jadi tidak ada interpolasi yang bisa disuntik.
        $pilih = [
            'a.status AS status',
            ($punyaBefore ? 'a.status_before_cancel' : 'NULL').' AS before_status',
            $this->adaBuktiKpr('kpr_sp3k').' AS ada_sp3k',
            $this->adaBuktiKpr('kpr_akads').' AS ada_akad',
            // Pencairan yang DITOLAK saat approval bank tidak dianggap pernah cair — mengikuti
            // konvensi MasterDataKprController::pencairanByApplication() yang hanya memakai
            // baris ber-approval_status NULL.
            $this->adaBuktiKpr('kpr_realizations', 'approval_status', "COALESCE(b.approval_status, '') <> 'rejected'").' AS ada_cair',
        ];

        $sub = DB::table('kpr_applications as a')->selectRaw(implode(', ', $pilih));
        // ALIRAN: berkas yang MASUK pipeline pada periode itu.
        $this->dalamPeriode($sub, 'created_at', $p, 'kpr_applications', 'a');
        if (Schema::hasColumn('kpr_applications', 'deleted_at')) {
            $sub->whereNull('a.deleted_at');
        }

        $baris = DB::query()->fromSub($sub, 't')
            ->select('status', 'before_status', 'ada_sp3k', 'ada_akad', 'ada_cair', DB::raw('COUNT(*) AS jml'))
            ->groupBy('status', 'before_status', 'ada_sp3k', 'ada_akad', 'ada_cair')
            ->get();

        // Basis data masih kosong → corong kosong apa adanya, bukan deretan nol yang menyaru data.
        if ($baris->isEmpty()) {
            return ['rows' => []];
        }

        /** @var array<int, int> $perPeringkat */
        $perPeringkat = array_fill(0, 7, 0);
        foreach ($baris as $row) {
            $status = (string) $row->status;
            $before = (string) ($row->before_status ?? '');

            $r = max($peringkat[$status] ?? -1, $peringkat[$before] ?? -1);
            if ($r < 0) {
                // Status di luar daftar peringkat (mis. 'rejected' yang jejaknya hilang):
                // konservatif — minimal berkas itu pernah "Diajukan", tidak mengarang tahap lanjut.
                $r = in_array($status, ['rejected', 'cancelled'], true) ? 1 : 0;
            }
            // Dokumen yang sudah terbit adalah bukti keras; ia menang atas status apa pun.
            if ((int) $row->ada_sp3k === 1) {
                $r = max($r, 3);
            }
            if ((int) $row->ada_akad === 1) {
                $r = max($r, 5);
            }
            if ((int) $row->ada_cair === 1) {
                $r = max($r, 6);
            }

            $perPeringkat[max(0, min(6, $r))] += (int) $row->jml;
        }

        // Kumulatif "pernah mencapai tahap >= i". Monoton menurun dengan sendirinya karena tiap
        // berkas menyumbang tepat satu peringkat.
        $label = [1 => 'Diajukan', 2 => 'Dalam Review', 3 => 'SP3K Terbit', 4 => 'SI Dicetak', 5 => 'Akad', 6 => 'Pencairan'];
        $rows  = [];
        foreach ($label as $i => $n) {
            $v = 0;
            for ($j = $i; $j <= 6; $j++) {
                $v += $perPeringkat[$j];
            }
            $rows[] = ['n' => $n, 'v' => $v];
        }

        return ['rows' => $rows];
    }

    /**
     * Ekspresi SQL 0/1 "berkas ini punya jejak di tabel bukti $tabel", untuk dipakai di dalam
     * sub-query chartProsesKpr() (alias berkas = a, alias tabel bukti = b).
     *
     * Mengembalikan literal '0' bila tabel/kolomnya belum ada, supaya query tetap sah dan
     * dashboard tidak pernah melempar QueryException di basis data yang belum lengkap.
     *
     * $kolomSyarat/$syarat: penyaring tambahan yang hanya dipasang bila kolomnya memang ada.
     * Keduanya SELALU literal buatan kode ini — tidak pernah berasal dari input pengguna.
     */
    private function adaBuktiKpr(string $tabel, string $kolomSyarat = '', string $syarat = ''): string
    {
        if (! Schema::hasTable($tabel) || ! Schema::hasColumn($tabel, 'kpr_application_id')) {
            return '0';
        }

        $where = 'b.kpr_application_id = a.id';
        if ($kolomSyarat !== '' && $syarat !== '' && Schema::hasColumn($tabel, $kolomSyarat)) {
            $where .= ' AND '.$syarat;
        }

        return 'EXISTS (SELECT 1 FROM `'.$tabel.'` b WHERE '.$where.')';
    }

    /**
     * Grafik 3 — kolom berkelompok "Penolakan BI Checking & Proses KPR", 8 bulan terakhir.
     *
     * Dua sumber penolakan yang berbeda tabel, sejalan dengan layar Database KPR:
     *  - Reject BI Checking : kpr_initiations status 'rejected'. Penyaring statusnya sama persis
     *    dengan KprDatabaseController::rejectBiQuery() — tanpa memilah reject_type, sehingga
     *    jenis 'bi_checking' MAUPUN 'batal_sepihak' ikut terhitung. Bedanya hanya di sini
     *    ditambahkan jendela 8 bulan (layar arsip menampilkan semua record).
     *  - Reject Proses KPR  : kpr_applications status 'rejected', tanggalnya di decided_at —
     *    kolom yang sama dengan yang ditampilkan KprDatabaseController::rejectProcess().
     *
     * Tanggal reject inisiasi hanya tersimpan di dalam JSON stages.bi_checking.rejected_at
     * (KprInitiationController::reject()), jadi diambil lewat JSON_EXTRACT dengan fallback
     * updated_at untuk data lama. Filter WHERE tetap memakai updated_at karena reject adalah
     * tulisan terakhir pada record → rejected_at <= updated_at, sehingga filter itu superset
     * yang aman: tidak ada baris dalam jendela yang hilang.
     *
     * @return array{labels: array<int, string>, series: array<int, array{n: string, c: int, v: array<int, int>}>}
     */
    private function chartPenolakanKpr(array $p): array
    {
        $bulan  = $p['bulan'];
        $labels = $this->labelBulan($bulan);
        $kunci  = array_map(fn (Carbon $m) => $m->format('Y-m'), $bulan);
        $mulai  = $bulan[0]->copy()->startOfMonth();
        $henti  = $p['akhir'];

        $ke = fn (array $ember): array => array_map(
            static fn (string $k) => (int) ($ember[$k] ?? 0),
            $kunci
        );

        return [
            'labels' => $labels,
            'series' => [
                ['n' => 'Reject BI Checking', 'c' => 7, 'v' => $ke($this->emberRejectBi($mulai, $henti))],
                ['n' => 'Reject Proses KPR',  'c' => 1, 'v' => $ke($this->emberRejectProses($mulai, $henti))],
            ],
        ];
    }

    /**
     * Ember bulanan (Y-m => jumlah) inisiasi KPR yang ditolak.
     *
     * @return array<string, int>
     */
    private function emberRejectBi(Carbon $mulai, Carbon $henti): array
    {
        if (! Schema::hasTable('kpr_initiations') || ! Schema::hasColumn('kpr_initiations', 'status')) {
            return [];
        }

        $punyaStages = Schema::hasColumn('kpr_initiations', 'stages');
        // Ekspresi konstan (tanpa input pengguna); rentang tanggal tetap lewat binding.
        $ym = $punyaStages
            ? "DATE_FORMAT(COALESCE(STR_TO_DATE(LEFT(JSON_UNQUOTE(JSON_EXTRACT(stages, '$.bi_checking.rejected_at')), 10), '%Y-%m-%d'), DATE(updated_at)), '%Y-%m')"
            : "DATE_FORMAT(DATE(updated_at), '%Y-%m')";

        $q = DB::table('kpr_initiations')
            ->where('status', 'rejected')
            ->whereBetween('updated_at', [$mulai, $henti])
            ->select(DB::raw($ym.' AS ym'), DB::raw('COUNT(*) AS jml'))
            ->groupBy(DB::raw($ym));
        if (Schema::hasColumn('kpr_initiations', 'deleted_at')) {
            $q->whereNull('deleted_at');
        }

        $out = [];
        foreach ($q->get() as $row) {
            $out[(string) $row->ym] = (int) $row->jml;
        }

        return $out;
    }

    /**
     * Ember bulanan (Y-m => jumlah) aplikasi KPR yang ditolak pada Proses KPR.
     *
     * @return array<string, int>
     */
    private function emberRejectProses(Carbon $mulai, Carbon $henti): array
    {
        if (! Schema::hasTable('kpr_applications') || ! Schema::hasColumn('kpr_applications', 'status')) {
            return [];
        }

        $ym = Schema::hasColumn('kpr_applications', 'decided_at')
            ? "DATE_FORMAT(COALESCE(decided_at, DATE(updated_at)), '%Y-%m')"
            : "DATE_FORMAT(DATE(updated_at), '%Y-%m')";

        $q = DB::table('kpr_applications')
            ->where('status', 'rejected')
            ->whereBetween('updated_at', [$mulai, $henti])
            ->select(DB::raw($ym.' AS ym'), DB::raw('COUNT(*) AS jml'))
            ->groupBy(DB::raw($ym));
        if (Schema::hasColumn('kpr_applications', 'deleted_at')) {
            $q->whereNull('deleted_at');
        }

        $out = [];
        foreach ($q->get() as $row) {
            $out[(string) $row->ym] = (int) $row->jml;
        }

        return $out;
    }

    // ══════════════════════════════════════════════════════════════
    // KELOMPOK CASHBOOK — Grafik 4 & 5
    // Tempel di dalam class DashboardController (sebelum blok "Helper umum").
    //
    // PERAKITAN: kedua keluaran duduk DI BAWAH prop 'charts', dan nama kuncinya sudah
    // dipatok kontrak layar. resources/js/Pages/Dashboard/Index.vue mendeklarasikan
    // charts.rekening & charts.kasbon di defineProps, lalu men-dereference c.rekening.rows
    // dan c.kasbon.labels/.series TANPA optional chaining dan tanpa nilai bawaan — kunci
    // yang tidak sama persis membuat SELURUH halaman dashboard gagal render, bukan cuma
    // kartu 4 & 5. Karena itu kuncinya WAJIB:
    //
    //     'charts' => [
    //         // ... grafik lain ...
    //         'rekening' => $this->chartSaldoRekeningKas(),  // → c.rekening.rows
    //         'kasbon'   => $this->chartBonSementara(),      // → c.kasbon.labels & .series
    //     ],
    //
    // Helper periode()/labelBulan()/MONTHS_ID SUDAH ada di controller — tidak diduplikasi.
    // ══════════════════════════════════════════════════════════════

    /**
     * GRAFIK 4 — Summary Rekening Ledger.
     * Saldo akhir tiap rekening kas & bank, batang horizontal, urut terbesar, maksimal 8.
     *
     * Pilihan sumber data (sengaja BUKAN bs_ledgers): isi bs_ledgers adalah daftar buku besar
     * (UMUM / PROYEK / PAJAK — 3 baris) dan kolomnya hanya code+name; tidak ada satu pun kolom
     * saldo di sana. Rekening kas & bank yang sesungguhnya adalah akun postable di bawah 10101
     * (kas/bank/giro) dan 10108 (kas setara yang dibatasi penggunaannya / escrow). Pemilihan
     * kode itu disalin dari CashBookController::cashAccounts() — persis dua pola LIKE yang sama,
     * ditambah is_active & is_postable — supaya daftar rekening di kartu ini tidak pernah berbeda
     * isi dengan daftar rekening di halaman Buku Kas.
     *
     * STATUS JURNAL — hanya 'posted'. 'final' berarti jurnal masih MENUNGGU APPROVAL, dan itu
     * status bawaan seluruh jurnal otomatis (config business_support.journal.auto_status), jadi
     * memasukkannya akan membuat saldo di kartu ini mendahului laporan resmi. Buku Kas
     * (CashBookController) dan Buku Besar (LedgerReportController) juga menyaring 'posted' saja,
     * dan seluruh grafik dashboard memakai aturan yang sama — Grafik 4, 7, 8 dan 10 seragam.
     *
     * @return array{rows: array<int, array{n: string, v: float}>}
     */
    private function chartSaldoRekeningKas(array $p): array
    {
        // Gerbang skema: SETIAP tabel dan SETIAP kolom yang dirujuk diperiksa lebih dulu. Di
        // lingkungan yang migrasinya belum penuh, kolom yang hilang harus berujung kartu kosong —
        // bukan QueryException yang menjatuhkan seluruh halaman dashboard.
        $siap = Schema::hasTable('bs_accounts')
            && Schema::hasTable('bs_journals')
            && Schema::hasTable('bs_journal_lines')
            && Schema::hasColumns('bs_accounts', ['id', 'code', 'name'])
            && Schema::hasColumns('bs_journals', ['id'])
            && Schema::hasColumns('bs_journal_lines', ['journal_id', 'account_id', 'debit', 'credit']);

        if (! $siap) {
            return ['rows' => []];
        }

        // Tidak ada kolom saldo tersimpan di bs_accounts, jadi saldo akhir harus diakumulasi dari
        // jurnal. Ini justru lebih benar: saldo awal pun disimpan sebagai jurnal biasa
        // (bs_journals.source = 'saldo_awal'), sehingga ikut terhitung tanpa perlakuan khusus.
        $saldo = 'SUM(l.debit) - SUM(l.credit)';

        $q = DB::table('bs_journal_lines as l')
            ->join('bs_journals as j', 'j.id', '=', 'l.journal_id')
            ->join('bs_accounts as a', 'a.id', '=', 'l.account_id')
            ->where(fn ($w) => $w->where('a.code', 'like', '10101%')->orWhere('a.code', 'like', '10108%'))
            ->selectRaw("a.name AS nama, {$saldo} AS saldo")
            ->groupBy('a.id', 'a.name')
            // Rekening yang bermutasi tapi berakhir nol tidak punya batang untuk digambar.
            // Saldo NEGATIF (posisi kredit) TETAP ditampilkan — itu keadaan yang justru perlu
            // dilihat; VizHBars menggambarnya ke arah berlawanan dengan warna kritis.
            ->havingRaw("{$saldo} <> 0")
            ->orderByRaw("ABS({$saldo}) DESC")
            ->limit(8);

        // Hanya jurnal sah yang boleh membentuk saldo (lihat catatan STATUS JURNAL di atas).
        if (Schema::hasColumn('bs_journals', 'status')) {
            $q->where('j.status', 'posted');
        }
        // Batas tanggal disamakan dengan CashBookController::balances(): saldo "akhir" berarti
        // sampai HARI INI. Tanpa ini, jurnal bertanggal ke depan — yang sah dibuat untuk
        // transaksi terjadwal — akan membuat kartu ini melaporkan saldo yang belum ada.
        if (Schema::hasColumn('bs_journals', 'journal_date')) {
            $q->whereDate('j.journal_date', '<=', $p['akhir']->toDateString());
        }
        // bs_journal_lines memang tidak punya deleted_at; penjagaannya tetap ditulis per tabel
        // supaya penambahan kolom itu kelak langsung ikut terhormat tanpa mengubah kode ini.
        foreach ([['bs_journals', 'j'], ['bs_journal_lines', 'l'], ['bs_accounts', 'a']] as [$tabel, $alias]) {
            if (Schema::hasColumn($tabel, 'deleted_at')) {
                $q->whereNull("{$alias}.deleted_at");
            }
        }
        // Tanpa is_postable, akun induk (10101 "KAS DAN SETARA KAS") ikut terbawa dan nilainya
        // terhitung dua kali bila suatu saat ada jurnal nyasar ke induk.
        if (Schema::hasColumn('bs_accounts', 'is_postable')) {
            $q->where('a.is_postable', 1);
        }
        if (Schema::hasColumn('bs_accounts', 'is_active')) {
            $q->where('a.is_active', 1);
        }
        // DB::table melewati global scope tenant, jadi batasannya dipasang manual di sini.
        // Method dashboard lain (count()/dist()/monthlyAgg()) memang belum men-scope tenant —
        // itu utang teknis yang dicatat terpisah; di sini scope dipasang karena murah, dan
        // selama tenant baru satu ia tidak mengubah satu angka pun.
        if (Schema::hasColumn('bs_accounts', 'tenant_id') && ($tenant = tenant_id()) !== null) {
            $q->where('a.tenant_id', $tenant);
        }

        return ['rows' => $q->get()->map(fn ($r) => [
            'n' => $this->namaRekeningRingkas((string) $r->nama),
            'v' => (float) $r->saldo,
        ])->all()];
    }

    /**
     * Label batang: buang kata "REK." yang tidak menambah informasi. Nomor rekening TETAP UTUH —
     * memotong nomor rekening akan membuat operator salah mencocokkan dengan rekening koran.
     */
    private function namaRekeningRingkas(string $nama): string
    {
        return trim((string) preg_replace('/\s+/', ' ', str_ireplace(['REK.', 'REK '], ' ', $nama)));
    }

    /**
     * GRAFIK 5 — Bon Sementara / Kasbon.
     * Kolom berkelompok 8 bulan, 2 deret: Diajukan vs Dipertanggungjawabkan. Rupiah penuh.
     *
     * @return array{labels: array<int, string>, series: array<int, array{n: string, c: int, v: array<int, float>}>}
     */
    private function chartBonSementara(array $p): array
    {
        $months = $p['bulan'];
        $n = count($months);
        $labels = $this->labelBulan($months);
        // Label bulan tetap dikirim walau datanya kosong — semua grafik bulanan di dashboard
        // ini berperilaku sama (bandingkan chartPenolakanKpr & chartSummaryRetensi), sehingga
        // sumbu waktunya konsisten dan komponen menampilkan keadaan kosong dari `series`.
        $kosong = ['labels' => $labels, 'series' => []];

        // Gerbang skema deret 1: tanpa advance_date tidak ada bucket, tanpa amount tidak ada nilai.
        if (! Schema::hasTable('bs_cash_advances')
            || ! Schema::hasColumns('bs_cash_advances', ['id', 'advance_date', 'amount'])) {
            return $kosong;
        }

        // Bulan pertama & terakhir dipotong pada tanggal yang dipilih, bukan pada batas
        // bulannya — kalau tidak, memilih 10–20 Agustus akan tetap menghitung sebulan penuh.
        $start = max($months[0]->copy()->startOfMonth(), $p['awal'])->toDateString();
        $end   = min($months[$n - 1]->copy()->endOfMonth(), $p['akhir'])->toDateString();

        // Peta 'Y-m' → indeks bucket, supaya hasil GROUP BY tinggal ditempatkan tanpa loop bersarang.
        $posisi = [];
        foreach ($months as $i => $m) {
            $posisi[$m->format('Y-m')] = $i;
        }

        $diajukan = array_fill(0, $n, 0.0);
        $dipertj  = array_fill(0, $n, 0.0);

        // Deret 1 — DIAJUKAN: seluruh bon dibucket pada tanggal pengajuannya, apa pun statusnya
        // (requested / outstanding / realized), karena yang diukur memang beban pengajuan bulan itu.
        $q1 = DB::table('bs_cash_advances as a')
            ->whereBetween('a.advance_date', [$start, $end])
            ->selectRaw('YEAR(a.advance_date) AS y, MONTH(a.advance_date) AS m, SUM(a.amount) AS total')
            ->groupByRaw('YEAR(a.advance_date), MONTH(a.advance_date)');
        $this->batasiBon($q1);

        foreach ($q1->get() as $r) {
            $i = $posisi[sprintf('%04d-%02d', $r->y, $r->m)] ?? null;
            if ($i !== null) {
                $diajukan[$i] += (float) $r->total;
            }
        }

        // Deret 2 — DIPERTANGGUNGJAWABKAN: dibucket pada realized_date, bukan advance_date. Bon yang
        // diajukan Juni tapi baru direalisasi Agustus memang harus muncul di Agustus — kalau tidak,
        // grafik jadi tidak bisa dipakai membaca keterlambatan pertanggungjawaban.
        $adaHeader  = Schema::hasColumn('bs_cash_advances', 'realized_amount');
        $adaRincian = Schema::hasTable('bs_cash_advance_lines')
            && Schema::hasColumns('bs_cash_advance_lines', ['cash_advance_id', 'amount']);

        // Bila realized_date tidak ada, atau tidak ada satu pun sumber nilai realisasi, deret 2
        // dibiarkan nol — lebih jujur daripada menebak dari kolom lain.
        if (Schema::hasColumn('bs_cash_advances', 'realized_date') && ($adaHeader || $adaRincian)) {
            // Nilai realisasi diambil dari rincian biaya lebih dulu, header sebagai cadangan.
            // CashAdvanceService::realize() memang mengisi realized_amount dari jumlah rincian
            // (keduanya seharusnya sama), tetapi rincian didahulukan supaya bon yang rinciannya ada
            // sedangkan header realized_amount-nya masih 0 — misalnya hasil impor atau tambal data —
            // tetap terhitung. Susunan COALESCE menyesuaikan kolom yang benar-benar tersedia.
            $nilai = match (true) {
                // COALESCE saja tidak cukup: ia hanya mundur ke header saat rincian NULL.
                // Bon yang punya baris rincian bernilai 0 (baru dibuat, belum diisi) akan
                // membuat 0 itu menang atas realisasi header yang sudah terisi, sehingga
                // pertanggungjawaban yang nyata hilang dari batang.
                $adaRincian && $adaHeader => 'SUM(CASE WHEN COALESCE(ln.realisasi, 0) > 0 THEN ln.realisasi ELSE COALESCE(a.realized_amount, 0) END)',
                $adaRincian               => 'SUM(COALESCE(ln.realisasi, 0))',
                default                   => 'SUM(COALESCE(a.realized_amount, 0))',
            };

            $q2 = DB::table('bs_cash_advances as a')
                ->whereNotNull('a.realized_date')
                ->whereBetween('a.realized_date', [$start, $end])
                ->selectRaw("YEAR(a.realized_date) AS y, MONTH(a.realized_date) AS m, {$nilai} AS total")
                ->groupByRaw('YEAR(a.realized_date), MONTH(a.realized_date)');

            if ($adaRincian) {
                // Subquery, bukan join langsung ke rincian: join langsung akan melipatgandakan baris
                // bon sebanyak jumlah rinciannya dan nilainya jadi berkali lipat.
                $rincian = DB::table('bs_cash_advance_lines')
                    ->selectRaw('cash_advance_id, SUM(amount) AS realisasi')
                    ->groupBy('cash_advance_id');
                $q2->leftJoinSub($rincian, 'ln', 'ln.cash_advance_id', '=', 'a.id');
            }
            $this->batasiBon($q2);

            foreach ($q2->get() as $r) {
                $i = $posisi[sprintf('%04d-%02d', $r->y, $r->m)] ?? null;
                if ($i !== null) {
                    $dipertj[$i] += (float) $r->total;
                }
            }
        }

        // Jujur: bila tak ada satu rupiah pun di rentang ini, kembalikan struktur kosong supaya layar
        // menampilkan keadaan kosong — bukan deretan batang nol yang terlihat seperti data.
        if (array_sum($diajukan) <= 0 && array_sum($dipertj) <= 0) {
            return $kosong;
        }

        return [
            'labels' => $labels,
            'series' => [
                ['n' => 'Diajukan', 'c' => 3, 'v' => $diajukan],
                ['n' => 'Dipertanggungjawabkan', 'c' => 2, 'v' => $dipertj],
            ],
        ];
    }

    /** Batasan yang wajib menempel di tiap query bon: soft delete + tenant aktif. */
    private function batasiBon(\Illuminate\Database\Query\Builder $q): void
    {
        if (Schema::hasColumn('bs_cash_advances', 'deleted_at')) {
            $q->whereNull('a.deleted_at');
        }
        // Sama seperti Grafik 4: DB::table melewati global scope tenant, jadi dipasang manual.
        if (Schema::hasColumn('bs_cash_advances', 'tenant_id') && ($tenant = tenant_id()) !== null) {
            $q->where('a.tenant_id', $tenant);
        }
    }

    // ── Grafik keuangan: Hutang (AP) & Buku Besar (GL) ────────
    //
    // CATATAN TENANT (utang teknis yang diketahui, BUKAN diperkenalkan di sini):
    // ketiga method di bawah memakai DB::table() sehingga TIDAK melewati TenantScope
    // milik trait HasTenant. Ini pola yang sudah berlaku di SELURUH DashboardController
    // (count(), dist(), monthlyAgg() juga tidak men-scope tenant). Hari ini hanya ada satu
    // tenant sehingga belum berdampak; sebelum tenant kedua masuk, penyaringan tenant harus
    // ditambahkan SEKALIGUS untuk semua method berkas ini, bukan tambal sebagian.

    /**
     * GRAFIK 6 — Aging Hutang: sisa hutang AP dikelompokkan menurut umur jatuh tempo.
     *
     * Lima baris SELALU dikembalikan dengan urutan tetap walau nilainya 0, karena warna
     * batang di layar dipilih berdasarkan indeks baris — membuang kelompok kosong akan
     * menggeser warna kelompok yang lain.
     *
     * Definisi angkanya sengaja disamakan dengan laporan Aging Hutang resmi
     * (ApInvoiceController::computeAging) pada tiga hal yang menentukan:
     *   1. nilai hutang  = BsApInvoice::getPayableAmountAttribute(), yaitu
     *      net_payable bila > 0, kalau tidak mundur ke total (cadangan TINGKAT BARIS,
     *      karena net_payable NOT NULL DEFAULT 0.00 sehingga faktur lama/impor
     *      bisa saja bernilai 0 padahal total-nya ada);
     *   2. acuan umur    = COALESCE(due_date, invoice_date) — due_date nullable, dan
     *      laporan resmi memang mundur ke invoice_date, bukan menganggapnya belum jatuh tempo;
     *   3. tanpa penyaring status — laporan resmi juga tidak menyaring status, sebab yang
     *      menentukan adalah NILAI SISA. Faktur lunas otomatis bernilai 0 lewat GREATEST(...,0),
     *      jadi menyaring lewat status hanya menambah risiko selisih bila status dan angka
     *      sempat tidak sinkron.
     *
     * YANG MASIH BERBEDA dari laporan resmi, dan itu disengaja (jangan diklaim identik):
     *   - pembayaran diambil dari kolom kumulatif paid_amount, sedangkan laporan resmi
     *     menjumlah bs_ap_payments s/d tanggal acuan. Untuk acuan "hari ini" keduanya sama
     *     kecuali ada pembayaran bertanggal mundur/maju yang belum tercermin di paid_amount;
     *   - ada batas atas invoice_date <= TANGGAL AKHIR PERIODE, sedangkan laporan resmi
     *     memotret hari ini tanpa batas atas. Kartu ini kartu POSISI seperti Neraca dan Aset
     *     Tetap: yang ditanya "berapa hutang yang SUDAH TERBIT per tanggal itu", jadi faktur
     *     bertanggal setelahnya belum ada dan tidak boleh ikut. Tanpa batas ini faktur masa
     *     depan selalu ikut terhitung dan mendarat di "Belum jatuh tempo" — sehingga TOTAL
     *     kartu ini sama persis di periode mana pun, dan diamnya terbaca sebagai "hutangnya
     *     memang segitu", bukan sebagai kartu yang mengabaikan filter.
     *     Batas BAWAH tetap tidak ada, sama seperti kartu posisi yang lain: hutang yang terbit
     *     sebelum periode masih terhutang pada tanggal potretnya.
     *     Skema tanpa kolom invoice_date tidak disaring — pola Schema:: yang sudah berlaku di
     *     seluruh berkas ini — dan pada skema itu acuan umurnya memang sudah due_date saja.
     *
     * @return array{rows: array<int, array{n: string, v: float}>}
     */
    private function chartAgingHutang(array $p): array
    {
        $labels = ['Belum jatuh tempo', '1-30 hari', '31-60 hari', '61-90 hari', '> 90 hari'];
        $vals   = array_fill(0, 5, 0.0);

        // Semua pemeriksaan kolom dikorsleting oleh $adaTabel supaya hasColumn() tidak pernah
        // dipanggil pada tabel yang tidak ada.
        $t        = 'bs_ap_invoices';
        $adaTabel = Schema::hasTable($t);
        $adaNet   = $adaTabel && Schema::hasColumn($t, 'net_payable');
        $adaTot   = $adaTabel && Schema::hasColumn($t, 'total');
        $adaJt    = $adaTabel && Schema::hasColumn($t, 'due_date');
        $adaInv   = $adaTabel && Schema::hasColumn($t, 'invoice_date');

        // Tanpa salah satu kolom nilai ATAU salah satu kolom tanggal, grafik ini tidak punya
        // arti apa pun — biarkan nilainya tetap 0 (struktur kosong yang jujur), jangan sekali-kali
        // membangun query yang bisa melempar QueryException.
        if (($adaNet || $adaTot) && ($adaJt || $adaInv)) {
            // Ketiga potongan SQL di bawah dipilih dari daftar tertutup berdasarkan hasil
            // Schema::hasColumn — tidak ada satu pun nilai dari pengguna yang masuk ke string SQL.
            // Nilai per baris meniru getPayableAmountAttribute(); bila skema lama hanya punya
            // salah satu kolom, dipakai kolom yang ada.
            $nilai = match (true) {
                $adaNet && $adaTot => 'CASE WHEN net_payable > 0 THEN net_payable ELSE COALESCE(total, 0) END',
                $adaNet            => 'COALESCE(net_payable, 0)',
                default            => 'COALESCE(total, 0)',
            };
            $bayar = Schema::hasColumn($t, 'paid_amount') ? 'COALESCE(paid_amount, 0)' : '0';
            $acuan = match (true) {
                $adaJt && $adaInv => 'COALESCE(due_date, invoice_date)',
                $adaJt            => 'due_date',
                default           => 'invoice_date',
            };

            // Umur dihitung terhadap TANGGAL AKHIR periode, bukan hari ini: kartu ini potret
            // posisi hutang pada tanggal itu, dan memakai hari ini akan membuat periode lampau
            // melaporkan umur yang lebih tua daripada yang sebenarnya waktu itu.
            $today = $p['akhir']->toDateString();

            // Umur dihitung di sisi DB agar cukup SATU query agregat, bukan loop per faktur.
            // Acuan yang tetap NULL (skema hanya punya due_date dan isinya kosong) tidak bisa
            // dihitung umurnya, jadi masuk 'Belum jatuh tempo' — bukan mengarang tunggakan.
            $bucket = "CASE
                WHEN {$acuan} IS NULL OR {$acuan} >= ? THEN 0
                WHEN DATEDIFF(?, {$acuan}) <= 30 THEN 1
                WHEN DATEDIFF(?, {$acuan}) <= 60 THEN 2
                WHEN DATEDIFF(?, {$acuan}) <= 90 THEN 3
                ELSE 4 END";

            $q = DB::table($t)
                // GREATEST(...,0): faktur yang kelebihan bayar tidak boleh menggerus
                // nilai kelompok umur yang lain menjadi minus.
                ->selectRaw(
                    $bucket." as bucket, SUM(GREATEST({$nilai} - {$bayar}, 0)) as sisa",
                    [$today, $today, $today, $today]
                )
                ->groupBy('bucket');

            if (Schema::hasColumn($t, 'deleted_at')) {
                $q->whereNull('deleted_at');
            }

            // POSISI: hanya faktur yang SUDAH terbit pada tanggal akhir periode. Penyaring yang
            // sama persis dengan kartu posisi lain (Neraca, Aset Tetap, Rekonsiliasi Piutang),
            // lewat helper yang sama — termasuk penjaga Schema::hasColumn di dalamnya, sehingga
            // skema tanpa invoice_date tidak disaring alih-alih melempar QueryException.
            $this->sampaiPeriode($q, 'invoice_date', $p, $t);

            foreach ($q->get() as $r) {
                $vals[(int) $r->bucket] = round((float) $r->sisa, 2);
            }
        }

        return ['rows' => array_map(
            static fn (string $n, float $v): array => ['n' => $n, 'v' => $v],
            $labels,
            $vals
        )];
    }

    /**
     * GRAFIK 7 — Summary Neraca: dua batang bertumpuk (AKTIVA & PASIVA).
     *
     * HANYA jurnal berstatus 'posted' yang dihitung. Siklus jurnal di aplikasi ini adalah
     * draft → final → posted, dan 'final' berarti MENUNGGU APPROVAL — itu pula status bawaan
     * jurnal otomatis dari modul lain (config/business_support.php: 'auto_status'). Seluruh
     * laporan GL resmi (ComparativeReportController, LedgerReportController, CashFlowController)
     * memakai where(status, 'posted'), jadi memasukkan 'final' akan membuat angka dashboard
     * tidak pernah cocok dengan Neraca resmi.
     *
     * Saldonya KUMULATIF sejak awal, tanpa batas tanggal — sama dengan Neraca resmi yang
     * menjumlah seluruh mutasi s/d tanggal acuan.
     *
     * @return array{rows: array<int, array{n: string, a: float, b: float}>, keys: array<int, array{k: string, n: string, c: int}>}
     */
    private function chartSummaryNeraca(array $p): array
    {
        $keys = [
            ['k' => 'a', 'n' => 'Lancar / Kewajiban', 'c' => 0],
            ['k' => 'b', 'n' => 'Tetap / Ekuitas',    'c' => 2],
        ];
        $kosong = [
            'rows' => [
                ['n' => 'AKTIVA', 'a' => 0.0, 'b' => 0.0],
                ['n' => 'PASIVA', 'a' => 0.0, 'b' => 0.0],
            ],
            'keys' => $keys,
        ];

        // Semua kolom yang dipakai query dijaga di muka; hasColumns() cukup satu kali
        // pembacaan daftar kolom per tabel, lebih hemat daripada hasColumn() berulang.
        $siap = Schema::hasTable('bs_journal_lines') && Schema::hasTable('bs_journals') && Schema::hasTable('bs_accounts')
            && Schema::hasColumns('bs_journal_lines', ['journal_id', 'account_id', 'debit', 'credit'])
            && Schema::hasColumns('bs_accounts', ['id', 'type', 'code'])
            && Schema::hasColumn('bs_journals', 'id');

        if (! $siap) {
            return $kosong;
        }

        $q = DB::table('bs_journal_lines as jl')
            ->join('bs_journals as j', 'j.id', '=', 'jl.journal_id')
            ->join('bs_accounts as a', 'a.id', '=', 'jl.account_id')
            // Golongan neraca diambil dari tipe akun; khusus aset dipecah lewat kode level-2:
            // '101' = ASET LANCAR, sisanya ('102' tetap, '103' tidak lancar lainnya) masuk kolom kedua.
            // Pemecahan lewat kode ini KHAS dashboard: Neraca resmi hanya memisah per type,
            // jadi TOTAL aktiva sama, tetapi pembagian dua segmen batangnya tidak ada padanannya.
            ->selectRaw("CASE
                    WHEN a.type = 'asset' AND a.code LIKE '101%' THEN 'aktiva_lancar'
                    WHEN a.type = 'asset'     THEN 'aktiva_tetap'
                    WHEN a.type = 'liability' THEN 'kewajiban'
                    WHEN a.type = 'equity'    THEN 'ekuitas'
                    WHEN a.type = 'income'    THEN 'pendapatan'
                    WHEN a.type = 'expense'   THEN 'beban'
                    ELSE 'lainnya' END as gol,
                SUM(jl.debit) as d, SUM(jl.credit) as k")
            ->groupBy('gol');

        // POSISI: neraca menyatakan keadaan PADA satu tanggal, jadi seluruh jurnal sampai
        // tanggal akhir ikut membentuknya — termasuk yang jauh sebelum periode dipilih.
        $this->sampaiPeriode($q, 'journal_date', $p, 'bs_journals', 'j');

        if (Schema::hasColumn('bs_journals', 'status')) {
            $q->where('j.status', 'posted');
        }
        if (Schema::hasColumn('bs_journals', 'deleted_at')) {
            $q->whereNull('j.deleted_at');
        }
        if (Schema::hasColumn('bs_accounts', 'deleted_at')) {
            $q->whereNull('a.deleted_at');
        }

        $d = [];
        $k = [];
        foreach ($q->get() as $r) {
            $d[$r->gol] = (float) $r->d;
            $k[$r->gol] = (float) $r->k;
        }

        $saldoDebet  = static fn (string $g): float => ($d[$g] ?? 0.0) - ($k[$g] ?? 0.0);
        $saldoKredit = static fn (string $g): float => ($k[$g] ?? 0.0) - ($d[$g] ?? 0.0);

        // Laba/rugi berjalan belum ditutup ke akun modal, padahal secara neraca dia bagian
        // ekuitas. Tanpa ini tinggi batang AKTIVA dan PASIVA tidak akan pernah sama.
        $labaBerjalan = $saldoKredit('pendapatan') - $saldoDebet('beban');

        // BATANG BERTUMPUK TIDAK BISA MENGGAMBARKAN ANGKA NEGATIF: lebarnya sebanding
        // dengan nilai, dan segmen negatif akan menggeser kursor x mundur sehingga segmen
        // berikutnya tumpang tindih — batangnya salah gambar, bukan sekadar jelek.
        // Keadaan ini nyata: rugi berjalan yang melampaui ekuitas membuat 'Tetap / Ekuitas'
        // pada PASIVA jadi negatif; saldo aktiva pun bisa kredit pada data yang belum rapi.
        //
        // Karena itu nilainya dijepit ke nol untuk DIGAMBAR. Angkanya tidak hilang: tooltip
        // tiap segmen memakai nilai yang dijepit ini juga, jadi tidak ada yang berbohong —
        // yang tidak digambar hanyalah arah negatifnya, dan itu memang di luar kemampuan
        // bentuk grafik ini. Kalau ekuitas negatif perlu terbaca, ia layak jadi kartunya
        // sendiri (kolom divergen), bukan dipaksakan ke tumpukan.
        $jepit = static fn (float $v): float => round(max(0.0, $v), 2);

        return [
            'rows' => [
                ['n' => 'AKTIVA', 'a' => $jepit($saldoDebet('aktiva_lancar')), 'b' => $jepit($saldoDebet('aktiva_tetap'))],
                ['n' => 'PASIVA', 'a' => $jepit($saldoKredit('kewajiban')),    'b' => $jepit($saldoKredit('ekuitas') + $labaBerjalan)],
            ],
            'keys' => $keys,
        ];
    }

    /**
     * GRAFIK 8 — Summary Laba Rugi 8 bulan terakhir (kolom divergen: laba +, rugi −).
     *
     * Sama seperti Grafik 7: HANYA status 'posted'. 'final' masih menunggu approval, dan
     * laporan Laba Rugi komparatif resmi pun memakai where(status,'posted') + period_year/
     * period_month — dua-duanya diikuti di sini supaya angka bulanan bisa direkonsiliasi.
     *
     * @return array{labels: array<int, string>, vals: array<int, float>}
     */
    private function chartSummaryLabaRugi(array $p): array
    {
        $months = $p['bulan'];
        $labels = $this->labelBulan($months);
        $vals   = array_fill(0, count($months), 0.0);

        $siap = Schema::hasTable('bs_journal_lines') && Schema::hasTable('bs_journals') && Schema::hasTable('bs_accounts')
            && Schema::hasColumns('bs_journal_lines', ['journal_id', 'account_id', 'debit', 'credit'])
            && Schema::hasColumns('bs_journals', ['id', 'period_year', 'period_month'])
            && Schema::hasColumns('bs_accounts', ['id', 'type']);

        if (! $siap) {
            return ['labels' => $labels, 'vals' => $vals];
        }

        // Kunci periode y*100+m: rentang 8 bulan cukup dibandingkan sebagai satu bilangan,
        // jadi tidak perlu fungsi tanggal per baris dan indeks period_year tetap terpakai.
        $kunci = static fn (Carbon $m): int => ((int) $m->format('Y')) * 100 + ((int) $m->format('n'));

        $q = DB::table('bs_journal_lines as jl')
            ->join('bs_journals as j', 'j.id', '=', 'jl.journal_id')
            ->join('bs_accounts as a', 'a.id', '=', 'jl.account_id')
            ->selectRaw("j.period_year as y, j.period_month as m,
                SUM(CASE WHEN a.type = 'income'  THEN jl.credit - jl.debit ELSE 0 END) as pendapatan,
                SUM(CASE WHEN a.type = 'expense' THEN jl.debit  - jl.credit ELSE 0 END) as beban")
            ->whereRaw('(j.period_year * 100 + j.period_month) between ? and ?', [
                $kunci($months[0]),
                $kunci($months[count($months) - 1]),
            ])
            ->groupBy('y', 'm');

        if (Schema::hasColumn('bs_journals', 'status')) {
            $q->where('j.status', 'posted');
        }
        if (Schema::hasColumn('bs_journals', 'deleted_at')) {
            $q->whereNull('j.deleted_at');
        }
        if (Schema::hasColumn('bs_accounts', 'deleted_at')) {
            $q->whereNull('a.deleted_at');
        }

        $perPeriode = [];
        foreach ($q->get() as $r) {
            $perPeriode[((int) $r->y) * 100 + ((int) $r->m)] = (float) $r->pendapatan - (float) $r->beban;
        }
        foreach ($months as $i => $m) {
            $vals[$i] = round($perPeriode[$kunci($m)] ?? 0.0, 2);
        }

        return ['labels' => $labels, 'vals' => $vals];
    }

// ── Penyambungan ke metrics(): tambahkan tiga baris ini di dalam array 'charts' ──
// Kunci HARUS persis seperti yang dibaca resources/js/Pages/Dashboard/Index.vue
// (props.charts.aging / .neraca / .laba_rugi) — bukan 'aging_hutang' dsb.
//            'aging'     => $this->chartAgingHutang(),
//            'neraca'    => $this->chartSummaryNeraca(),
//            'laba_rugi' => $this->chartSummaryLabaRugi(),

    // ── KELOMPOK ASET & BUDGET ────────────────────────────────

    /**
     * GRAFIK 9 — Overview Aset Tetap: batang bertumpuk nilai buku + akumulasi penyusutan per kategori.
     *
     * SUMBER ANGKA: KARTU ASET (subledger bs_fixed_assets) — SUM(acquisition_cost) dan
     * SUM(accumulated_depreciation) per kategori, aset terjual/dihapus dikeluarkan.
     *
     * Halaman modul "Overview Aset Tetap" memakai sumber LAIN: saldo GL akun aset & akumulasi
     * menurut config('business_support.fixed_asset') — lihat FixedAssetController::glByCategory()
     * yang menyebut GL sebagai sumber tunggalnya. Kedua angka itu BISA BERBEDA; halaman modul bahkan
     * sengaja memajang kolom selisih subledger-vs-GL karena penyimpangan memang diantisipasi.
     * Jadi kartu dashboard ini TIDAK mengklaim sama dengan halaman modul maupun dengan Neraca Saldo.
     * Untuk angka yang harus cocok dengan neraca, bacalah halaman modulnya.
     *
     * @return array{rows: array<int, array{n: string, a: float, b: float}>, keys: array<int, array{k: string, n: string, c: int}>}
     */
    private function chartAsetTetap(array $p): array
    {
        // Legenda tetap dikirim walau baris kosong supaya layar bisa menggambar kerangka grafiknya.
        $keys = [
            ['k' => 'b', 'n' => 'Akumulasi penyusutan', 'c' => 3],
            ['k' => 'a', 'n' => 'Nilai buku', 'c' => 2],
        ];

        if (! Schema::hasTable('bs_fixed_assets')
            || ! Schema::hasColumn('bs_fixed_assets', 'category')
            || ! Schema::hasColumn('bs_fixed_assets', 'acquisition_cost')
            || ! Schema::hasColumn('bs_fixed_assets', 'accumulated_depreciation')) {
            return ['rows' => [], 'keys' => $keys];
        }

        // Label pendek khusus sumbu grafik. Sengaja TIDAK memakai BsFixedAsset::CATEGORIES karena
        // dua labelnya terlalu panjang untuk sumbu: 'Tanah (tidak disusutkan)' dan 'Inventaris Kantor'.
        $labels = [
            'bangunan'   => 'Bangunan',
            'kendaraan'  => 'Kendaraan',
            'inventaris' => 'Inventaris',
            'tanah'      => 'Tanah',
        ];

        $q = DB::table('bs_fixed_assets')
            ->select('category')
            ->selectRaw('COALESCE(SUM(acquisition_cost), 0) as cost')
            ->selectRaw('COALESCE(SUM(accumulated_depreciation), 0) as accum')
            ->groupBy('category')
            ->orderByDesc(DB::raw('SUM(acquisition_cost)'))
            ->limit(6); // batas 6 kategori sesuai lebar grafik

        if (Schema::hasColumn('bs_fixed_assets', 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        // POSISI: hanya aset yang SUDAH diperoleh pada tanggal akhir periode. Aset yang
        // dibeli sesudahnya belum ada di neraca tanggal itu.
        $this->sampaiPeriode($q, 'acquisition_date', $p, 'bs_fixed_assets');
        // Aset terjual/dihapus sudah keluar dari neraca, jadi tidak lagi punya nilai buku untuk dipajang.
        if (Schema::hasColumn('bs_fixed_assets', 'status')) {
            $q->whereNotIn('status', ['sold', 'disposed']);
        }

        $rows = [];
        foreach ($q->get() as $r) {
            $cost  = (float) $r->cost;
            $accum = (float) $r->accum;
            $rows[] = [
                'n' => $labels[(string) $r->category] ?? ucfirst((string) $r->category),
                // Akumulasi melebihi harga perolehan berarti data cacat; batang tak boleh negatif.
                'a' => round(max(0.0, $cost - $accum), 2),
                'b' => round(max(0.0, $accum), 2),
            ];
        }

        return ['rows' => $rows, 'keys' => $keys];
    }

    /**
     * GRAFIK 10 — Realisasi Budget: pemakaian anggaran terhadap plafon per cost center.
     *
     *  - Plafon    = SUM(bs_budgets.amount) berstatus 'approved' tahun berjalan, per cost center.
     *  - Realisasi = baris jurnal BERTANDA menurut posisi normal akun, hanya untuk pasangan
     *                akun + cost center yang memang dianggarkan (lihat realisasiPerCostCenter()).
     *
     * DUA PERBEDAAN SENGAJA terhadap BudgetRealizationService yang dipakai halaman Budget Control —
     * angkanya memang bisa tidak sama, dan itu keputusan sadar, bukan kelalaian:
     *  1. Dashboard hanya menghitung jurnal berstatus 'posted'; Budget Control memakai
     *     draft + final + posted. Seluruh grafik dashboard diseragamkan ke 'posted' supaya tidak
     *     bercerita lain dari laporan resmi (Neraca / Laba Rugi), sebab 'final' masih menunggu
     *     approval. Akibatnya realisasi di kartu ini bisa LEBIH KECIL dari halaman Budget Control.
     *  2. Dashboard membuang jurnal yang di-soft-delete (whereNull j.deleted_at);
     *     BudgetRealizationService::base() belum menyaringnya. Bila ada jurnal terhapus, realisasi
     *     di kartu ini juga lebih kecil daripada di halaman Budget Control.
     *
     * Dua query agregat, tanpa perulangan per budget.
     *
     * @return array{rows: array<int, array{n: string, real: float, plafon: float}>}
     */
    private function chartRealisasiBudget(array $p): array
    {
        // Semua kolom yang dirujuk query plafon dijaga di sini: lingkungan yang migrasinya belum
        // lengkap harus dapat struktur kosong, bukan QueryException.
        if (! Schema::hasTable('bs_budgets') || ! Schema::hasTable('bs_cost_centers')
            || ! Schema::hasColumn('bs_budgets', 'cost_center_id') || ! Schema::hasColumn('bs_budgets', 'year')
            || ! Schema::hasColumn('bs_budgets', 'amount') || ! Schema::hasColumn('bs_cost_centers', 'name')) {
            return ['rows' => []];
        }

        // Anggaran disusun per TAHUN, jadi yang dipakai tahun dari tanggal akhir periode —
        // bukan tahun berjalan. Memilih periode 2025 lalu membandingkannya dengan anggaran
        // 2026 adalah perbandingan yang tidak setara.
        $year = (int) $p['akhir']->year;

        // 1) Plafon per cost center. havingRaw membuang cost center berplafon 0 di sisi DB
        //    supaya layar tak pernah membagi dengan nol.
        //    Anggaran TANPA cost center tidak dibuang: BudgetController memvalidasi
        //    cost_center_id sebagai nullable, jadi menyaringnya keluar akan menyembunyikan
        //    plafon yang benar-benar ada. Dikumpulkan ke satu ember bernama, sejalan dengan
        //    pola 'Tanpa cluster' pada Grafik 11/12/17/18. LEFT JOIN, bukan JOIN, agar baris
        //    ber-cost_center_id NULL tidak lenyap di tingkat join.
        //    Sentinel 0 dipakai untuk "tanpa cost center" supaya baris itu tetap punya kunci
        //    yang bisa dicocokkan dengan realisasinya, tanpa bergantung pada perbandingan NULL.
        $plafon = DB::table('bs_budgets as bg')
            ->leftJoin('bs_cost_centers as cc', 'cc.id', '=', 'bg.cost_center_id')
            ->selectRaw('COALESCE(bg.cost_center_id, 0) as cc_id')
            ->selectRaw("COALESCE(cc.name, 'Tanpa cost center') as cc_name")
            ->selectRaw('COALESCE(SUM(bg.amount), 0) as plafon')
            ->where('bg.year', $year)
            ->groupByRaw("COALESCE(bg.cost_center_id, 0), COALESCE(cc.name, 'Tanpa cost center')")
            ->havingRaw('COALESCE(SUM(bg.amount), 0) > 0')
            ->orderByDesc(DB::raw('SUM(bg.amount)'))
            ->limit(6); // enam cost center berplafon terbesar

        if (Schema::hasColumn('bs_budgets', 'status')) {
            $plafon->where('bg.status', 'approved');
        }
        if (Schema::hasColumn('bs_cost_centers', 'deleted_at')) {
            $plafon->whereNull('cc.deleted_at');
        }

        $baris = $plafon->get();
        if ($baris->isEmpty()) {
            return ['rows' => []];
        }

        $real = $this->realisasiPerCostCenter($year, $baris->pluck('cc_id')->all(), (int) $p['akhir']->month);

        $rows = [];
        foreach ($baris as $r) {
            $rows[] = [
                'n'      => (string) $r->cc_name,
                // Realisasi negatif (koreksi melebihi beban) tak punya arti pada meter → dijepit ke 0.
                'real'   => round(max(0.0, (float) ($real[(int) $r->cc_id] ?? 0)), 2),
                'plafon' => round((float) $r->plafon, 2),
            ];
        }

        return ['rows' => $rows];
    }

    /**
     * Realisasi bertanda per cost center dalam SATU query agregat.
     *
     * @param  array<int, int|string>  $ccIds
     * @return array<int, float>
     */
    /**
     * @param  int  $sampaiBulan  bulan terakhir yang ikut dihitung (1–12).
     */
    private function realisasiPerCostCenter(int $year, array $ccIds, int $sampaiBulan = 12): array
    {
        // Penjagaan kolom lengkap — sengaja disebut satu per satu supaya kolom yang belum ada di
        // lingkungan tertentu berujung pada struktur kosong, bukan QueryException.
        $siap = $ccIds !== []
            && Schema::hasTable('bs_journal_lines') && Schema::hasTable('bs_journals')
            && Schema::hasTable('bs_accounts') && Schema::hasTable('bs_budgets')
            && Schema::hasColumn('bs_journal_lines', 'cost_center_id')
            && Schema::hasColumn('bs_journals', 'period_year')
            && Schema::hasColumn('bs_accounts', 'type')
            && Schema::hasColumn('bs_budgets', 'year')
            && Schema::hasColumn('bs_budgets', 'cost_center_id')
            && Schema::hasColumn('bs_budgets', 'account_id');

        if (! $siap) {
            return [];
        }

        // Cost center efektif: diisi di baris (mis. AP) atau — bila kolomnya ada — di kepala jurnal
        // (memorial). Akun yang boleh tanpa cost center hanya diakui bila cost center-nya benar-benar
        // diisi di baris; aturan ini mengikuti BudgetRealizationService::base(). Kepala jurnal hanya
        // ikut dihitung kalau bs_journals.cost_center_id memang ada, supaya query tak pernah meledak.
        $ccEfektif = Schema::hasColumn('bs_journals', 'cost_center_id')
            ? 'COALESCE(jl.cost_center_id, j.cost_center_id)'
            : 'jl.cost_center_id';
        $ccExpr = Schema::hasColumn('bs_accounts', 'cost_center_optional')
            ? 'CASE WHEN a.cost_center_optional = 1 THEN jl.cost_center_id ELSE '.$ccEfektif.' END'
            : $ccEfektif;

        // Hanya pasangan akun+cost center yang dianggarkan yang boleh dihitung sebagai realisasi.
        // cost_center_id NULL dinormalkan ke sentinel 0 di kedua sisi perbandingan, sejalan
        // dengan kunci yang dipakai daftar plafon — tanpa itu, anggaran tanpa cost center
        // tidak akan pernah berpasangan dengan jurnalnya (NULL = NULL bernilai UNKNOWN).
        $anggaran = DB::table('bs_budgets')
            ->selectRaw('COALESCE(cost_center_id, 0) as cost_center_id, account_id')
            ->where('year', $year)
            ->whereIn(DB::raw('COALESCE(cost_center_id, 0)'), $ccIds)
            ->groupByRaw('COALESCE(cost_center_id, 0), account_id');

        if (Schema::hasColumn('bs_budgets', 'status')) {
            $anggaran->where('status', 'approved');
        }

        $q = DB::table('bs_journal_lines as jl')
            ->join('bs_journals as j', 'j.id', '=', 'jl.journal_id')
            ->join('bs_accounts as a', 'a.id', '=', 'jl.account_id')
            ->joinSub($anggaran, 'b', function ($join) use ($ccExpr) {
                $join->on('b.account_id', '=', 'jl.account_id')
                    ->whereRaw('COALESCE('.$ccExpr.', 0) = b.cost_center_id');
            })
            ->where('j.period_year', $year)
            ->groupBy('b.cost_center_id')
            // Realisasi dipotong pada BULAN AKHIR periode, bukan setahun penuh: memilih
            // periode s/d Maret lalu membandingkannya dengan realisasi Desember membuat
            // "Sisa" tampak jauh lebih kecil daripada keadaan pada tanggal yang diminta.
            //
            // Batas bawahnya sengaja TIDAK dipasang. Plafon anggaran bersifat tahunan, jadi
            // sisanya hanya bermakna terhadap realisasi sejak awal tahun; memotong dari
            // tanggal awal periode akan menghasilkan "Sisa" yang lebih besar daripada yang
            // sebenarnya tersedia — angka yang terlihat wajar dan menyesatkan.
            ->when(
                $sampaiBulan < 12 && Schema::hasColumn('bs_journals', 'period_month'),
                fn ($w) => $w->where('j.period_month', '<=', $sampaiBulan)
            )
            ->select('b.cost_center_id as cc_id')
            // Akun bersaldo normal kredit dibalik tandanya supaya pemakaian anggaran selalu positif.
            ->selectRaw('COALESCE(SUM(CASE WHEN a.type IN (?, ?, ?) THEN jl.credit - jl.debit ELSE jl.debit - jl.credit END), 0) as v', ['liability', 'equity', 'income']);

        // Seluruh grafik dashboard seragam memakai jurnal 'posted' saja — 'final' masih menunggu
        // approval, jadi memasukkannya membuat kartu ini tak cocok dengan laporan resmi.
        if (Schema::hasColumn('bs_journals', 'status')) {
            $q->where('j.status', 'posted');
        }
        if (Schema::hasColumn('bs_journals', 'deleted_at')) {
            $q->whereNull('j.deleted_at');
        }
        if (Schema::hasColumn('bs_accounts', 'deleted_at')) {
            $q->whereNull('a.deleted_at');
        }

        $out = [];
        foreach ($q->get() as $r) {
            $out[(int) $r->cc_id] = (float) $r->v;
        }

        return $out;
    }

    // ── Grafik Contractor Management ──────────────────────────────────────────

    /**
     * Grafik 11 — SPK per Cluster: jumlah SPK dan total nilainya.
     *
     * ATURAN ATRIBUSI: satu SPK dihitung UTUH pada SATU cluster saja, yaitu cluster
     * DOMINAN-nya — cluster dengan porsi unit_price terbesar (seri dipecah oleh id cluster
     * terkecil supaya hasilnya tidak berubah-ubah antar pemuatan).
     *
     * Mengapa bukan dibagi proporsional: tidak ada aturan di mana pun yang memaksa
     * Σ unit_price sama dengan contract_value — ContractorController hanya memvalidasi
     * 'units.*.unit_price' => nullable|numeric|min:0, dan ContractorService::create cuma
     * melewati porsi <= 0. Membagi nilai memakai porsi yang tidak lengkap akan MENGHILANGKAN
     * sisa nilai SPK tanpa jejak. Dengan atribusi utuh, dua total di layar tetap persis sama
     * dengan modul kontraktor: Σ jumlah = jumlah SPK, Σ nilai = Σ (contract_value + addendum).
     *
     * SPK yang belum punya alokasi unit tidak dibuang — masuk ember "Tanpa Cluster",
     * karena SPK tanpa alokasi adalah jalur normal (validasinya 'units' => nullable|array).
     *
     * @return array{rows: array<int, array{n: string, jml: int, nilai: float}>}
     */
    private function chartSpkPerCluster(array $p): array
    {
        $t = 'bs_work_orders';
        if (! Schema::hasTable($t) || ! Schema::hasColumn($t, 'contract_value')) {
            return ['rows' => []];
        }

        // contract_value SUDAH memuat addendum: ContractorService::addendum() menulis
        // contract_value = lama + delta sekaligus menumpuknya di addendum_value. Menjumlah
        // keduanya menghitung addendum DUA KALI dan membuat nilai di kartu ini melebihi
        // nilai kontrak yang dipajang modul Contractor Management.
        $nilaiSpk = 'COALESCE(w.contract_value,0)';

        $bisaCluster = Schema::hasTable('bs_work_order_units')
            && Schema::hasColumn('bs_work_order_units', 'unit_id')
            && Schema::hasTable('units') && Schema::hasColumn('units', 'cluster_id')
            && Schema::hasTable('clusters') && Schema::hasColumn('clusters', 'name');

        // Ekspresi label harus ikut berubah saat tabel clusternya tidak ada: kalau tetap
        // menyebut d.cluster_name sementara sub-query 'd' tidak pernah di-join, MySQL
        // melempar "Unknown column" — struktur kosong yang dijanjikan malah jadi error.
        $labelCluster = $bisaCluster ? "COALESCE(d.cluster_name, 'Tanpa Cluster')" : "'Tanpa Cluster'";

        $q = DB::table($t.' as w')->selectRaw(
            "{$labelCluster} AS n, COUNT(*) AS jml, SUM({$nilaiSpk}) AS nilai"
        );

        if ($bisaCluster) {
            // Porsi tiap cluster dalam satu SPK, lalu ambil yang terbesar (window function,
            // MySQL 8). Satu baris per work_order_id — tidak ada loop per SPK.
            $porsi = DB::table('bs_work_order_units as wu')
                ->join('units as u', 'u.id', '=', 'wu.unit_id')
                ->join('clusters as c', 'c.id', '=', 'u.cluster_id')
                ->selectRaw('wu.work_order_id, c.id AS cluster_id, c.name AS cluster_name, SUM(COALESCE(wu.unit_price,0)) AS porsi')
                ->when(Schema::hasColumn('units', 'deleted_at'), fn ($w) => $w->whereNull('u.deleted_at'))
                ->when(Schema::hasColumn('clusters', 'deleted_at'), fn ($w) => $w->whereNull('c.deleted_at'))
                ->groupBy('wu.work_order_id', 'c.id', 'c.name');

            $dominan = DB::query()->fromSub($porsi, 'p')->selectRaw(
                'p.work_order_id, p.cluster_name,
                 ROW_NUMBER() OVER (PARTITION BY p.work_order_id ORDER BY p.porsi DESC, p.cluster_id ASC) AS rn'
            );

            $q->leftJoinSub($dominan, 'd', fn ($j) => $j->on('d.work_order_id', '=', 'w.id')->where('d.rn', '=', 1));
        }

        if (Schema::hasColumn($t, 'deleted_at')) {
            $q->whereNull('w.deleted_at');
        }
        // ALIRAN: SPK yang TERBIT pada periode itu — diukur dari tanggal SPK-nya, bukan
        // dari kapan progresnya dicatat.
        $this->dalamPeriode($q, 'spk_date', $p, $t, 'w');
        // SPK batal tidak lagi menjadi pekerjaan berjalan, jadi tidak ikut dihitung.
        // SPK 'closed' TETAP dihitung: retensinya sudah dilepas tapi pekerjaannya nyata dan
        // nilainya tetap bagian dari beban cluster. Label panel di layar karena itu berbunyi
        // "JUMLAH SPK (TIDAK BATAL)", bukan "AKTIF" — supaya tidak menjanjikan angka yang
        // sama dengan ringkasan 'active' pada modul Contractor Management.
        if (Schema::hasColumn($t, 'status')) {
            $q->where('w.status', '!=', 'cancelled');
        }

        // Batas 5 baris, BUKAN 6: ramp oranye hanya punya 6 langkah dan indeks 0 dilewati
        // (langkah terpucat tidak lolos ambang kontras), jadi baris ke-6 akan berwarna kembar
        // dengan baris ke-5. Sejalan pula dengan Grafik 12 & 18 yang juga membatasi 5.
        $rows = [];
        foreach ($q->groupByRaw($labelCluster)->orderByRaw('nilai DESC')->limit(5)->get() as $r) {
            $rows[] = ['n' => (string) $r->n, 'jml' => (int) $r->jml, 'nilai' => round((float) $r->nilai, 2)];
        }

        return ['rows' => $rows];
    }

    /**
     * Grafik 12 — Progress Lapangan: sebaran UNIT menurut tahap pembangunan.
     *
     * TIGA HAL YANG MUDAH SALAH DI SINI, dan cara menghindarinya:
     *
     * 1. POPULASI UNIT tidak boleh diambil dari bs_work_order_units saja.
     *    ContractorService::addProgressBulk punya cabang fallback untuk SPK yang BELUM punya
     *    alokasi unit, dan cabang itu TETAP menulis unit_id nyata ke bs_work_order_progress_units.
     *    Kalau tabel alokasi dijadikan satu-satunya penggerak dengan INNER JOIN, progres yang
     *    benar-benar tercatat hilang total. Populasi di sini = GABUNGAN kedua tabel.
     *
     * 2. SATU UNIT harus diklasifikasikan SEKALI, bukan sekali per SPK. Satu unit fisik bisa
     *    dialokasikan ke dua SPK (mis. bangunan + infrastruktur) dengan akumulasi berbeda;
     *    mengelompokkan per pasangan (SPK, unit) membuat tinggi tumpukan melebihi jumlah unit
     *    fisik. Karena itu akumulasi dihitung dua tingkat: per (SPK, unit) — sebab plafon 100%
     *    berlaku per SPK — lalu diambil YANG TERTINGGI per unit sebagai tahap unit tersebut.
     *
     * 3. Unit tanpa cluster tidak boleh lenyap. clusters di-LEFT JOIN dan dikelompokkan pada
     *    COALESCE(nama, 'Tanpa Cluster'), sejalan dengan Grafik 11 yang bersebelahan di layar.
     *
     * @return array{rows: array<int, array<string, mixed>>, keys: array<int, array{k: string, n: string, c: int}>}
     */
    private function chartProgressLapangan(array $p): array
    {
        $keys = [
            ['k' => 'a', 'n' => 'Tanah Matang', 'c' => 0],
            ['k' => 'b', 'n' => 'Pondasi',      'c' => 2],
            ['k' => 'c', 'n' => 'Topping Off',  'c' => 3],
            ['k' => 'd', 'n' => 'Selesai',      'c' => 5],
        ];
        $kosong = ['rows' => [], 'keys' => $keys];

        $adaAlokasi = Schema::hasTable('bs_work_order_units') && Schema::hasColumn('bs_work_order_units', 'unit_id');
        $adaProgres = Schema::hasTable('bs_work_order_progress_units')
            && Schema::hasColumn('bs_work_order_progress_units', 'unit_id')
            && Schema::hasColumn('bs_work_order_progress_units', 'progress_pct')
            && Schema::hasTable('bs_work_order_progress')
            && Schema::hasColumn('bs_work_order_progress', 'work_order_id');

        if (! $adaAlokasi && ! $adaProgres) {
            return $kosong;
        }
        if (! Schema::hasTable('units')) {
            return $kosong;
        }

        // Tingkat 1 — akumulasi persen per (SPK, unit). Plafon 100% memang berlaku per SPK.
        $perPasangan = $adaProgres
            ? DB::table('bs_work_order_progress_units as pu')
                ->join('bs_work_order_progress as pr', 'pr.id', '=', 'pu.progress_id')
                ->selectRaw('pr.work_order_id, pu.unit_id, SUM(COALESCE(pu.progress_pct,0)) AS pct')
                ->groupBy('pr.work_order_id', 'pu.unit_id')
            : null;
        // POSISI: tahap yang sudah tercapai PADA tanggal akhir periode. Progres sesudahnya
        // belum boleh ikut, dan progres sebelum periode tetap harus ikut — tanpa itu unit
        // yang selesai tahun lalu akan terbaca mundur ke 'Tanah Matang'.
        if ($perPasangan !== null) {
            $this->sampaiPeriode($perPasangan, 'progress_date', $p, 'bs_work_order_progress', 'pr');
        }

        // Tingkat 2 — tahap sebuah unit = akumulasi TERTINGGI di antara SPK yang menggarapnya.
        $perUnit = $perPasangan
            ? DB::query()->fromSub($perPasangan, 'x')->selectRaw('x.unit_id, MAX(x.pct) AS pct')->groupBy('x.unit_id')
            : null;

        // Populasi unit: gabungan unit yang dialokasikan dan unit yang punya catatan progres.
        //
        // POPULASINYA IKUT DIPOTONG PERIODE. Tanpa itu, unit dari SPK yang baru terbit tahun
        // 2026 tetap muncul saat operator memilih periode 2019 — semuanya jatuh ke ember
        // "Tanah Matang" karena progresnya belum ada, sehingga kartunya menampilkan angka
        // yang sama persis untuk periode mana pun dan terlihat seolah memang begitu keadaannya.
        // Unit hanya "ada" sejak SPK yang menggarapnya terbit.
        $populasi = null;
        if ($adaAlokasi) {
            $populasi = DB::table('bs_work_order_units as wu')->select('wu.unit_id')->distinct();
            if (Schema::hasTable('bs_work_orders') && Schema::hasColumn('bs_work_orders', 'spk_date')) {
                $populasi->join('bs_work_orders as wo', 'wo.id', '=', 'wu.work_order_id');
                $this->sampaiPeriode($populasi, 'spk_date', $p, 'bs_work_orders', 'wo');
                if (Schema::hasColumn('bs_work_orders', 'deleted_at')) {
                    $populasi->whereNull('wo.deleted_at');
                }
            }
        }
        if ($adaProgres) {
            $dariProgres = DB::table('bs_work_order_progress_units as pgu')->select('pgu.unit_id')->distinct()
                ->join('bs_work_order_progress as pg', 'pg.id', '=', 'pgu.progress_id');
            $this->sampaiPeriode($dariProgres, 'progress_date', $p, 'bs_work_order_progress', 'pg');
            $populasi = $populasi ? $populasi->union($dariProgres) : $dariProgres;
        }
        if (! $populasi) {
            return $kosong;
        }

        $adaCluster = Schema::hasColumn('units', 'cluster_id')
            && Schema::hasTable('clusters') && Schema::hasColumn('clusters', 'name');
        $label = $adaCluster ? "COALESCE(c.name, 'Tanpa Cluster')" : "'Semua'";

        // Ember tahap. Batasnya sengaja eksplisit supaya terbaca sebagai keputusan, bukan sisa:
        // 0% = tanah matang (belum ada progres), <50% pondasi, <100% topping off, >=100% selesai.
        $pct = 'COALESCE(g.pct, 0)';
        $q = DB::table('units as u')
            ->joinSub($populasi, 'p', fn ($j) => $j->on('p.unit_id', '=', 'u.id'))
            ->selectRaw(
                $label." AS n,
                 COUNT(DISTINCT CASE WHEN {$pct} <= 0                        THEN u.id END) AS a,
                 COUNT(DISTINCT CASE WHEN {$pct} > 0   AND {$pct} < 50       THEN u.id END) AS b,
                 COUNT(DISTINCT CASE WHEN {$pct} >= 50 AND {$pct} < 100      THEN u.id END) AS c,
                 COUNT(DISTINCT CASE WHEN {$pct} >= 100                      THEN u.id END) AS d"
            )
            ->groupByRaw($label)
            ->orderByRaw('(a + b + c + d) DESC')
            ->limit(5);

        if ($perUnit) {
            $q->leftJoinSub($perUnit, 'g', fn ($j) => $j->on('g.unit_id', '=', 'u.id'));
        } else {
            $q->leftJoin(DB::raw('(SELECT NULL AS unit_id, 0 AS pct) AS g'), fn ($j) => $j->on('g.unit_id', '=', 'u.id'));
        }
        if (Schema::hasColumn('units', 'deleted_at')) {
            $q->whereNull('u.deleted_at');
        }
        if ($adaCluster) {
            $q->leftJoin('clusters as c', function ($j) {
                $j->on('c.id', '=', 'u.cluster_id');
                if (Schema::hasColumn('clusters', 'deleted_at')) {
                    $j->whereNull('c.deleted_at');
                }
            });
        }

        $rows = [];
        foreach ($q->get() as $r) {
            $rows[] = [
                'n' => (string) $r->n,
                'a' => (int) $r->a, 'b' => (int) $r->b, 'c' => (int) $r->c, 'd' => (int) $r->d,
            ];
        }

        return ['rows' => $rows, 'keys' => $keys];
    }

    /**
     * Grafik 13 — Summary Retensi KONTRAKTOR: nilai ditahan vs dilepas per bulan.
     *
     * SUMBERNYA RETENSI KONTRAKTOR, BUKAN RETENSI KPR. Kartu ini duduk di antara Grafik 11
     * dan 12 dengan tanda modul "CONTRACTOR MGT", jadi angkanya harus berasal dari SPK:
     *  - ditahan : bs_work_order_progress.retention_amount, dibulankan pada tanggal progres.
     *              Di situlah ContractorService menahan retensi tiap kali progres dicatat
     *              (lihat penambahan retention_held pada baris 188 & 396 servicenya).
     *  - dilepas : bs_work_orders.retention_held yang dilepas, dibulankan pada
     *              retention_released_at — yang diisi releaseRetention() saat SPK ditutup.
     *
     * Dua tabel kpr_retentions/kpr_retention_releases yang dipakai sebelumnya adalah retensi
     * pencairan KPR milik modul Bank Loan — perkara yang sama sekali berbeda, dan angkanya
     * tidak akan pernah cocok dengan Summary Retensi di modul Contractor Management.
     *
     * Pelepasan yang DITOLAK saat approval tidak dihitung — uangnya memang tidak keluar.
     *
     * @return array{labels: array<int, string>, series: array<int, array{n: string, c: int, v: array<int, float>}>}
     */
    private function chartSummaryRetensi(array $p): array
    {
        $bulan  = $p['bulan'];
        $labels = $this->labelBulan($bulan);

        $ditahan = $this->bulananJumlah('bs_work_order_progress', 'progress_date', 'retention_amount', $bulan);
        // retention_held disetel 0 saat dilepas, jadi nilainya diambil dari sisi progres:
        // total retensi SPK yang tanggal lepasnya jatuh di bulan itu.
        $dilepas = $this->retensiSpkDilepas($bulan);

        // Dua-duanya kosong → jangan tampilkan deretan nol yang menyaru data.
        if (array_sum($ditahan) <= 0 && array_sum($dilepas) <= 0) {
            return ['labels' => $labels, 'series' => []];
        }

        return ['labels' => $labels, 'series' => [
            ['n' => 'Ditahan', 'c' => 7, 'v' => $ditahan],
            ['n' => 'Dilepas', 'c' => 2, 'v' => $dilepas],
        ]];
    }

    /**
     * Retensi SPK yang DILEPAS per bulan.
     *
     * retention_held di-nol-kan saat pelepasan, jadi nilainya tidak bisa dibaca dari sana
     * setelah kejadian. Yang dipakai adalah jumlah retensi yang pernah ditahan pada seluruh
     * progres SPK itu, dibulankan pada tanggal pelepasannya.
     *
     * @param  array<int, Carbon>  $bulan
     * @return array<int, float>
     */
    private function retensiSpkDilepas(array $bulan): array
    {
        $ember = array_fill(0, count($bulan), 0.0);

        if (! Schema::hasTable('bs_work_orders')
            || ! Schema::hasColumn('bs_work_orders', 'retention_released_at')
            || ! Schema::hasTable('bs_work_order_progress')
            || ! Schema::hasColumn('bs_work_order_progress', 'retention_amount')
            || ! Schema::hasColumn('bs_work_order_progress', 'work_order_id')) {
            return $ember;
        }

        $mulai = $bulan[0]->copy()->startOfMonth();

        $q = DB::table('bs_work_orders as w')
            ->join('bs_work_order_progress as p', 'p.work_order_id', '=', 'w.id')
            ->selectRaw("DATE_FORMAT(w.retention_released_at, '%Y-%m') AS bln, SUM(COALESCE(p.retention_amount,0)) AS nilai")
            ->whereNotNull('w.retention_released_at')
            ->where('w.retention_released_at', '>=', $mulai)
            ->groupByRaw("DATE_FORMAT(w.retention_released_at, '%Y-%m')");

        if (Schema::hasColumn('bs_work_orders', 'deleted_at')) {
            $q->whereNull('w.deleted_at');
        }

        $peta = [];
        foreach ($bulan as $i => $m) {
            $peta[$m->format('Y-m')] = $i;
        }
        foreach ($q->get() as $r) {
            $i = $peta[(string) $r->bln] ?? null;
            if ($i !== null) {
                $ember[$i] = round((float) $r->nilai, 2);
            }
        }

        return $ember;
    }

    /**
     * Jumlah kolom nilai per bulan, satu query agregat (GROUP BY tahun-bulan).
     *
     * @param  array<int, Carbon>  $bulan
     * @param  string|null  $tolakKolom  bila ada, baris ber-nilai 'rejected' pada kolom itu dibuang
     * @return array<int, float>
     */
    private function bulananJumlah(string $tabel, string $kolomTgl, string $kolomNilai, array $bulan, ?string $tolakKolom = null): array
    {
        $ember = array_fill(0, count($bulan), 0.0);

        if (! Schema::hasTable($tabel) || ! Schema::hasColumn($tabel, $kolomNilai)) {
            return $ember;
        }
        // Tanggal utama boleh tidak ada (mis. released_at belum dipasang di skema lama) —
        // mundur ke created_at supaya angkanya tetap terbaca, bukan hilang diam-diam.
        $tgl = Schema::hasColumn($tabel, $kolomTgl) ? $kolomTgl : 'created_at';
        if (! Schema::hasColumn($tabel, $tgl)) {
            return $ember;
        }
        $tglEfektif = ($tgl !== 'created_at' && Schema::hasColumn($tabel, 'created_at'))
            ? "COALESCE({$tgl}, created_at)"
            : $tgl;

        $mulai = $bulan[0]->copy()->startOfMonth();
        $q = DB::table($tabel)
            ->selectRaw("DATE_FORMAT({$tglEfektif}, '%Y-%m') AS bln, SUM(COALESCE({$kolomNilai},0)) AS nilai")
            ->whereRaw("{$tglEfektif} >= ?", [$mulai])
            ->groupByRaw("DATE_FORMAT({$tglEfektif}, '%Y-%m')");

        if (Schema::hasColumn($tabel, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        if ($tolakKolom && Schema::hasColumn($tabel, $tolakKolom)) {
            $q->where(fn ($w) => $w->whereNull($tolakKolom)->orWhere($tolakKolom, '!=', 'rejected'));
        }

        $peta = [];
        foreach ($bulan as $i => $m) {
            $peta[$m->format('Y-m')] = $i;
        }
        foreach ($q->get() as $r) {
            $i = $peta[(string) $r->bln] ?? null;
            if ($i !== null) {
                $ember[$i] = round((float) $r->nilai, 2);
            }
        }

        return $ember;
    }

// ── Tempel di dalam class DashboardController ──────────────────────
// Daftarkan di metrics() dengan menambah satu baris pada array kembalian:
//     'bast_ajb_br' => $this->chartBastAjbBooked(),

    /**
     * Grafik 14–16 — corong serah terima: BAST → AJB → Booked Revenue.
     * Digabung dalam satu method karena ketiganya satu alur hidup dokumen
     * (unit diserahkan → akta ditandatangani → pendapatan dibukukan), jadi
     * batang bulanannya wajib memakai skala bulan yang sama supaya jarak antar
     * tahap terbaca sebagai corong.
     *
     * DUA RENTANG WAKTU YANG MEMANG BERBEDA — ini disengaja, jangan "diseragamkan":
     *   - 'unit'  = 8 bulan bergulir, dipakai panel kiri "RINCIAN BULANAN".
     *   - 'nilai' = total TAHUN KALENDER BERJALAN (1 Jan s/d 31 Des tahun ini),
     *     dipakai panel kanan "RINGKASAN TAHUNAN", dihitung lewat query rentang
     *     tersendiri. Alasannya: kalau nilai ikut jendela bergulir, mulai
     *     September jendela itu melintasi dua tahun buku (mis. pada Maret 2027
     *     jadi Agu 2026–Mar 2027) sementara label di layar tetap berbunyi
     *     "TAHUNAN" — angka campuran dua tahun buku di kartu keuangan.
     *
     * @return array{labels: array<int, string>, series: array<int, array<string, mixed>>}
     */
    private function chartBastAjbBooked(array $p): array
    {
        $months = $p['bulan'];

        // Sumber unit BAST = bast_documents: hanya tabel ini yang menyimpan TANGGAL
        // serah terima (handed_over_at) dan tautan booking_id. bs_bast_units sengaja
        // TIDAK dipakai — itu tabel unggahan Excel modul Contractor "Generate BAST"
        // (lihat GenerateBastController::import), isinya hanya identitas unit:
        // tanpa kolom tanggal apa pun dan tanpa booking_id, jadi tidak bisa
        // dibulankan maupun ditautkan ke harga.
        $bast   = $this->serahTerimaSeri('bast_documents', 'handed_over_at', null, $months);
        $ajb    = $this->serahTerimaSeri('ajb_documents', 'signed_at', 'amount', $months);
        $booked = $this->serahTerimaSeri('bs_booked_revenues', 'booking_date', 'amount', $months);

        return [
            'labels' => $this->labelBulan($months),
            // Nama kunci n/c/unit/nilai mengikuti antarmuka SeriGabungan di
            // resources/js/Pages/Dashboard/Index.vue — jangan diganti namanya.
            // Urutan c 0/1/2 dikunci: layar memilih warna seri berdasarkan angka ini,
            // bukan berdasarkan nama seri — jangan ditukar urutannya.
            'series' => [
                ['n' => 'BAST',        'c' => 0, 'unit' => $bast['unit'],   'nilai' => $bast['nilai'],   'unit_tahunan' => $bast['unit_tahunan']],
                ['n' => 'AJB',         'c' => 1, 'unit' => $ajb['unit'],    'nilai' => $ajb['nilai'],    'unit_tahunan' => $ajb['unit_tahunan']],
                ['n' => 'Booked Rev.', 'c' => 2, 'unit' => $booked['unit'], 'nilai' => $booked['nilai'], 'unit_tahunan' => $booked['unit_tahunan']],
            ],
        ];
    }

    /**
     * Satu seri corong serah terima.
     *
     * Menghasilkan dua hal dari dua query agregat terpisah, karena rentang
     * waktunya memang beda (lihat chartBastAjbBooked):
     *   - unit  : jumlah unit per bulan sepanjang 8 bulan bergulir;
     *   - nilai : total rupiah sepanjang TAHUN KALENDER BERJALAN.
     * Dua query per seri itu konstan — bukan N+1; tidak ada loop per dokumen.
     *
     * Semua akses tabel/kolom dijaga Schema:: sehingga sumber yang belum ada
     * menghasilkan seri nol yang jujur, bukan QueryException.
     *
     * @param  array<int, Carbon>  $months
     * @return array{unit: array<int, int>, nilai: float}
     */
    private function serahTerimaSeri(string $table, string $dateCol, ?string $amountCol, array $months): array
    {
        $kosong = ['unit' => array_fill(0, count($months), 0), 'nilai' => 0.0, 'unit_tahunan' => 0];

        if ($months === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $dateCol)) {
            return $kosong;
        }

        // (a) Batang bulanan — jendela 8 bulan bergulir.
        // Batas akhir diturunkan dari $months[0] + (n-1) bulan, bukan end($months):
        // months() memang menghasilkan bulan berurutan, dan end() bertipe Carbon|false
        // sehingga ditolak Larastan level 6.
        $awal  = $months[0]->copy()->startOfMonth();
        $akhir = $awal->copy()->addMonths(count($months) - 1)->endOfMonth();

        // Peta 'YYYY-MM' → posisi bulan, supaya penempatan hasil query O(1).
        $index = [];
        foreach ($months as $i => $m) {
            $index[$m->format('Y-m')] = $i;
        }

        $unit = array_fill(0, count($months), 0);
        foreach ($this->serahTerimaAgregat($table, $dateCol, $amountCol, $awal, $akhir, true) as $r) {
            $i = $index[(string) $r->ym] ?? null;
            if ($i === null) {
                continue;
            }
            $unit[$i] = (int) $r->unit;
        }

        // (b) Kartu nilai — rentang tersendiri: SATU TAHUN PENUH pada tahun periode yang
        // dipilih (bukan tahun berjalan), supaya melihat periode 2025 tidak memulangkan
        // angka tahunan 2026.
        //
        // JUMLAH UNIT TAHUNAN IKUT DIHITUNG DI SINI, tidak boleh diturunkan dengan
        // menjumlahkan ember bulanan di layar: ember bulanan di-dedupe per (unit, BULAN),
        // sehingga satu unit yang dokumennya terbit ulang di bulan berbeda muncul pada dua
        // batang dan akan terhitung dua kali. Dihitung di sini, unit dan rupiah memakai
        // rentang DAN granularitas dedupe yang sama — dua angka pada kartu yang sama tidak
        // lagi saling membantah.
        $nilai = 0.0;
        $unitTahunan = 0;
        $tahun = $akhir->copy();
        foreach ($this->serahTerimaAgregat($table, $dateCol, $amountCol, $tahun->copy()->startOfYear(), $tahun->copy()->endOfYear(), false) as $r) {
            $nilai += (float) $r->nilai;
            $unitTahunan += (int) $r->unit;
        }

        return ['unit' => $unit, 'nilai' => $nilai, 'unit_tahunan' => $unitTahunan];
    }

    /**
     * Agregat satu seri untuk satu rentang tanggal. Satu query, satu perjalanan.
     *
     * ANTI-DOBEL BERLAKU UNTUK KEDUANYA — jumlah unit DAN rupiah dihitung di atas
     * himpunan baris yang sama. Skema mengizinkan satu booking/unit punya lebih
     * dari satu dokumen (bast_number/ajb_number/br_number UNIQUE, tetapi
     * booking_id/unit_id hanya indeks biasa), jadi dokumen revisi atau cetak ganda
     * harus dibuang SEBELUM dijumlah. Dibuang lewat ROW_NUMBER(): yang
     * dipertahankan adalah dokumen TERAKHIR per kunci (tanggal terbesar, lalu id
     * terbesar sebagai pemutus seri), karena revisi terakhir itulah yang berlaku.
     * Kalau rupiah tidak ikut di-dedupe, kartu nilai naik tanpa batang unitnya ikut
     * naik — dua angka di kartu yang sama jadi saling membantah.
     *
     * Granularitas dedupe mengikuti apa yang digambar:
     *   - $perBulan = true  → per (kunci, bulan). Batangnya memang per bulan, jadi
     *     unit yang punya dokumen di dua bulan berbeda memang muncul di dua batang.
     *   - $perBulan = false → per kunci saja sepanjang rentang. Dipakai kartu nilai
     *     tahunan: satu unit menyumbang satu kali dalam setahun.
     * Bila tabelnya tidak punya kunci (tidak ada unit_id maupun booking_id), dedupe
     * tidak mungkin dilakukan; saat itu tiap dokumen dihitung sebagai satu baris —
     * unit dan rupiah tetap sepadan karena dasarnya sama-sama per dokumen.
     *
     * @return array<int, \stdClass>
     */
    private function serahTerimaAgregat(
        string $table,
        string $dateCol,
        ?string $amountCol,
        Carbon $awal,
        Carbon $akhir,
        bool $perBulan,
    ): array {
        $q = DB::table($table.' as d');

        if (Schema::hasColumn($table, 'deleted_at')) {
            $q->whereNull('d.deleted_at');
        }
        // Dokumen batal tidak boleh ikut menaikkan corong. bs_booked_revenues memang
        // memakai status 'cancelled' (BsBookedRevenue::isCancelled) dan menandai
        // cancelled_at; BAST/AJB sampai sekarang hanya pernah menulis 'active'
        // (IssueBast/IssueAjb), jadi ejaan lain di daftar ini bersifat jaga-jaga.
        if (Schema::hasColumn($table, 'status')) {
            $q->whereNotIn('d.status', ['cancelled', 'canceled', 'batal', 'void']);
        }
        if (Schema::hasColumn($table, 'cancelled_at')) {
            $q->whereNull('d.cancelled_at');
        }

        // Nilai rupiah: pakai nominal dokumen bila ada; bila NULL jatuh ke harga
        // booking yang ditunjuk dokumen — bast_documents memang tidak punya kolom
        // nominal sama sekali, jadi seluruh nilainya berasal dari bookings.
        $nilaiParts = [];
        if ($amountCol !== null && Schema::hasColumn($table, $amountCol)) {
            $nilaiParts[] = "d.{$amountCol}";
        }
        if (Schema::hasColumn($table, 'booking_id')
            && Schema::hasTable('bookings')
            && Schema::hasColumn('bookings', 'total_price')) {
            // Syarat deleted_at ditaruh di ON, bukan WHERE: booking terhapus tidak
            // menyumbang nilai, tapi dokumennya tetap terhitung sebagai unit.
            $bookingLunak = Schema::hasColumn('bookings', 'deleted_at');
            $q->leftJoin('bookings as b', function ($j) use ($bookingLunak) {
                $j->on('d.booking_id', '=', 'b.id');
                if ($bookingLunak) {
                    $j->whereNull('b.deleted_at');
                }
            });
            $nilaiParts[] = 'b.total_price';
        }
        $nilaiExpr = $nilaiParts === [] ? '0' : 'COALESCE('.implode(', ', $nilaiParts).', 0)';

        // Nama tabel/kolom berasal dari konstanta di kode ini dan sudah lolos
        // Schema::hasColumn(); tidak ada input pengguna di string SQL. Batas tanggal
        // tetap dikirim sebagai binding.
        $q->whereBetween("d.{$dateCol}", [$awal, $akhir]);

        $ymExpr = "DATE_FORMAT(d.{$dateCol}, '%Y-%m')";

        // Kunci dedupe: unit_id bila ada (bs_booked_revenues), kalau tidak booking_id
        // (bast_documents/ajb_documents tidak punya unit_id).
        $kunci = match (true) {
            Schema::hasColumn($table, 'unit_id')    => 'unit_id',
            Schema::hasColumn($table, 'booking_id') => 'booking_id',
            default                                 => null,
        };

        if ($kunci === null) {
            $select = [DB::raw('COUNT(*) as unit'), DB::raw("SUM({$nilaiExpr}) as nilai")];
            if ($perBulan) {
                $select[] = DB::raw("{$ymExpr} as ym");
                $q->groupBy('ym');
            }

            return $q->get($select)->all();
        }

        $partisi = $perBulan ? "d.{$kunci}, {$ymExpr}" : "d.{$kunci}";
        // id dipakai sebagai pemutus seri supaya dua dokumen bertanggal sama tetap
        // menghasilkan pilihan yang pasti (bukan urutan acak dari MySQL).
        $urut = Schema::hasColumn($table, 'id') ? "d.{$dateCol} DESC, d.id DESC" : "d.{$dateCol} DESC";

        $dalam = [DB::raw("{$nilaiExpr} as nilai"), DB::raw("ROW_NUMBER() OVER (PARTITION BY {$partisi} ORDER BY {$urut}) as rn")];
        if ($perBulan) {
            $dalam[] = DB::raw("{$ymExpr} as ym");
        }

        // fromSub() membawa serta binding tanggal dari query dalam — tidak ada
        // penyambungan string SQL di sini.
        $luar   = DB::query()->fromSub($q->select($dalam), 't')->where('t.rn', '=', 1);
        $select = [DB::raw('COUNT(*) as unit'), DB::raw('SUM(t.nilai) as nilai')];
        if ($perBulan) {
            $select[] = DB::raw('t.ym as ym');
            $luar->groupBy('t.ym');
        }

        return $luar->get($select)->all();
    }

}
