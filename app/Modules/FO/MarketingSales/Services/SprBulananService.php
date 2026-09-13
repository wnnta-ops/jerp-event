<?php

namespace App\Modules\FO\MarketingSales\Services;

use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\SprTarget;
use Illuminate\Support\Carbon;

/**
 * Angka SPR per bulan — satu tempat untuk ketiga tampilan grafik Sales Report.
 *
 * TIGA KEPUTUSAN YANG MENENTUKAN ANGKANYA, ditulis di sini supaya tidak tersebar:
 *
 * 1. SPR = kontrak ber-`tahap` 'SPR'. Enum tahap memuat 'SPRS' (Surat Pemesanan Rumah
 *    Sementara, tahap awal) dan 'SPR' (final). Yang dihitung hanya yang SUDAH SPR, sejalan
 *    dengan judul laporannya dan dengan hitungan "kontrak SPR" di dashboard.
 *
 * 2. BULANNYA diambil dari `tgl_kontrak`, termasuk untuk yang batal. Tanggal pembatalan
 *    sebenarnya ada — tersimpan sebagai JSON di fo_approvals.detail_baru->'$.tgl_pembatalan' —
 *    tetapi memakainya membuat Gross dan Batal jatuh di bulan yang BERBEDA, sehingga
 *    Gross − Batal tidak lagi sama dengan Nett pada bulan mana pun, dan pembatalan atas
 *    kontrak tahun lalu ikut masuk ke tahun ini. Atribusi ke bulan kontrak menjaga ketiga
 *    angkanya saling menjelaskan di setiap kolom.
 *
 * 3. BATAL dihitung dari kolom `status` kontraknya sendiri, bukan dari JOIN ke approval.
 *    Pembatalan lewat migrasi data lama atau perbaikan manual tidak meninggalkan baris
 *    approval; lewat JOIN, kontrak itu hilang dari hitungan Batal dan Nett jadi terlalu besar.
 *    Satu kontrak juga bisa punya beberapa pengajuan batal (ditolak lalu diajukan ulang),
 *    yang lewat JOIN terhitung berkali-kali.
 *
 * Nett = Gross − Batal, jadi ketiganya konsisten menurut konstruksi, bukan menurut harapan.
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

        $rows = Kontrak::query()
            ->where('tahap', self::TAHAP)
            ->whereNotNull('tgl_kontrak')
            ->whereBetween('tgl_kontrak', [$tahun.'-01-01', $tahun.'-12-31'])
            ->get(['tgl_kontrak', 'status']);

        foreach ($rows as $r) {
            $m = (int) Carbon::parse($r->tgl_kontrak)->month;
            $gross[$m]++;
            if ((string) $r->status === self::STATUS_BATAL) {
                $batal[$m]++;
            }
        }

        $nett = [];
        for ($m = 1; $m <= 12; $m++) {
            $nett[$m] = $gross[$m] - $batal[$m];
        }

        return ['gross' => $gross, 'batal' => $batal, 'nett' => $nett];
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
