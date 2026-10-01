<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\BusinessSupport\Models\BsMukContract;
use App\Domain\BusinessSupport\Models\BsMukLokasi;
use App\Domain\BusinessSupport\Services\MukApi;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Tarik LOKASI beserta progresnya untuk tiap kontrak MUK → bs_muk_lokasi.
 *
 * Memakai /contracts/{id}/progress, BUKAN /locations: keduanya memulangkan daftar lokasi
 * yang sama, tetapi progress sekaligus membawa opname, ditagih, belum_ditagih, dan retensi.
 * Memanggil keduanya berarti dua kali permintaan untuk satu kontrak, dan jatah MUK hanya
 * 120 permintaan per menit.
 *
 * SATU PERMINTAAN PER KONTRAK. Dengan 95 kontrak, sekali jalan memakan 95 permintaan —
 * masih di bawah jatah, tetapi karena itu perintah ini TIDAK dijadwalkan tiap menit seperti
 * penarikan kontrak. Jalankan sesudah muk:tarik-kontrak, atau saat progres perlu disegarkan.
 *
 * PASANGAN UNIT TIDAK PERNAH DISENTUH. Kolom unit_id, dipasangkan_oleh, dan dipasangkan_pada
 * sengaja tidak ikut dalam payload updateOrCreate: itu pekerjaan orang, bukan data MUK, dan
 * menariknya ulang tidak boleh menghapus pasangan yang sudah disusun.
 */
class TarikLokasiMukCommand extends Command
{
    /** Jeda antar kontrak (detik) agar jatah 120 permintaan/menit tidak tersentuh. */
    private const JEDA_DETIK = 1;

    protected $signature = 'muk:tarik-lokasi
        {--kontrak= : Tarik satu kontrak saja, sebutkan muk_id-nya.}';

    protected $description = 'Tarik lokasi & progres per kontrak dari MUK ERP (bs_muk_lokasi)';

    public function handle(MukApi $muk): int
    {
        if (! $muk->aktif()) {
            $this->error('  Integrasi MUK belum aktif — isi MUK_API_KEY di .env, lalu ulangi.');

            return self::FAILURE;
        }

        $kontrak = BsMukContract::query()
            ->when($this->option('kontrak'), fn ($q, $id) => $q->where('muk_id', (int) $id))
            ->orderBy('muk_id')->get(['muk_id', 'no_kontrak']);

        if ($kontrak->isEmpty()) {
            $this->warn('  Tidak ada kontrak di salinan lokal — jalankan muk:tarik-kontrak dulu.');

            return self::SUCCESS;
        }

        $this->line('  Menarik lokasi untuk '.$kontrak->count().' kontrak.');
        $bar = $this->output->createProgressBar($kontrak->count());
        $bar->start();

        $sekarang = Carbon::now();
        $baru = $diperbarui = $gagal = 0;
        $tanpaLokasi = [];

        foreach ($kontrak as $k) {
            try {
                // 'lokasi', BUKAN 'data.lokasi': MukApi::ambil() sudah membuka pembungkus
                // `data` kecuali dipanggil dengan $utuh = true. Salah satu lapis membuat
                // SELURUH kontrak terbaca tanpa lokasi — dan itu menyamar jadi keadaan yang
                // masuk akal, karena paket infra & SI memang wajar tidak punya lokasi.
                $lokasi = data_get($muk->progres((int) $k->muk_id), 'lokasi', []);
            } catch (RuntimeException $e) {
                $gagal++;
                $this->newLine();
                $this->error('  kontrak '.$k->muk_id.' ('.$k->no_kontrak.'): '.$e->getMessage());
                $bar->advance();

                continue;
            }

            if ($lokasi === []) {
                $tanpaLokasi[] = (string) $k->no_kontrak;
            }

            foreach ($lokasi as $l) {
                // Lokasi tanpa identitas dilewati: sebelum 1 Okt 2026 MUK memulangkan satu
                // baris kosong per kontrak, dan menyimpannya hanya menambah baris hantu yang
                // tidak bisa dipasangkan ke unit mana pun.
                $lokasiId = $l['lokasi_id'] ?? null;
                if ($lokasiId === null) {
                    continue;
                }

                $isi = [
                    'no_kontrak'      => $k->no_kontrak,
                    'lokasi_kode'     => $l['kode'] ?? null,
                    'nama'            => $l['nama'] ?? null,
                    'tipe_rumah'      => $l['tipe_rumah'] ?? null,
                    'ukuran'          => $l['ukuran'] ?? null,
                    'blok'            => $l['blok'] ?? null,
                    'no_rumah'        => $l['no_rumah'] ?? null,
                    'baris_kontrak'   => $l['baris_kontrak'] ?? null,
                    'nilai_kontrak'   => (float) ($l['nilai_kontrak'] ?? 0),
                    'opname_nilai'    => (float) data_get($l, 'opname.nilai', 0),
                    'opname_persen'   => (float) data_get($l, 'opname.persen', 0),
                    'opname_terakhir' => data_get($l, 'opname.opname_terakhir'),
                    'ditagih_nilai'   => (float) data_get($l, 'ditagih.nilai', 0),
                    'ditagih_persen'  => (float) data_get($l, 'ditagih.persen', 0),
                    'belum_ditagih'   => (float) data_get($l, 'ditagih.belum_ditagih', 0),
                    'retensi_nilai'   => (float) data_get($l, 'retensi.nilai', 0),
                    'ditarik_pada'    => $sekarang,
                ];

                // tenant_id SENGAJA tidak dikirim — lihat TarikKontrakMukCommand: di CLI
                // tenant_id() bernilai NULL, dan hook creating HasTenant hanya mengisinya
                // pada baris BARU. Mengirimnya berarti pembaruan menulis NULL, dan baris itu
                // lenyap dari layar karena scope tenant.
                $model = BsMukLokasi::updateOrCreate(
                    ['muk_id' => (int) $k->muk_id, 'lokasi_id' => (int) $lokasiId],
                    $isi,
                );

                $model->wasRecentlyCreated ? $baru++ : $diperbarui++;
            }

            $bar->advance();
            if (self::JEDA_DETIK > 0) {
                sleep(self::JEDA_DETIK);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(['Keadaan', 'Lokasi'], [
            ['Baru', $baru],
            ['Diperbarui', $diperbarui],
            ['Total tersimpan', BsMukLokasi::count()],
            ['Kontrak gagal', $gagal],
        ]);

        // NOL MUTLAK HAMPIR PASTI BUG, BUKAN DATA. Sebagian kontrak memang tidak punya
        // lokasi, tetapi tidak mungkin SEMUANYA — kontrak housing jelas berisi ratusan rumah.
        // Keadaan ini pernah terjadi karena salah satu lapis pada jalur JSON, dan lolos
        // karena pesannya terdengar masuk akal. Sejak itu dikatakan terang-terangan.
        if ($baru === 0 && $diperbarui === 0 && $gagal === 0) {
            $this->newLine();
            $this->error('  TIDAK ADA SATU PUN lokasi tersimpan dari '.$kontrak->count().' kontrak.');
            $this->warn('  Itu hampir pasti bukan keadaan data, melainkan bentuk balasan API yang berubah.');
            $this->warn('  Periksa: php artisan tinker --execute="dump(app(App\Domain\BusinessSupport\Services\MukApi::class)->progres(8));"');

            return self::FAILURE;
        }

        // Kontrak tanpa lokasi itu WAJAR untuk paket yang bukan rumah (infra, SI), tetapi
        // disebut supaya tidak dikira penarikannya yang gagal.
        if ($tanpaLokasi !== []) {
            $this->newLine();
            $this->line('  '.count($tanpaLokasi).' kontrak tidak memulangkan lokasi — umumnya paket');
            $this->line('  infrastruktur atau site instruction yang memang bukan per rumah:');
            $this->line('  '.implode(', ', array_slice($tanpaLokasi, 0, 12)).(count($tanpaLokasi) > 12 ? ', …' : ''));
        }

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }
}
