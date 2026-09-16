<?php

namespace App\Console\Commands\Fo;

use App\Modules\FO\MarketingSales\Models\Unit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pecah No. Unit warisan migrasi menjadi Blok + Nomer.
 *
 * No. Unit berformat {Lokasi}-{Tahap}-{Blok}-{Nomer} dan memang disusun ulang dari keempat
 * bagian itu oleh Unit::composeKodeUnit(). Tetapi migrasi lama menaruh KODE LENGKAP ke kolom
 * no_unit dan tidak pernah mengisi blok — 1358 dari 1358 unit kosong bloknya.
 *
 * Akibatnya bukan sekadar kolom kosong:
 *
 *  · Peta stok unit (BuildsStockGrid) membangun matriks baris=blok × kolom=nomer. Dengan
 *    blok kosong hasilnya SATU baris kosong dengan sebanyak-unitnya kolom — JRK3 tahap 004
 *    melebar jadi 1×222, padahal denah aslinya 13 blok × 32 nomer.
 *  · Peta Unit Live, Pilih Unit, dan Editor Koordinat mengurutkan dengan orderBy('blok').
 *  · blok ikut dikirim ke BO lewat IproMasterSync.
 *  · Isian "Nomer" di form Unit menampilkan kode penuh, sehingga salah blok tidak bisa
 *    dibetulkan sendiri — mengetik nomer di situ justru merusak kodenya.
 *
 * Import Unit sudah lama meminta kolom terpisah (Lokasi, Tahap, Blok, Nomor), jadi
 * pemecahan ini merapikan warisan lama — bukan menambal jalur yang masih memproduksi
 * kekacauan yang sama.
 *
 * TIDAK menulis kode_unit sama sekali. Yang menulisnya tetap hook saving() pada model, dan
 * tiap baris DIPERIKSA DULU: bila hasil susun ulang dari pecahan tidak sama persis dengan
 * kode_unit yang sekarang, baris itu dilewati. Lebih baik melewatkan daripada menggeser
 * nomor unit yang sudah tercetak di SPR dan kontrak.
 *
 * Aman dijalankan berulang: unit yang bloknya sudah terisi dilewati.
 */
class PecahKodeUnitCommand extends Command
{
    protected $signature = 'unit:pecah-kode
        {--jalankan : Benar-benar menulis ke database. Tanpa ini hanya menampilkan hitungannya.}';

    protected $description = 'Pecah No. Unit warisan migrasi jadi kolom Blok + Nomer (kode_unit tidak berubah)';

    public function handle(): int
    {
        $tulis = (bool) $this->option('jalankan');

        $akanDipecah = [];   // [id => [blok, nomer]]
        $sudah = 0;          // blok sudah terisi
        $bukan4 = [];        // no_unit tidak berformat 4 segmen
        $tidakCocok = [];    // susun ulang ≠ kode_unit sekarang

        Unit::query()->withoutGlobalScopes()
            ->orderBy('id')
            ->chunkById(500, function ($units) use (&$akanDipecah, &$sudah, &$bukan4, &$tidakCocok) {
                foreach ($units as $u) {
                    if (trim((string) $u->blok) !== '') {
                        $sudah++;

                        continue;
                    }

                    $bagian = explode('-', trim((string) $u->no_unit));
                    if (count($bagian) !== 4) {
                        $bukan4[] = $u->kode_unit ?: $u->no_unit;

                        continue;
                    }

                    [, , $blok, $nomer] = $bagian;
                    $asal = (string) $u->kode_unit;

                    // Dicoba di memori dulu memakai penyusun milik model sendiri —
                    // bukan menyalin aturannya ke sini, supaya tidak bisa berbeda.
                    $u->blok = $blok;
                    $u->no_unit = $nomer;
                    $hasil = Unit::composeKodeUnit($u);

                    if ($hasil !== $asal) {
                        $tidakCocok[] = $asal.' → '.($hasil ?? 'null');

                        continue;
                    }

                    $akanDipecah[$u->id] = [$blok, $nomer];
                }
            });

        $this->newLine();
        $this->table(
            ['Keadaan', 'Unit'],
            [
                ['Akan dipecah', count($akanDipecah)],
                ['Blok sudah terisi (dilewati)', $sudah],
                ['No. Unit bukan 4 segmen (dilewati)', count($bukan4)],
                ['Susun ulang tidak cocok (dilewati)', count($tidakCocok)],
            ]
        );

        foreach ([['bukan 4 segmen', $bukan4], ['tidak cocok', $tidakCocok]] as [$judul, $daftar]) {
            if ($daftar !== []) {
                $this->newLine();
                $this->warn('  Dilewati — '.$judul.':');
                foreach (array_slice($daftar, 0, 20) as $x) {
                    $this->line('    '.$x);
                }
                if (count($daftar) > 20) {
                    $this->line('    … dan '.(count($daftar) - 20).' lagi');
                }
            }
        }

        if ($akanDipecah === []) {
            $this->newLine();
            $this->info('  Tidak ada yang perlu dipecah.');

            return self::SUCCESS;
        }

        if (! $tulis) {
            $this->newLine();
            $this->warn('  HITUNGAN saja — tidak ada yang ditulis. Tambahkan --jalankan untuk menerapkan.');

            return self::SUCCESS;
        }

        $berubah = 0;
        $gagal = [];

        DB::transaction(function () use ($akanDipecah, &$berubah, &$gagal) {
            foreach (array_chunk($akanDipecah, 200, true) as $potongan) {
                foreach (Unit::query()->withoutGlobalScopes()->findMany(array_keys($potongan)) as $u) {
                    [$blok, $nomer] = $potongan[$u->id];
                    $asal = (string) $u->kode_unit;

                    $u->blok = $blok;
                    $u->no_unit = $nomer;
                    $u->save();   // saving() menyusun ulang kode_unit

                    // Penjaga terakhir: kalau kode bergeser walau sudah dicoba di memori,
                    // seluruh transaksi dibatalkan. Nomor unit ada di SPR dan kontrak.
                    if ((string) $u->refresh()->kode_unit !== $asal) {
                        $gagal[] = $asal.' → '.$u->kode_unit;
                        throw new \RuntimeException(
                            'No. Unit bergeser pada '.$asal.' → '.$u->kode_unit.'. Seluruh perubahan dibatalkan.'
                        );
                    }

                    $berubah++;
                }
            }
        });

        $this->newLine();
        $this->info("  {$berubah} unit dipecah. No. Unit tidak ada yang berubah.");

        return self::SUCCESS;
    }
}
