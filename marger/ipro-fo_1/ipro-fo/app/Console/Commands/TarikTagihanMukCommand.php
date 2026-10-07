<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\BusinessSupport\Models\BsMukContract;
use App\Domain\BusinessSupport\Models\BsMukTagihan;
use App\Domain\BusinessSupport\Services\MukApi;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Tarik TAGIHAN (COP Owner) tiap kontrak MUK → bs_muk_tagihan.
 *
 * SATU PERMINTAAN PER KONTRAK, sama seperti muk:tarik-lokasi. Dengan 95 kontrak sekali
 * jalan memakan 95 permintaan dari jatah 120/menit, jadi perintah ini TIDAK dijadwalkan
 * tiap menit — jalankan sesudah muk:tarik-kontrak atau saat daftar tagihan perlu disegarkan.
 *
 * ── KOLOM POSTING TIDAK PERNAH DISENTUH ──
 *
 * supplier_id, due_date, dan diposting_* adalah keputusan kita, bukan data MUK. Kalau ikut
 * ditimpa, tagihan yang sudah diposting ke AP akan muncul lagi di antrean dan dibayar dua
 * kali. Karena itu yang dikirim ke updateOrCreate hanya kolom nilai.
 */
class TarikTagihanMukCommand extends Command
{
    /** Jeda antar kontrak (detik) agar jatah 120 permintaan/menit tidak tersentuh. */
    private const JEDA_DETIK = 1;

    protected $signature = 'muk:tarik-tagihan
        {--kontrak= : Tarik satu kontrak saja, sebutkan muk_id-nya.}';

    protected $description = 'Tarik tagihan (COP Owner) per kontrak dari MUK ERP (bs_muk_tagihan)';

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

        $this->line('  Menarik tagihan untuk '.$kontrak->count().' kontrak.');
        $bar = $this->output->createProgressBar($kontrak->count());
        $bar->start();

        $sekarang = Carbon::now();
        $baru = $diperbarui = $gagal = 0;
        $tanpaTagihan = [];

        foreach ($kontrak as $k) {
            try {
                $tagihan = $muk->tagihan((int) $k->muk_id);
            } catch (RuntimeException $e) {
                $gagal++;
                $this->newLine();
                $this->error('  kontrak '.$k->muk_id.' ('.$k->no_kontrak.'): '.$e->getMessage());
                $bar->advance();

                continue;
            }

            if ($tagihan === []) {
                $tanpaTagihan[] = (string) $k->no_kontrak;
            }

            foreach ($tagihan as $t) {
                // Tanpa nomor tagihan tidak ada yang bisa dijadikan kunci, dan barisnya tidak
                // akan pernah bisa ditunjuk invoice AP. Dilewati, bukan disimpan sebagai hantu.
                $no = trim((string) ($t['no_tagihan'] ?? ''));
                if ($no === '') {
                    continue;
                }

                // tenant_id SENGAJA tidak dikirim — lihat TarikKontrakMukCommand: di CLI
                // tenant_id() bernilai NULL, dan hook creating HasTenant hanya mengisinya
                // pada baris BARU. Mengirimnya berarti pembaruan menulis NULL, dan baris itu
                // lenyap dari layar karena scope tenant.
                $model = BsMukTagihan::updateOrCreate(
                    ['muk_id' => (int) $k->muk_id, 'no_tagihan' => $no],
                    [
                        'no_kontrak' => $k->no_kontrak,
                        'jenis' => $t['jenis'] ?? null,
                        'tanggal' => $t['tanggal'] ?? null,
                        'jatuh_tempo' => $t['jatuh_tempo'] ?? null,
                        'dpp' => (float) ($t['dpp'] ?? 0),
                        'ppn' => (float) ($t['ppn'] ?? 0),
                        'pph' => (float) ($t['pph'] ?? 0),
                        'total' => (float) ($t['total'] ?? 0),
                        'diterima' => (float) ($t['diterima'] ?? 0),
                        'status' => $t['status'] ?? null,
                        'ditarik_pada' => $sekarang,
                    ],
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

        $this->table(['Keadaan', 'Tagihan'], [
            ['Baru', $baru],
            ['Diperbarui', $diperbarui],
            ['Total tersimpan', BsMukTagihan::count()],
            ['Kontrak gagal', $gagal],
        ]);

        // NOL MUTLAK HAMPIR PASTI BUG, BUKAN DATA — pelajaran dari muk:tarik-lokasi, yang
        // sempat menyimpan nol karena satu lapis keliru pada jalur JSON dan lolos karena
        // pesannya terdengar masuk akal. Kontrak yang sudah berjalan pasti punya COP.
        if ($baru === 0 && $diperbarui === 0 && $gagal === 0) {
            $this->newLine();
            $this->error('  TIDAK ADA SATU PUN tagihan tersimpan dari '.$kontrak->count().' kontrak.');
            $this->warn('  Itu hampir pasti bukan keadaan data, melainkan bentuk balasan API yang berubah.');
            $this->warn('  Periksa: php artisan tinker --execute="dump(app(App\Domain\BusinessSupport\Services\MukApi::class)->tagihan(8));"');

            return self::FAILURE;
        }

        // Kontrak tanpa tagihan itu WAJAR untuk SPK yang belum pernah ditagihkan, tetapi
        // disebut supaya tidak dikira penarikannya yang gagal.
        if ($tanpaTagihan !== []) {
            $this->newLine();
            $this->line('  '.count($tanpaTagihan).' kontrak belum punya satu pun COP Owner.');
        }

        return self::SUCCESS;
    }
}
