<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Foundation\Region\Models\Region;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Import dataset wilayah Indonesia (Provinsi→Kab/Kota→Kecamatan→Kelurahan).
 *
 * Sumber default: kode wilayah Kemendagri (format kode bertitik).
 * Re-runnable (truncate + reinsert).
 *
 * Run: php artisan regions:import
 *      php artisan regions:import --file=wilayah.sql   (offline, dari file lokal)
 */
class ImportRegionsCommand extends Command
{
    protected $signature   = 'regions:import {--file= : Path file SQL lokal (opsional)} {--url=}';
    protected $description = 'Import dataset wilayah Indonesia (4 level) ke tabel regions.';

    private const DEFAULT_URL = 'https://raw.githubusercontent.com/cahyadsn/wilayah/master/db/wilayah.sql';

    public function handle(): int
    {
        $raw = $this->loadSource();
        if ($raw === null) {
            return self::FAILURE;
        }

        $this->info('Memproses data wilayah...');

        // Cocokkan baris: ('11.01','Kabupaten Aceh Selatan'),
        preg_match_all("/\\('([0-9.]+)','((?:[^'\\\\]|\\\\.)*)'\\)/", $raw, $matches, PREG_SET_ORDER);

        if (count($matches) === 0) {
            $this->error('Tidak ada baris wilayah yang cocok pada sumber data.');
            return self::FAILURE;
        }

        DB::table('regions')->truncate();

        $batch = [];
        $total = 0;
        $bar = $this->output->createProgressBar(count($matches));

        foreach ($matches as $m) {
            $code = $m[1];
            $name = str_replace(["\\'", '\\\\'], ["'", '\\'], $m[2]);
            $level = substr_count($code, '.') + 1;
            $parent = $level === 1 ? null : substr($code, 0, (int) strrpos($code, '.'));

            $batch[] = ['code' => $code, 'parent_code' => $parent, 'level' => $level, 'name' => $name];

            if (count($batch) >= 2000) {
                Region::insert($batch);
                $total += count($batch);
                $bar->advance(count($batch));
                $batch = [];
            }
        }
        if ($batch !== []) {
            Region::insert($batch);
            $total += count($batch);
            $bar->advance(count($batch));
        }
        $bar->finish();
        $this->newLine(2);

        $byLevel = DB::table('regions')->selectRaw('level, count(*) as c')->groupBy('level')->pluck('c', 'level');
        $this->info("Selesai. Total {$total} wilayah:");
        $this->line('  Provinsi   : '.($byLevel[1] ?? 0));
        $this->line('  Kab/Kota   : '.($byLevel[2] ?? 0));
        $this->line('  Kecamatan  : '.($byLevel[3] ?? 0));
        $this->line('  Kelurahan  : '.($byLevel[4] ?? 0));

        return self::SUCCESS;
    }

    private function loadSource(): ?string
    {
        $file = $this->option('file');
        if ($file) {
            if (! is_file($file)) {
                $this->error("File tidak ditemukan: {$file}");
                return null;
            }
            $this->info("Membaca file lokal: {$file}");
            return (string) file_get_contents($file);
        }

        $url = $this->option('url') ?: self::DEFAULT_URL;
        $this->info("Mengunduh dataset wilayah dari: {$url}");

        // Coba dengan verifikasi SSL; bila gagal (CA bundle tidak ada di server),
        // retry tanpa verifikasi (dataset publik, aman).
        foreach ([true, false] as $verify) {
            try {
                $req = Http::timeout(60);
                if (! $verify) {
                    $this->warn('  SSL verify gagal — retry tanpa verifikasi (dataset publik).');
                    $req = $req->withoutVerifying();
                }
                $resp = $req->get($url);
                if ($resp->successful()) {
                    return $resp->body();
                }
                if ($verify === false) {
                    $this->error('Gagal mengunduh (HTTP '.$resp->status().'). Coba --file=<path SQL lokal>.');
                    return null;
                }
            } catch (\Throwable $e) {
                if ($verify === false) {
                    $this->error('Gagal mengunduh: '.$e->getMessage().'. Coba --file=<path SQL lokal>.');
                    return null;
                }
            }
        }

        return null;
    }
}
