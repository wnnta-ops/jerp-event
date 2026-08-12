<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
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

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $bulan = $this->monthLabels(8);

        return Inertia::render('Dashboard/Index', [
            'greeting' => [
                'name'          => $user?->name,
                'last_login_at' => $user?->last_login_at?->toIso8601String(),
                'last_login_ip' => $user?->last_login_ip,
            ],
            'periode' => [
                // Rentang batang bulanan; ditulis di kepala halaman supaya pembaca tahu
                // angka yang dilihatnya mencakup periode apa.
                'label'   => $bulan[0].'–'.$bulan[count($bulan) - 1].' '.now()->year,
                'project' => (string) (DB::table('projects')->value('name') ?? 'Semua Project'),
            ],
            'charts' => $this->charts(),
        ]);
    }

    /**
     * Data 16 kartu grafik Dashboard Back Office.
     *
     * Nama kuncinya adalah KONTRAK dengan resources/js/Pages/Dashboard/Index.vue —
     * komponen di sana mengambilnya langsung tanpa optional chaining, jadi setiap kunci
     * WAJIB ada walau isinya kosong. Tiap method sudah mengembalikan struktur kosong yang
     * jujur bila tabelnya belum berisi; layar menampilkan keadaan kosong, bukan angka contoh.
     *
     * @return array<string, mixed>
     */
    private function charts(): array
    {
        return [
            'inisiasi_kpr' => $this->chartInisiasiKpr(),        // 1
            'proses_kpr'   => $this->chartProsesKpr(),          // 2
            'penolakan'    => $this->chartPenolakanKpr(),       // 3
            'rekening'     => $this->chartSaldoRekeningKas(),   // 4
            'kasbon'       => $this->chartBonSementara(),       // 5
            'aging'        => $this->chartAgingHutang(),        // 6
            'neraca'       => $this->chartSummaryNeraca(),      // 7
            'laba_rugi'    => $this->chartSummaryLabaRugi(),    // 8
            'aset_tetap'   => $this->chartAsetTetap(),          // 9
            'budget'       => $this->chartRealisasiBudget(),    // 10
            'spk'          => $this->chartSpkPerCluster(),      // 11
            'progress'     => $this->chartProgressLapangan(),   // 12
            'retensi'      => $this->chartSummaryRetensi(),     // 13
            'bast_ajb_br'  => $this->chartBastAjbBooked(),      // 14–16
            'piutang'      => $this->chartRekonsiliasiPiutang(), // 17
            'hpp'          => $this->chartSummaryHpp(),         // 18
        ];
    }


    // ── Grafik keuangan: Hutang (AP) & Buku Besar (GL) ────────


    // ── Helper umum ───────────────────────────────────────────


    /** @return array<int, Carbon> */
    private function months(int $n): array
    {
        $list = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $list[] = now()->startOfMonth()->subMonths($i);
        }

        return $list;
    }

    /** @return array<int, string> */
    private function monthLabels(int $n): array
    {
        return array_map(fn (Carbon $m) => self::MONTHS_ID[$m->month], $this->months($n));
    }


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
    private function chartRekonsiliasiPiutang(): array
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

        // GREATEST(...,0): faktur yang kelebihan bayar tidak boleh membuat sisa jadi minus.
        $sisa     = "SUM(GREATEST({$tagih} - COALESCE(i.paid_amount,0), 0))";
        $terbayar = 'SUM(COALESCE(i.paid_amount,0))';

        // Rantai cluster hanya dipasang bila SEMUA tabel perantaranya ada; bila tidak,
        // grafik tetap tampil sebagai satu ember daripada gagal total.
        $adaRantai = Schema::hasTable('bookings') && Schema::hasColumn('bookings', 'unit_id')
            && Schema::hasTable('units') && Schema::hasColumn('units', 'cluster_id')
            && Schema::hasTable('clusters') && Schema::hasColumn('clusters', 'name')
            && Schema::hasColumn($t, 'booking_id');

        $label = $adaRantai ? "COALESCE(c.name, 'Tanpa cluster')" : "'Semua'";

        $q = DB::table($t.' as i')
            ->selectRaw("{$label} AS n, {$terbayar} AS terbayar, {$sisa} AS sisa")
            ->groupByRaw($label)
            ->orderByRaw('(terbayar + sisa) DESC')
            ->limit(6);

        if ($adaRantai) {
            $q->leftJoin('bookings as b', function ($j) {
                $j->on('b.id', '=', 'i.booking_id');
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

        if (Schema::hasColumn($t, 'deleted_at')) {
            $q->whereNull('i.deleted_at');
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
     * dari tujuh komponen HPP (BsCogsCategory::COMPONENTS). CogsService::generateHpp() sudah
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
    private function chartSummaryHpp(): array
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
        // HPP PENUH unit tersebut — bukan angka tambahan (CogsService::generateHpp menulis
        // pool anggaran tahunan x share tiap kali di-generate untuk periode baru). Menjumlah
        // seluruh baris berarti MENGALIKAN HPP dengan jumlah periode yang pernah di-generate.
        // Konvensi repo memang mengambil satu baris terakhir per unit — lihat
        // CogsQueryController::hppUnit() yang groupBy('unit_id') lalu sortByDesc('id')->first().
        $punyaPeriode = Schema::hasColumn($t, 'period_year') && Schema::hasColumn($t, 'period_month');

        $terakhir = DB::table($t.' as h2')
            ->selectRaw('h2.unit_id, MAX(h2.id) AS id')
            ->when(Schema::hasColumn($t, 'deleted_at'), fn ($w) => $w->whereNull('h2.deleted_at'))
            ->when($punyaPeriode, fn ($w) => $w->whereRaw(
                '(h2.period_year, h2.period_month) = (
                     SELECT h3.period_year, h3.period_month FROM '.$t.' AS h3
                     WHERE h3.unit_id = h2.unit_id'
                     .(Schema::hasColumn($t, 'deleted_at') ? ' AND h3.deleted_at IS NULL' : '').'
                     ORDER BY h3.period_year DESC, h3.period_month DESC, h3.id DESC LIMIT 1
                 )'
            ))
            ->groupBy('h2.unit_id');

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
    private function chartInisiasiKpr(): array
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
    private function chartProsesKpr(): array
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
    private function chartPenolakanKpr(): array
    {
        $bulan  = $this->months(8);
        $labels = array_map(fn (Carbon $m) => self::MONTHS_ID[$m->month], $bulan);
        $kunci  = array_map(fn (Carbon $m) => $m->format('Y-m'), $bulan);
        $mulai  = $bulan[0]->copy()->startOfMonth();

        $ke = fn (array $ember): array => array_map(
            static fn (string $k) => (int) ($ember[$k] ?? 0),
            $kunci
        );

        return [
            'labels' => $labels,
            'series' => [
                ['n' => 'Reject BI Checking', 'c' => 7, 'v' => $ke($this->emberRejectBi($mulai))],
                ['n' => 'Reject Proses KPR',  'c' => 1, 'v' => $ke($this->emberRejectProses($mulai))],
            ],
        ];
    }

    /**
     * Ember bulanan (Y-m => jumlah) inisiasi KPR yang ditolak.
     *
     * @return array<string, int>
     */
    private function emberRejectBi(Carbon $mulai): array
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
            ->where('updated_at', '>=', $mulai)
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
    private function emberRejectProses(Carbon $mulai): array
    {
        if (! Schema::hasTable('kpr_applications') || ! Schema::hasColumn('kpr_applications', 'status')) {
            return [];
        }

        $ym = Schema::hasColumn('kpr_applications', 'decided_at')
            ? "DATE_FORMAT(COALESCE(decided_at, DATE(updated_at)), '%Y-%m')"
            : "DATE_FORMAT(DATE(updated_at), '%Y-%m')";

        $q = DB::table('kpr_applications')
            ->where('status', 'rejected')
            ->where('updated_at', '>=', $mulai)
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
    // Helper months()/monthLabels()/MONTHS_ID SUDAH ada di controller — tidak diduplikasi.
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
    private function chartSaldoRekeningKas(): array
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
            ->havingRaw("{$saldo} <> 0")
            ->orderByRaw("{$saldo} DESC")
            ->limit(8);

        // Hanya jurnal sah yang boleh membentuk saldo (lihat catatan STATUS JURNAL di atas).
        if (Schema::hasColumn('bs_journals', 'status')) {
            $q->where('j.status', 'posted');
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
    private function chartBonSementara(): array
    {
        $n = 8;
        // Label bulan tetap dikirim walau datanya kosong — semua grafik bulanan di dashboard
        // ini berperilaku sama (bandingkan chartPenolakanKpr & chartSummaryRetensi), sehingga
        // sumbu waktunya konsisten dan komponen menampilkan keadaan kosong dari `series`.
        $kosong = ['labels' => $this->monthLabels($n), 'series' => []];

        // Gerbang skema deret 1: tanpa advance_date tidak ada bucket, tanpa amount tidak ada nilai.
        if (! Schema::hasTable('bs_cash_advances')
            || ! Schema::hasColumns('bs_cash_advances', ['id', 'advance_date', 'amount'])) {
            return $kosong;
        }

        $months = $this->months($n);
        $start  = $months[0]->copy()->startOfMonth()->toDateString();
        $end    = $months[$n - 1]->copy()->endOfMonth()->toDateString();

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
                $adaRincian && $adaHeader => 'SUM(COALESCE(ln.realisasi, a.realized_amount, 0))',
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
            'labels' => $this->monthLabels($n),
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
     *   - tidak ada batas atas invoice_date <= hari ini, karena dashboard menampilkan posisi
     *     hutang saat ini, bukan potret per tanggal pilihan pengguna.
     *
     * @return array{rows: array<int, array{n: string, v: float}>}
     */
    private function chartAgingHutang(): array
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

            $today = now()->toDateString();

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
    private function chartSummaryNeraca(): array
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

        return [
            'rows' => [
                ['n' => 'AKTIVA', 'a' => round($saldoDebet('aktiva_lancar'), 2), 'b' => round($saldoDebet('aktiva_tetap'), 2)],
                ['n' => 'PASIVA', 'a' => round($saldoKredit('kewajiban'), 2),    'b' => round($saldoKredit('ekuitas') + $labaBerjalan, 2)],
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
    private function chartSummaryLabaRugi(): array
    {
        $months = $this->months(8);
        $labels = array_map(fn (Carbon $m): string => self::MONTHS_ID[$m->month], $months);
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
    private function chartAsetTetap(): array
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
    private function chartRealisasiBudget(): array
    {
        // Semua kolom yang dirujuk query plafon dijaga di sini: lingkungan yang migrasinya belum
        // lengkap harus dapat struktur kosong, bukan QueryException.
        if (! Schema::hasTable('bs_budgets') || ! Schema::hasTable('bs_cost_centers')
            || ! Schema::hasColumn('bs_budgets', 'cost_center_id') || ! Schema::hasColumn('bs_budgets', 'year')
            || ! Schema::hasColumn('bs_budgets', 'amount') || ! Schema::hasColumn('bs_cost_centers', 'name')) {
            return ['rows' => []];
        }

        $year = (int) now()->year;

        // 1) Plafon per cost center. havingRaw membuang cost center berplafon 0 di sisi DB
        //    supaya layar tak pernah membagi dengan nol.
        $plafon = DB::table('bs_budgets as bg')
            ->join('bs_cost_centers as cc', 'cc.id', '=', 'bg.cost_center_id')
            ->select('bg.cost_center_id as cc_id', 'cc.name as cc_name')
            ->selectRaw('COALESCE(SUM(bg.amount), 0) as plafon')
            ->where('bg.year', $year)
            ->whereNotNull('bg.cost_center_id')
            ->groupBy('bg.cost_center_id', 'cc.name')
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

        $real = $this->realisasiPerCostCenter($year, $baris->pluck('cc_id')->all());

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
    private function realisasiPerCostCenter(int $year, array $ccIds): array
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
        $anggaran = DB::table('bs_budgets')
            ->select('cost_center_id', 'account_id')
            ->where('year', $year)
            ->whereIn('cost_center_id', $ccIds)
            ->groupBy('cost_center_id', 'account_id');

        if (Schema::hasColumn('bs_budgets', 'status')) {
            $anggaran->where('status', 'approved');
        }

        $q = DB::table('bs_journal_lines as jl')
            ->join('bs_journals as j', 'j.id', '=', 'jl.journal_id')
            ->join('bs_accounts as a', 'a.id', '=', 'jl.account_id')
            ->joinSub($anggaran, 'b', function ($join) use ($ccExpr) {
                $join->on('b.account_id', '=', 'jl.account_id')
                    ->whereRaw($ccExpr.' = b.cost_center_id');
            })
            ->where('j.period_year', $year)
            ->groupBy('b.cost_center_id')
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
    private function chartSpkPerCluster(): array
    {
        $t = 'bs_work_orders';
        if (! Schema::hasTable($t) || ! Schema::hasColumn($t, 'contract_value')) {
            return ['rows' => []];
        }

        $adaAddendum = Schema::hasColumn($t, 'addendum_value');
        $nilaiSpk = 'COALESCE(w.contract_value,0)'.($adaAddendum ? ' + COALESCE(w.addendum_value,0)' : '');

        $q = DB::table($t.' as w')->selectRaw(
            "COALESCE(d.cluster_name, 'Tanpa Cluster') AS n, COUNT(*) AS jml, SUM({$nilaiSpk}) AS nilai"
        );

        $bisaCluster = Schema::hasTable('bs_work_order_units')
            && Schema::hasColumn('bs_work_order_units', 'unit_id')
            && Schema::hasTable('units') && Schema::hasColumn('units', 'cluster_id')
            && Schema::hasTable('clusters') && Schema::hasColumn('clusters', 'name');

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
        } else {
            $q->selectRaw('NULL AS dummy_cluster');   // tidak dipakai; ekspresi n jatuh ke 'Tanpa Cluster'
        }

        if (Schema::hasColumn($t, 'deleted_at')) {
            $q->whereNull('w.deleted_at');
        }
        // SPK batal tidak lagi menjadi pekerjaan berjalan, jadi tidak ikut dihitung.
        if (Schema::hasColumn($t, 'status')) {
            $q->where('w.status', '!=', 'cancelled');
        }

        $rows = [];
        foreach ($q->groupByRaw("COALESCE(d.cluster_name, 'Tanpa Cluster')")->orderByRaw('nilai DESC')->limit(6)->get() as $r) {
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
    private function chartProgressLapangan(): array
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

        // Tingkat 2 — tahap sebuah unit = akumulasi TERTINGGI di antara SPK yang menggarapnya.
        $perUnit = $perPasangan
            ? DB::query()->fromSub($perPasangan, 'x')->selectRaw('x.unit_id, MAX(x.pct) AS pct')->groupBy('x.unit_id')
            : null;

        // Populasi unit: gabungan unit yang dialokasikan dan unit yang punya catatan progres.
        $populasi = null;
        if ($adaAlokasi) {
            $populasi = DB::table('bs_work_order_units')->select('unit_id')->distinct();
        }
        if ($adaProgres) {
            $dariProgres = DB::table('bs_work_order_progress_units')->select('unit_id')->distinct();
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
     * Grafik 13 — Summary Retensi: nilai ditahan vs dilepas per bulan (8 bulan terakhir).
     *
     * "Ditahan" memakai tanggal pencatatan retensinya, "dilepas" memakai released_at dan
     * baru jatuh ke created_at bila tanggal lepasnya belum terisi. Pelepasan yang DITOLAK
     * saat approval tidak dihitung sebagai dilepas — uangnya memang tidak keluar.
     *
     * @return array{labels: array<int, string>, series: array<int, array{n: string, c: int, v: array<int, float>}>}
     */
    private function chartSummaryRetensi(): array
    {
        $labels = $this->monthLabels(8);
        $bulan  = $this->months(8);

        $ditahan = $this->bulananJumlah('kpr_retentions', 'created_at', 'amount', $bulan);
        $dilepas = $this->bulananJumlah('kpr_retention_releases', 'released_at', 'amount', $bulan, 'approval_status');

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
    private function chartBastAjbBooked(): array
    {
        $months = $this->months(8);

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
            'labels' => array_map(fn (Carbon $m) => self::MONTHS_ID[$m->month], $months),
            // Nama kunci n/c/unit/nilai mengikuti antarmuka SeriGabungan di
            // resources/js/Pages/Dashboard/Index.vue — jangan diganti namanya.
            // Urutan c 0/1/2 dikunci: layar memilih warna seri berdasarkan angka ini,
            // bukan berdasarkan nama seri — jangan ditukar urutannya.
            'series' => [
                ['n' => 'BAST',        'c' => 0, 'unit' => $bast['unit'],   'nilai' => $bast['nilai']],
                ['n' => 'AJB',         'c' => 1, 'unit' => $ajb['unit'],    'nilai' => $ajb['nilai']],
                ['n' => 'Booked Rev.', 'c' => 2, 'unit' => $booked['unit'], 'nilai' => $booked['nilai']],
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
        $kosong = ['unit' => array_fill(0, count($months), 0), 'nilai' => 0.0];

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

        // (b) Kartu nilai — rentang tersendiri: 1 Januari s/d 31 Desember tahun ini.
        $nilai = 0.0;
        foreach ($this->serahTerimaAgregat($table, $dateCol, $amountCol, now()->startOfYear(), now()->endOfYear(), false) as $r) {
            $nilai += (float) $r->nilai;
        }

        return ['unit' => $unit, 'nilai' => $nilai];
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
