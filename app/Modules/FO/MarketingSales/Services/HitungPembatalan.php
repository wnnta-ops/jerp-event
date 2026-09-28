<?php

namespace App\Modules\FO\MarketingSales\Services;

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
 */
class HitungPembatalan
{
    /** Alasan yang dasarnya UTJ: seluruh UTJ yang sudah dibayar dikembalikan utuh. */
    public const ALASAN_UTJ = ['REJECT_BANK', 'KOL_2', 'KOL_3', 'KOL_4', 'KOL_5', 'TUTUP_BUKU'];

    /** Alasan yang dasarnya DP dikurangi administrasi, dan UTJ-nya hangus. */
    public const ALASAN_SEPIHAK = 'BATAL_SEPIHAK';

    public function __construct(private readonly FoSettings $setelan) {}

    /**
     * Yang SUDAH dibayar customer, dipisah menurut tipe tagihan.
     *
     * @return array{utj: float, dp: float, adm: float, total: float}
     */
    public function terbayar(Kontrak $kontrak): array
    {
        $per = KontrakTagihan::where('kontrak_id', $kontrak->id)
            ->selectRaw('tipe, SUM(pelunasan) AS dibayar')
            ->groupBy('tipe')->pluck('dibayar', 'tipe');

        $ambil = fn (string $t) => (float) ($per[$t] ?? 0);

        return [
            'utj' => $ambil('BF'),
            'dp' => $ambil('DP'),
            'adm' => $ambil('ADM'),
            'total' => (float) $per->sum(),
        ];
    }

    /**
     * Berapa yang kembali ke customer untuk sebuah alasan.
     *
     * `refund` bernilai null HANYA untuk alasan "Lainnya" — penanda bahwa sistem memang tidak
     * punya pendapat dan angkanya harus diketik operator. Nol dan null dibedakan dengan
     * sengaja: nol berarti "sudah dihitung, hasilnya memang nihil".
     *
     * @return array{utj: float, dp: float, admin_persen: float, potongan: float, refund: ?float, dasar: string}
     */
    public function pengembalian(Kontrak $kontrak, ?string $alasan, ?float $adminPersen = null): array
    {
        $bayar = $this->terbayar($kontrak);
        $hasil = [
            'utj' => $bayar['utj'],
            'dp' => $bayar['dp'],
            'admin_persen' => 0.0,
            'potongan' => 0.0,
            'refund' => null,
            'dasar' => 'MANUAL',
        ];

        if (in_array((string) $alasan, self::ALASAN_UTJ, true)) {
            return ['refund' => $bayar['utj'], 'dasar' => 'UTJ'] + $hasil;
        }

        if ((string) $alasan === self::ALASAN_SEPIHAK) {
            $persen = max(0.0, min(100.0, (float) $adminPersen));
            // Potongannya DIBULATKAN KE ATAS supaya pengembaliannya jatuh pada rupiah bulat
            // dan tidak pernah melebihi DP yang masuk. Searah dengan pembulatan PPh di
            // BltCalculatorService.
            $potongan = (float) ceil($bayar['dp'] * $persen / 100);

            return [
                'admin_persen' => $persen,
                'potongan' => $potongan,
                'refund' => max(0.0, $bayar['dp'] - $potongan),
                'dasar' => 'DP',
            ] + $hasil;
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
