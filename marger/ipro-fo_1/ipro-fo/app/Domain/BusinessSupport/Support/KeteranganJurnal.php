<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Support;

/**
 * KETERANGAN MUTASI — satu aturan untuk Rekening Ledger (layar, PDF, Excel) dan Buku Besar GL.
 *
 * Keterangan BARIS jurnal sering hanya menyebut nomor dokumen ("Transfer bon sementara
 * BON/2610/0079"); keterangan KEPALA jurnal-lah yang menerangkan perkaranya ("Pembayaran Bon
 * Sementara — PENGAJUAN KASBON …"). Dulu tiap layar memilih sendiri — sebagian hanya baris, sebagian
 * baris + kepala — sehingga layar dan berkas unduhannya menyebut keterangan berbeda untuk mutasi
 * yang sama.
 *
 *   utama   = keterangan baris; kosong/spasi → keterangan kepala jurnal
 *   tambahan = keterangan kepala jurnal, HANYA bila keduanya terisi dan berbeda
 *              (sama persis → tidak dicetak dua kali)
 */
final class KeteranganJurnal
{
    /** @return array{0:string, 1:?string} [utama, tambahan] */
    public static function pisah(?string $baris, ?string $jurnal): array
    {
        $baris = trim((string) $baris);
        $jurnal = trim((string) $jurnal);

        if ($baris === '') {
            return [$jurnal, null];
        }

        return [$baris, ($jurnal !== '' && $jurnal !== $baris) ? $jurnal : null];
    }
}
