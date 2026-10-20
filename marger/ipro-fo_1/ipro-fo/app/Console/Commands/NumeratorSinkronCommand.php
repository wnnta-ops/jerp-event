<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\BusinessSupport\Services\BoNumberService;
use Illuminate\Console\Command;

/**
 * Selaraskan penghitung Numerator Back Office dengan dokumen yang SUDAH ADA.
 *
 * Data migrasi & sinkron eProperty masuk langsung ke tabel tanpa menaikkan penghitungnya,
 * sehingga nomor berikutnya bisa menabrak nomor yang sudah terbit (Simpan gagal "Duplicate
 * entry"). Perintah ini menaikkan penghitung ke nomor urut tertinggi tahun ini. Tidak pernah
 * menurunkan. Tanpa --jalankan hanya menampilkan rencananya.
 */
class NumeratorSinkronCommand extends Command
{
    protected $signature = 'bo:numerator-sinkron {--jalankan : terapkan perubahan (tanpa ini hanya laporan)}';

    protected $description = 'Naikkan penghitung Numerator BO yang tertinggal dari dokumen yang sudah ada (tahun ini)';

    public function handle(): int
    {
        $jalankan = (bool) $this->option('jalankan');
        $hasil = BoNumberService::sinkronPenghitung($jalankan);

        if (! $hasil) {
            $this->info('Semua penghitung sudah selaras — tidak ada yang perlu dinaikkan.');

            return self::SUCCESS;
        }

        $this->table(['Kode', 'Dokumen', 'Penghitung', 'Nomor tertinggi', 'Menjadi'],
            array_map(fn ($r) => [$r['code'], $r['label'], $r['sekarang'], $r['tertinggi'], $r['baru']], $hasil));
        $this->line($jalankan ? '<info>Penghitung dinaikkan.</info>' : '<comment>Mode kering — jalankan ulang dengan --jalankan untuk menerapkan.</comment>');

        return self::SUCCESS;
    }
}
