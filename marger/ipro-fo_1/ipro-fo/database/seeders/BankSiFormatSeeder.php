<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\BankLoan\Models\BankPartner;
use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Builder;

/**
 * Penetapan Format SI bawaan per bank rekanan KPR.
 *
 * Dipatok pada KODE BANK (kolom code di Set Up KPR → Kategori Bank) karena itulah penanda
 * yang stabil: nama bank bisa berubah ("Bank Mandiri" → "MANDIRI KCP …") tanpa mengubah
 * kodenya. Nama dipakai hanya sebagai cadangan bila kodenya berbeda dari daftar ini.
 *
 * Idempoten & aman dijalankan ulang. Yang disentuh HANYA kolom si_format — format hasil
 * suntingan operator (si_template) tidak pernah ikut diubah, dan suntingan itu tetap menang
 * atas format bawaan saat surat dicetak.
 */
class BankSiFormatSeeder extends Seeder
{
    /** KODE BANK → kode format SI bawaan (lihat BankPartner::SI_FORMATS). */
    public const BY_CODE = [
        'MDR' => 'mandiri',
        'BNI' => 'bni',
        'BRI' => 'bri',
        'BTN' => 'btn',
        'BSN' => 'bsn',
    ];

    /** Cadangan bila kode banknya tidak ada di daftar di atas: cocokkan dari NAMA. */
    public const BY_NAME = [
        'mandiri' => ['mandiri'],
        'bni'     => ['negara indonesia', 'bni'],
        'bri'     => ['rakyat indonesia'],
        'btn'     => ['tabungan negara'],
        'bsn'     => ['syariah nasional', 'bsn'],
    ];

    public function run(): void
    {
        $ubah = 0;
        $lewat = [];

        foreach (BankPartner::query()->get(['id', 'code', 'name', 'si_format']) as $bank) {
            $format = self::BY_CODE[mb_strtoupper(trim((string) $bank->code))] ?? $this->dariNama((string) $bank->name);
            if ($format === null) {
                $lewat[] = trim(($bank->code ? $bank->code.' — ' : '').$bank->name);

                continue;
            }
            if ($bank->si_format === $format) {
                continue;   // sudah benar, tidak perlu ditulis ulang
            }
            $bank->forceFill(['si_format' => $format])->save();
            $ubah++;
        }

        $this->command?->info("Format SI bank: {$ubah} bank diperbarui.");
        if ($lewat) {
            // Disebutkan, bukan didiamkan — bank tanpa format akan gagal Cetak SI.
            $this->command?->warn('  Belum punya Format SI (pilih manual di Kategori Bank): '.implode(', ', $lewat));
        }
    }

    private function dariNama(string $name): ?string
    {
        $n = mb_strtolower($name);
        foreach (self::BY_NAME as $format => $katas) {
            foreach ($katas as $k) {
                if (str_contains($n, $k)) {
                    return $format;
                }
            }
        }

        return null;
    }
}
