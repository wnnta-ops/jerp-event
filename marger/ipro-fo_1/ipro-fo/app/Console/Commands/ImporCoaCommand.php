<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\BusinessSupport\Import\CoaImporter;
use App\Domain\BusinessSupport\Models\BsAccount;
use Illuminate\Console\Command;

/**
 * Masukkan Chart of Account dari berkas data ke bagan akun.
 *
 * Bawaannya database/data/coa-aktiva.json — hasil pembacaan lampiran "COA Aktiva.xlsx"
 * (204 akun, level 1 sampai 6). Memakai CoaImporter yang sama dengan tombol Import di layar,
 * jadi aturan pemeriksaannya persis sama lewat jalur mana pun.
 *
 * Tanpa --apply hanya melaporkan rencana. Idempoten: dicocokkan lewat kode akun.
 */
final class ImporCoaCommand extends Command
{
    protected $signature = 'coa:impor
        {--apply : Tulis ke database. Tanpa ini perintahnya hanya melaporkan rencana.}
        {--berkas= : Berkas JSON sumber (baku: database/data/coa-aktiva.json)}';

    protected $description = 'Masukkan / perbarui Chart of Account dari berkas data (baku: COA Aktiva).';

    public function handle(CoaImporter $importer): int
    {
        $berkas = (string) ($this->option('berkas') ?: database_path('data/coa-aktiva.json'));
        if (! is_file($berkas)) {
            $this->error("Berkas sumber tidak ditemukan: {$berkas}");

            return self::FAILURE;
        }

        /** @var array<int, array<string, mixed>>|null $data */
        $data = json_decode((string) file_get_contents($berkas), true);
        if (! is_array($data) || $data === []) {
            $this->error('Berkas sumber tidak berisi data yang bisa dibaca.');

            return self::FAILURE;
        }

        // Berkas JSON diubah menjadi baris bergaya CSV lebih dulu, supaya melewati pemeriksaan
        // yang SAMA dengan berkas yang diunggah operator — bukan jalur pintas yang lebih longgar.
        $baris = array_map(fn ($a) => [
            (string) $a['level'], (string) $a['code'], (string) $a['name'],
            CoaImporter::LEVEL[(int) $a['level']] ?? '',
            ($a['type'] ?? null) === 'asset' ? 'Aktiva' : (string) ($a['type'] ?? ''),
            (string) ($a['normal_balance'] ?? ''),
            ! empty($a['is_postable']) ? '√' : '',
            '', '', '',
        ], $data);

        $hasil = $importer->baca($baris);
        if ($hasil['galat'] !== []) {
            $this->error('Berkas ditolak — tidak ada yang ditulis:');
            foreach (array_slice($hasil['galat'], 0, 10) as $g) {
                $this->line('  '.$g);
            }
            if (count($hasil['galat']) > 10) {
                $this->line('  (dan '.(count($hasil['galat']) - 10).' galat lain)');
            }

            return self::FAILURE;
        }

        $akun = $hasil['akun'];
        $kode = array_column($akun, 'code');
        $ada  = BsAccount::whereIn('code', $kode)->pluck('code')->flip();
        $baru = collect($akun)->reject(fn ($a) => $ada->has($a['code']))->count();

        $this->newLine();
        $this->line('  Sumber        : '.$berkas);
        $this->line('  Akun di berkas: '.count($akun));
        $this->line('  Akan dibuat   : '.$baru);
        $this->line('  Akan diperbarui: '.(count($akun) - $baru));
        foreach (CoaImporter::LEVEL as $l => $nama) {
            $n = collect($akun)->where('level', $l)->count();
            if ($n) {
                $this->line('    level '.$l.' '.str_pad($nama, 20).$n.' akun');
            }
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('  Belum ada yang ditulis. Jalankan ulang dengan --apply untuk menyimpannya.');

            return self::SUCCESS;
        }

        $tulis = $importer->tulis($akun);
        $this->newLine();
        $this->info("  Selesai — {$tulis['baru']} akun baru, {$tulis['ubah']} diperbarui.");

        return self::SUCCESS;
    }
}
