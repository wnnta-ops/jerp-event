<?php

namespace App\Modules\FO\MarketingSales\Services;

use App\Modules\BO\IproBridge\Services\RekomBatalCashService;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\KontrakTagihan;
use App\Settings\FoSettings;

/**
 * Rumus pembatalan kontrak — SATU tempat untuk seluruh angkanya.
 *
 * Dipakai layar Pembatalan Kontrak (menampilkan & mengisi Refund), ApprovalExecutionService
 * (membuat Refund dan BLT saat disetujui), dan apa pun yang menyusul. Ditaruh terpusat karena
 * angka-angka ini muncul di tiga tempat sekaligus — layar, approval, dan kwitansi — dan
 * ketidaksepakatannya tidak menghasilkan galat, melainkan dua dokumen yang menyebut jumlah
 * uang berbeda untuk pembatalan yang sama.
 *
 * ── DUA KELUARGA ALASAN, DUA DASAR PERHITUNGAN ──
 *
 *   KOL 2-5, Reject Bank, Tutup Buku → dikembalikan UTJ-nya saja, utuh.
 *   Batal Sepihak                    → UTJ HANGUS; yang kembali DP dikurangi administrasi
 *                                      sekian persen, persennya diketik saat pengajuan.
 *   Lainnya                          → diisi manual, sistem tidak menghitung apa pun.
 *
 * Persen administrasinya sengaja DIKETIK, bukan diturunkan dari keadaan kontrak. Praktiknya
 * 10% bila pembeli mundur sesudah membayar DP dan 30% bila batal menjelang akad, tetapi
 * batasnya soal kesepakatan — bukan sesuatu yang bisa dibaca sistem dari data. Menebaknya
 * berarti mengubah jumlah uang yang kembali ke pembeli tanpa ada yang memutuskan.
 *
 * ── UTJ ITU TAGIHAN BERTIPE 'BF' ──
 *
 * Di master skema, barisnya bernama "UTJ (Booking Fee)" dan bertipe BF (lihat
 * SkemaCalculatorService). Yang dijumlahkan adalah kolom `pelunasan` — uang yang benar-benar
 * masuk, bukan yang seharusnya ditagih.
 *
 * ── "DP" BERARTI DP *DAN* TAMBAH DP ──
 *
 * Tambah DP tersimpan sebagai tipe tersendiri, TDP, bukan sebagai baris DP kedua. Keduanya
 * uang muka yang sama-sama sudah disetor pembeli, jadi keduanya masuk dasar potongan; di
 * repo ini PembayaranService pun sudah memetakan TDP ke jenis pembayaran DP.
 *
 * Menghitung DP saja pernah membuat kontrak yang seluruh uang mukanya masuk sebagai TDP
 * terbaca "DP yang sudah dibayar Rp 0" — pengembaliannya nol padahal pembeli sudah menyetor
 * ratusan juta, dan tidak ada galat apa pun yang menandainya.
 *
 * ── ANGSURAN IKUT, TAPI HANYA PADA CASH & CASH BERTAHAP ──
 *
 * Pada kontrak cash, cicilannya tercatat sebagai ANG — bukan DP. Kontrak CASH 3X yang sudah
 * menyetor Rp 187 juta karena itu terbaca berdasar nol sebelum ini, dan pengembaliannya nol.
 *
 * Pada kontrak KPR, ANG berarti hal yang sama sekali berbeda: baris ber-flag_kpr adalah
 * PENCAIRAN DARI BANK (lihat KontrakGeneratorService), bukan uang pembeli. Memasukkannya ke
 * dasar pengembalian berarti menyerahkan uang bank kepada pembeli — kesalahan yang tidak akan
 * ketahuan dari layar mana pun sampai banknya menagih.
 *
 * Penentu "cash"-nya memakai RekomBatalCashService::adalahCash(), sumber yang sama dengan
 * layar Rekom Batal Cash. Definisi kedua di sini akan lambat laun berbeda dari layar itu,
 * dan bedanya berupa uang.
 */
class HitungPembatalan
{
    /** Alasan yang dasarnya UTJ: seluruh UTJ yang sudah dibayar dikembalikan utuh. */
    public const ALASAN_UTJ = ['REJECT_BANK', 'KOL_2', 'KOL_3', 'KOL_4', 'KOL_5', 'TUTUP_BUKU'];

    /** Alasan yang dasarnya DP dikurangi administrasi, dan UTJ-nya hangus. */
    public const ALASAN_SEPIHAK = 'BATAL_SEPIHAK';

    /** Tipe tagihan yang dihitung sebagai uang muka: DP dan Tambah DP. */
    public const TIPE_DP = ['DP', 'TDP'];

    public function __construct(private readonly FoSettings $setelan) {}

    /**
     * Yang SUDAH dibayar customer, dipisah menurut tipe tagihan.
     *
     * `dp` menjumlahkan DP DAN TDP (Tambah DP); `ang` berdiri sendiri karena hanya ikut dasar
     * pengembalian pada kontrak cash — lihat catatan di docblock kelas.
     *
     * @return array{utj: float, dp: float, ang: float, adm: float, total: float}
     */
    public function terbayar(Kontrak $kontrak): array
    {
        $per = KontrakTagihan::where('kontrak_id', $kontrak->id)
            ->selectRaw('tipe, SUM(pelunasan) AS dibayar')
            ->groupBy('tipe')->pluck('dibayar', 'tipe');

        $ambil = fn (string ...$tipe) => (float) collect($tipe)->sum(fn ($t) => (float) ($per[$t] ?? 0));

        return [
            'utj' => $ambil('BF'),
            'dp' => $ambil(...self::TIPE_DP),
            'ang' => $ambil('ANG'),
            'adm' => $ambil('ADM'),
            'total' => (float) $per->sum(),
        ];
    }

    /**
     * Angsuran kontrak ini ikut dasar pengembalian atau tidak.
     *
     * Hanya pada CASH & CASH BERTAHAP, di mana ANG memang cicilan pembeli. Pada KPR, ANG
     * ber-flag_kpr adalah pencairan bank — bukan uangnya, dan bukan haknya saat batal.
     */
    public function angsuranIkut(Kontrak $kontrak): bool
    {
        return RekomBatalCashService::adalahCash($kontrak->cara_bayar);
    }

    /**
     * Berapa yang kembali ke customer untuk sebuah alasan.
     *
     * `refund` bernilai null HANYA untuk alasan "Lainnya" — penanda bahwa sistem memang tidak
     * punya pendapat dan angkanya harus diketik operator. Nol dan null dibedakan dengan
     * sengaja: nol berarti "sudah dihitung, hasilnya memang nihil".
     *
     * @return array{utj: float, dp: float, ang: float, ang_ikut: bool, basis: float, admin_persen: float, potongan: float, refund: ?float, dasar: string}
     */
    public function pengembalian(Kontrak $kontrak, ?string $alasan, ?float $adminPersen = null): array
    {
        $bayar = $this->terbayar($kontrak);
        $angIkut = $this->angsuranIkut($kontrak);

        $hasil = [
            'utj' => $bayar['utj'],
            'dp' => $bayar['dp'],
            'ang' => $bayar['ang'],
            'ang_ikut' => $angIkut,
            'basis' => 0.0,
            'admin_persen' => 0.0,
            'potongan' => 0.0,
            'refund' => null,
            'dasar' => 'MANUAL',
        ];

        if (in_array((string) $alasan, self::ALASAN_UTJ, true)) {
            return array_replace($hasil, [
                'basis' => $bayar['utj'],
                'refund' => $bayar['utj'],
                'dasar' => 'UTJ',
            ]);
        }

        if ((string) $alasan === self::ALASAN_SEPIHAK) {
            // Angsuran hanya ikut pada cash. Pada KPR ia uang bank, bukan uang pembeli.
            $basis = $bayar['dp'] + ($angIkut ? $bayar['ang'] : 0.0);
            $persen = max(0.0, min(100.0, (float) $adminPersen));

            // Potongannya DIBULATKAN KE ATAS supaya pengembaliannya jatuh pada rupiah bulat
            // dan tidak pernah melebihi setoran yang masuk. Searah dengan pembulatan PPh di
            // BltCalculatorService.
            $potongan = (float) ceil($basis * $persen / 100);

            return array_replace($hasil, [
                'basis' => $basis,
                'admin_persen' => $persen,
                'potongan' => $potongan,
                'refund' => max(0.0, $basis - $potongan),
                'dasar' => 'DP',
            ]);
        }

        return $hasil;
    }

    /**
     * BLT Batal Sepihak untuk sales — nilainya tetap, diatur di Pengaturan FO.
     *
     * Hanya berlaku untuk Batal Sepihak: pembatalan karena bank menolak atau kolektibilitas
     * bukan kegagalan sales, jadi tidak ada BLT-nya.
     *
     * @return array{nilai: float, pph_persen: float, pph: float, nett: float}
     */
    public function bltBatalSepihak(): array
    {
        $nilai = (float) $this->setelan->blt_batal_sepihak_nilai;
        $persen = (float) $this->setelan->blt_batal_sepihak_pph_persen;
        // Dibulatkan KE ATAS mengikuti aturan PPh di BltCalculatorService — supaya BLT yang
        // lahir dari pembatalan tidak berbeda perlakuan dengan BLT yang lahir dari skema.
        $pph = (float) ceil($nilai * $persen / 100);

        return [
            'nilai' => $nilai,
            'pph_persen' => $persen,
            'pph' => $pph,
            'nett' => max(0.0, $nilai - $pph),
        ];
    }
}
