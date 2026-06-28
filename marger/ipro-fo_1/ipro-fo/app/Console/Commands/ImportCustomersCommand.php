<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Master\Customer\Actions\CreateCustomer;
use App\Domain\Master\Customer\Models\Customer;
use Illuminate\Console\Command;

/**
 * Bulk import customer dari CSV (migration dari Java Core System Lama).
 *
 * Usage:
 *   php artisan import:customers path/to/customers.csv --dry-run
 *   php artisan import:customers path/to/customers.csv
 *
 * CSV header (minimal): full_name,id_type,id_number,phone,email,address_line,city
 */
class ImportCustomersCommand extends Command
{
    protected $signature = 'import:customers {file : Path ke CSV file} {--dry-run : Validasi saja tanpa insert}';

    protected $description = 'Bulk import customer dari CSV (migrasi data Java Core System Lama)';

    public function handle(CreateCustomer $createCustomer): int
    {
        $file   = $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');

        if (! is_file($file)) {
            $this->error("File tidak ditemukan: {$file}");
            return self::FAILURE;
        }

        $handle = fopen($file, 'r');
        if ($handle === false) {
            $this->error("Tidak bisa membuka file: {$file}");
            return self::FAILURE;
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            $this->error('CSV kosong atau header tidak valid.');
            return self::FAILURE;
        }
        $header = array_map(fn ($h) => trim((string) $h), $header);

        $this->info(($dryRun ? '[DRY RUN] ' : '').'Mengimpor customer dari: '.basename($file));
        $this->line('Kolom: '.implode(', ', $header));
        $this->newLine();

        $rowNum   = 1;
        $ok       = 0;
        $skipped  = 0;
        $errors   = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            $data = @array_combine($header, array_map(fn ($v) => trim((string) $v), $row));

            if ($data === false) {
                $errors[] = "Baris {$rowNum}: jumlah kolom tidak cocok dengan header.";
                continue;
            }

            // Validasi minimal
            $validation = $this->validateRow($data, $rowNum);
            if ($validation !== null) {
                $errors[] = $validation;
                continue;
            }

            // Cek duplikasi via id_number
            if (! empty($data['id_number'])) {
                $exists = Customer::query()->get()->contains(
                    fn ($c) => $c->id_number === $data['id_number']
                );
                if ($exists) {
                    $skipped++;
                    $this->line("  <comment>SKIP</comment> Baris {$rowNum}: id_number sudah ada ({$data['full_name']})");
                    continue;
                }
            }

            if ($dryRun) {
                $ok++;
                $this->line("  <info>OK</info>   Baris {$rowNum}: {$data['full_name']} — siap diimpor");
                continue;
            }

            try {
                $customer = $createCustomer->execute([
                    'full_name'    => $data['full_name'],
                    'id_type'      => $data['id_type'] ?: 'KTP',
                    'id_number'    => $data['id_number'] ?? '',
                    'phone'        => $data['phone'] ?? null,
                    'email'        => $data['email'] ?? null,
                    'address_line' => $data['address_line'] ?? null,
                    'city'         => $data['city'] ?? null,
                    'nationality'  => 'WNI',
                ]);
                $ok++;
                $this->line("  <info>✓</info> Baris {$rowNum}: {$customer->code} — {$customer->full_name}");
            } catch (\Throwable $e) {
                $errors[] = "Baris {$rowNum}: ".$e->getMessage();
            }
        }

        fclose($handle);

        $this->newLine();
        $this->line(str_repeat('═', 60));
        $this->info("Berhasil : {$ok}");
        $this->line("Dilewati  : {$skipped} (duplikat)");
        if ($errors) {
            $this->error('Error    : '.count($errors));
            foreach ($errors as $err) {
                $this->line("  • {$err}");
            }
        }
        $this->line(str_repeat('═', 60));

        if ($dryRun) {
            $this->comment('DRY RUN — tidak ada data yang disimpan. Jalankan tanpa --dry-run untuk import sungguhan.');
        }

        return empty($errors) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param array<string, string> $data
     */
    private function validateRow(array $data, int $rowNum): ?string
    {
        if (empty($data['full_name'])) {
            return "Baris {$rowNum}: full_name wajib diisi.";
        }
        if (! empty($data['email']) && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return "Baris {$rowNum}: email tidak valid ({$data['email']}).";
        }
        if (! empty($data['id_type']) && ! in_array($data['id_type'], ['KTP', 'Passport', 'SIM'], true)) {
            return "Baris {$rowNum}: id_type tidak valid ({$data['id_type']}). Harus KTP/Passport/SIM.";
        }

        return null;
    }
}
