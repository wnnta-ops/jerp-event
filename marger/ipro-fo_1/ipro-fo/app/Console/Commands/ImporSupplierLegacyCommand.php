<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\BusinessSupport\Models\BsSupplier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pindahkan Data Supplier dari software lama ke Supplier Center.
 *
 * Sumbernya database/data/suppliers-legacy.json — hasil pembacaan berkas Excel
 * "Data Supplier Update 18-08-26.xlsx" (sheet DataSupplier(3), 757 baris).
 *
 * DUA KOREKSI diterapkan saat berkas itu disusun, dan alasannya dicatat di sini supaya
 * siapa pun yang membandingkannya dengan Excel aslinya tidak mengira ada data yang hilang:
 *
 *  1. NPWP yang nyasar ke kolom "Nama Bisnis". Pada berkas sumber, baris yang TIDAK punya
 *     nama bisnis menaruh NPWP-nya satu kolom lebih kiri. Terbukti dari hitungannya: kolom
 *     Nama Bisnis berisi 149 angka 15 digit, dan kolom No. NPWP terisi tepat pada 361 baris
 *     yang sama dengan baris ber-nama-bisnis sungguhan. Ke-149 angka itu dikembalikan ke
 *     NPWP. Membiarkannya berarti 149 supplier bernama bisnis berupa deretan angka pajak.
 *  2. Nilai "0" dan "-" diperlakukan sebagai KOSONG. Keduanya dipakai berkas sumber sebagai
 *     penanda tidak-ada, bukan nilai; 247 baris ber-"Nama Bisnis" = "0".
 *
 * Perintahnya IDEMPOTEN: dicocokkan dengan kode supplier, yang sudah ada diperbarui, yang
 * belum ada dibuat. Dijalankan dua kali hasilnya sama.
 *
 * Tanpa --apply, ia hanya melaporkan apa yang AKAN dikerjakan dan tidak menyentuh database.
 */
final class ImporSupplierLegacyCommand extends Command
{
    protected $signature = 'supplier:impor-legacy
        {--apply : Tulis ke database. Tanpa ini perintahnya hanya melaporkan rencana.}
        {--berkas= : Berkas JSON sumber (baku: database/data/suppliers-legacy.json)}';

    protected $description = 'Pindahkan Data Supplier software lama ke Supplier Center (kode SP.#####).';

    public function handle(): int
    {
        $berkas = (string) ($this->option('berkas') ?: database_path('data/suppliers-legacy.json'));
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

        $apply = (bool) $this->option('apply');
        $kolom = ['name', 'business_name', 'npwp', 'phone', 'phone2', 'fax', 'email',
            'address', 'address_billing', 'address_mail', 'address_identity'];

        $adaKode = BsSupplier::withTrashed()->pluck('id', 'code');
        $baru = 0;
        $ubah = 0;
        $tetap = 0;
        $bentrok = [];

        foreach ($data as $row) {
            $code = trim((string) ($row['code'] ?? ''));
            if ($code === '' || trim((string) ($row['name'] ?? '')) === '') {
                $bentrok[] = 'baris tanpa kode/nama dilewati';

                continue;
            }

            $isi = ['code' => $code, 'is_active' => true];
            foreach ($kolom as $k) {
                $v = $row[$k] ?? null;
                $isi[$k] = is_string($v) && trim($v) === '' ? null : $v;
            }

            $lama = $adaKode->get($code);
            if (! $lama) {
                $baru++;
                if ($apply) {
                    BsSupplier::create($isi);
                }

                continue;
            }

            $model = BsSupplier::withTrashed()->find($lama);
            $beda = collect($isi)->except(['code', 'is_active'])
                ->filter(fn ($v, $k) => (string) ($model->{$k} ?? '') !== (string) ($v ?? ''))->keys();
            if ($beda->isEmpty()) {
                $tetap++;

                continue;
            }
            $ubah++;
            if ($apply) {
                $model->update($isi);
            }
        }

        $this->newLine();
        $this->line('  Sumber      : '.$berkas);
        $this->line('  Baris       : '.count($data));
        $this->line('  Akan dibuat : '.$baru);
        $this->line('  Akan diubah : '.$ubah);
        $this->line('  Sudah sama  : '.$tetap);
        foreach (array_slice($bentrok, 0, 10) as $b) {
            $this->warn('  '.$b);
        }

        // Penerus kode: kode berikutnya melanjutkan nomor SP tertinggi, bukan mulai dari awal.
        $tertinggi = BsSupplier::withTrashed()->where('code', 'like', 'SP.%')
            ->selectRaw('MAX(CAST(SUBSTRING(code, 4) AS UNSIGNED)) AS n')->value('n');
        $this->line('  Kode SP tertinggi setelah impor: SP.'.str_pad((string) ((int) $tertinggi), 5, '0', STR_PAD_LEFT)
            .' → berikutnya SP.'.str_pad((string) ((int) $tertinggi + 1), 5, '0', STR_PAD_LEFT));

        $this->newLine();
        if (! $apply) {
            $this->warn('  Belum ada yang ditulis. Jalankan ulang dengan --apply untuk menyimpannya.');

            return self::SUCCESS;
        }
        $this->info('  Selesai — Supplier Center diperbarui.');

        return self::SUCCESS;
    }
}
