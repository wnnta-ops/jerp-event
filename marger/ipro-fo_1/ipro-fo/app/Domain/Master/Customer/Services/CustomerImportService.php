<?php

declare(strict_types=1);

namespace App\Domain\Master\Customer\Services;

use App\Domain\Master\Customer\Actions\CreateCustomer;
use App\Domain\Master\Customer\Services\CustomerService;
use League\Csv\Reader;

/**
 * Bulk Upload Customer (eProperty: CustomerUpload / "Upload Customer").
 * Parse CSV → buat customer via CreateCustomer; laporkan ok/lewati/gagal.
 */
class CustomerImportService
{
    /** Kolom template (header CSV). */
    public const COLUMNS = ['full_name', 'id_type', 'id_number', 'phone', 'email', 'address_line', 'city'];

    public function __construct(
        private readonly CreateCustomer $createCustomer,
        private readonly CustomerService $customers,
    ) {}

    /** CSV template (header + 1 baris contoh). */
    public function template(): string
    {
        return implode(',', self::COLUMNS)."\n"
            .'Budi Santoso,KTP,3201010101010001,08123456789,budi@email.com,Jl. Merdeka No. 1,Jakarta'."\n";
    }

    /**
     * @return array{ok:int, skipped:int, failed:array<int,array{row:int,name:string,error:string}>}
     */
    public function import(string $csv, bool $dryRun = false): array
    {
        $reader = Reader::createFromString($csv);
        $reader->setHeaderOffset(0);

        $ok = 0; $skipped = 0; $failed = [];
        $rowNum = 1; // header = baris 1

        foreach ($reader->getRecords() as $record) {
            $rowNum++;
            $data = [];
            foreach ($record as $k => $v) {
                $data[trim((string) $k)] = trim((string) $v);
            }
            $name = $data['full_name'] ?? '';

            if ($err = $this->validateRow($data)) {
                $failed[] = ['row' => $rowNum, 'name' => $name, 'error' => $err];
                continue;
            }
            // Cek duplikat lewat service (kolom id_number terenkripsi → tak bisa
            // dibandingkan via SQL where; service membandingkan setelah dekripsi).
            if (! empty($data['id_number'])) {
                try {
                    $this->customers->ensureIdNumberUnique($data['id_number']);
                } catch (\Throwable) {
                    $skipped++;
                    continue;
                }
            }
            if ($dryRun) { $ok++; continue; }

            try {
                $this->createCustomer->execute([
                    'full_name'    => $data['full_name'],
                    'id_type'      => $data['id_type'] ?: 'KTP',
                    'id_number'    => $data['id_number'] ?? '',
                    'phone'        => $data['phone'] ?? null,
                    'email'        => $data['email'] ?: null,
                    'address_line' => $data['address_line'] ?? null,
                    'city'         => $data['city'] ?? null,
                    'nationality'  => 'WNI',
                ]);
                $ok++;
            } catch (\Throwable $e) {
                $failed[] = ['row' => $rowNum, 'name' => $name, 'error' => $e->getMessage()];
            }
        }

        return ['ok' => $ok, 'skipped' => $skipped, 'failed' => $failed];
    }

    /** @param array<string,string> $data */
    private function validateRow(array $data): ?string
    {
        if (empty($data['full_name'])) {
            return 'full_name wajib diisi.';
        }
        if (! empty($data['email']) && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return "email tidak valid ({$data['email']}).";
        }
        if (! empty($data['id_type']) && ! in_array($data['id_type'], ['KTP', 'Passport', 'SIM'], true)) {
            return "id_type harus KTP/Passport/SIM ({$data['id_type']}).";
        }

        return null;
    }
}
