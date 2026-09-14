<?php

namespace App\Modules\FO\MarketingSales\Services;

use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\SprTarget;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Angka SPR per bulan — satu tempat untuk ketiga tampilan grafik Sales Report.
 *
 * TIGA KEPUTUSAN YANG MENENTUKAN ANGKANYA, ditulis di sini supaya tidak tersebar:
 *
 * 1. SPR = kontrak ber-`tahap` 'SPR'. Enum tahap memuat 'SPRS' (Surat Pemesanan Rumah
 *    Sementara, tahap awal) dan 'SPR' (final). Yang dihitung hanya yang SUDAH SPR, sejalan
 *    dengan judul laporannya dan dengan hitungan "kontrak SPR" di dashboard.
 *
 * 2. GROSS memakai bulan `tgl_kontrak`; BATAL memakai bulan PEMBATALANNYA yang sebenarnya.
 *    Keduanya karena itu tidak berasal dari kelompok kontrak yang sama: kontrak yang dipesan
 *    Januari lalu dibatalkan Maret menambah Gross di Januari dan Batal di Maret. Akibat yang
 *    disengaja dan harus dikatakan di layar: Nett sebuah bulan BISA NEGATIF bila pembatalan
 *    yang masuk bulan itu lebih banyak daripada SPR barunya, dan jumlah setahun tidak selalu
 *    Gross − Batal karena pembatalan atas kontrak tahun lalu ikut masuk tahun ini.
 *
 * 3. BATAL DIGERAKKAN DARI STATUS KONTRAKNYA, bukan dari baris approval. Tiga sebab, ketiganya
 *    nyata di kode ini:
 *      · Kontrak bisa berstatus BATAL tanpa baris approval sama sekali — dibuat langsung BATAL
 *        lewat form Kontrak, atau berasal dari migrasi data lama. Menghitung dari sisi approval
 *        membuat kontrak-kontrak itu lenyap dari kolom Batal dan Nett jadi terlalu besar.
 *      · Satu kontrak bisa punya BEBERAPA approval BATAL_KONTRAK (ditolak lalu diajukan ulang;
 *        halaman Pembatalan bahkan menampilkan cacahnya). Menjumlahkan baris approval membuat
 *        satu pembatalan terhitung berkali-kali.
 *      · Approval bisa berstatus EXECUTED tanpa applyBatal() pernah berjalan, lewat koreksi
 *        manual super admin — kontraknya masih AKTIF sementara approval-nya terlihat selesai.
 *    Approval hanya dipakai untuk MENCARI TANGGALNYA, tidak untuk menentukan apakah batal.
 */
final class SprBulananService
{
    /** Tahap kontrak yang dihitung sebagai SPR. */
    public const TAHAP = 'SPR';

    /** Status kontrak yang dihitung sebagai pembatalan. */
    public const STATUS_BATAL = 'BATAL';

    public const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];

    /**
     * Gross / Batal / Nett per bulan (indeks 1..12).
     *
     * Pengelompokan per bulan dikerjakan di PHP, bukan lewat MONTH() di SQL: pengujian berjalan
     * di sqlite sedangkan produksi MySQL, dan MONTH() tidak ada di sqlite. Jumlah barisnya
     * ratusan per tahun, jadi harganya tidak terasa dan angkanya jadi sama di kedua basis data.
     *
     * @return array{gross: array<int,int>, batal: array<int,int>, nett: array<int,int>}
     */
    public function perBulan(int $tahun): array
    {
        $gross = array_fill(1, 12, 0);
        $batal = array_fill(1, 12, 0);

        foreach (Kontrak::query()
            ->where('tahap', self::TAHAP)
            ->whereNotNull('tgl_kontrak')
            ->whereBetween('tgl_kontrak', [$tahun.'-01-01', $tahun.'-12-31'])
            ->get(['tgl_kontrak']) as $r) {
            $gross[(int) Carbon::parse($r->tgl_kontrak)->month]++;
        }

        // Yang dibatalkan TAHUN INI — apa pun tahun kontraknya. Batasnya akhir tahun itu:
        // sebuah kontrak tidak mungkin dibatalkan sebelum ia dipesan.
        $dibatalkan = Kontrak::query()
            ->where('tahap', self::TAHAP)
            ->where('status', self::STATUS_BATAL)
            ->whereNotNull('tgl_kontrak')
            ->where('tgl_kontrak', '<=', $tahun.'-12-31')
            ->get(['id', 'tgl_kontrak']);

        $peta = $this->tanggalBatal($dibatalkan->pluck('id')->all());
        foreach ($dibatalkan as $k) {
            // Jatuh ke tgl_kontrak bila tanggal batalnya tidak ketemu di mana pun. Membuang
            // kontraknya justru menghilangkan pembatalan yang benar-benar terjadi.
            $c = $this->tanggal($peta[(int) $k->id] ?? null) ?? Carbon::parse($k->tgl_kontrak);
            if ((int) $c->year === $tahun) {
                $batal[(int) $c->month]++;
            }
        }

        $nett = [];
        for ($m = 1; $m <= 12; $m++) {
            $nett[$m] = $gross[$m] - $batal[$m];
        }

        return ['gross' => $gross, 'batal' => $batal, 'nett' => $nett];
    }

    /**
     * Tanggal pembatalan per kontrak, dicari dari approval BATAL_KONTRAK.
     *
     * Hanya yang berstatus EXECUTED: `tgl_pembatalan` sudah terisi sejak baris approval DIBUAT,
     * sebelum disetujui, jadi baris DRAFT/PENDING/REJECTED pun memilikinya — memakainya berarti
     * mencatat pembatalan yang tidak pernah terjadi.
     *
     * Bila satu kontrak punya beberapa baris EXECUTED, yang dipakai yang TERBARU — satu baris,
     * bukan dijumlahkan.
     *
     * Urutan jatuh-bangun tanggalnya: detail_baru['tgl_pembatalan'] → executed_at → approved_at
     * → created_at. `tgl_approval` SENGAJA TIDAK dipakai meski namanya paling menjanjikan: itu
     * tanggal PENGAJUAN (diisi now() saat baris dibuat), bukan tanggal pembatalan disetujui.
     *
     * @param  array<int,int>  $ids
     * @return array<int,string>
     */
    private function tanggalBatal(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('fo_approvals')
            ->where('jenis', 'BATAL_KONTRAK')
            ->where('status', 'EXECUTED')
            ->where('subject_type', Kontrak::class)
            ->whereIn('subject_id', $ids)
            ->when(app(TenantContext::class)->id(), fn ($q, $t) => $q->where('tenant_id', $t))
            // Baris ter-soft-delete IKUT dibaca: approval yang dihapus tetap merekam KAPAN
            // pembatalannya terjadi, dan kontraknya tetap berstatus BATAL. Membuangnya hanya
            // membuat tanggalnya hilang lalu jatuh ke tgl_kontrak yang lebih jauh dari benar.
            ->orderBy('subject_id')
            ->orderByRaw('COALESCE(executed_at, approved_at, created_at)')
            ->orderBy('id')
            ->get(['subject_id', 'detail_baru', 'executed_at', 'approved_at', 'created_at']);

        $out = [];
        foreach ($rows as $r) {
            // Diurut menaik, jadi baris terakhir per kontrak yang menang.
            $detail = json_decode((string) ($r->detail_baru ?? ''), true);

            /*
             * Tiap calon DIBACA DULU, baru diterima. Rantai cadangan yang memilih lebih dulu
             * lalu membaca belakangan akan tersangkut pada tgl_pembatalan yang berisi sampah
             * ('13/09/2026', untai kosong, salah eja) — nilainya "ada", jadi executed_at yang
             * sah tidak pernah dicoba, dan tanggalnya jatuh sampai ke tanggal kontrak.
             */
            foreach ([
                is_array($detail) ? ($detail['tgl_pembatalan'] ?? null) : null,
                $r->executed_at,
                $r->approved_at,
                $r->created_at,
            ] as $calon) {
                if (($t = $this->tanggal(is_string($calon) ? $calon : null)) !== null) {
                    $out[(int) $r->subject_id] = $t->toDateString();
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Baca tanggal dari nilai yang bentuknya tidak dijamin.
     *
     * detail_baru adalah JSON bebas: lewat form KeyValue generik nilainya bisa '13/09/2026',
     * untai kosong, atau apa saja. Carbon::parse atas nilai semacam itu melempar galat — atau
     * lebih buruk, memulangkan tanggal yang salah tanpa keluhan. Karena itu dibaca ketat, dan
     * yang tidak terbaca dipulangkan null supaya pemanggilnya jatuh ke cadangan.
     */
    private function tanggal(?string $v): ?Carbon
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', substr($v, 0, 10))->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Target per skenario untuk satu tahun, urut sesuai kolom `urutan` lalu namanya.
     *
     * @return array<int,array{skenario: string, bulan: array<int,int>, setahun: int}>
     */
    public function target(int $tahun): array
    {
        return SprTarget::query()
            ->where('tahun', $tahun)
            ->orderBy('urutan')->orderBy('skenario')
            ->get()
            ->map(fn (SprTarget $t) => [
                'skenario' => (string) $t->skenario,
                'bulan' => $t->perBulan(),
                'setahun' => $t->setahun(),
            ])->all();
    }

    /**
     * Bulan terakhir yang sudah berjalan untuk tahun itu — bulan sesudahnya dikosongkan
     * (null), BUKAN digambar nol. Nol berarti "tidak ada SPR bulan itu"; belum berjalan
     * berarti "belum ada kabarnya". Batang nol di bulan depan membuat tren terbaca anjlok.
     */
    public function bulanBerjalan(int $tahun, ?Carbon $kini = null): int
    {
        $kini ??= Carbon::now();

        if ($tahun < (int) $kini->year) {
            return 12;
        }
        if ($tahun > (int) $kini->year) {
            return 0;
        }

        return (int) $kini->month;
    }

    /**
     * Potong deret pada bulan berjalan: bulan yang belum terjadi jadi null.
     *
     * @param  array<int,int>  $bulan
     * @return array<int,int|null>
     */
    public function hinggaBulan(array $bulan, int $sampai): array
    {
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $out[$m] = $m <= $sampai ? ($bulan[$m] ?? 0) : null;
        }

        return $out;
    }
}
