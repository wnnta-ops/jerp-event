<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\BO\IproBridge\Services\IproMasterSync;
use App\Modules\Eprop\Services\MesinSinkron;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Rekonsiliasi harian eProperty → J-ERP. Dijalankan SESUDAH `bangun_bayang.sh` membangun
 * basis data bayangan dari kiriman backup eProperty terbaru.
 *
 *   php artisan eprop:sinkron --kiriman=2026-10-20 --kering     (uji: lihat laporan, tidak menulis)
 *   php artisan eprop:sinkron --kiriman=2026-10-20              (terapkan)
 */
class EpropSinkronCommand extends Command
{
    protected $signature = 'eprop:sinkron
        {--kiriman= : tanggal kiriman backup eProperty (untuk catatan)}
        {--batas= : waktu data eProperty dimuat ke server ini (input J-ERP sesudahnya = pembanding); bawaan dari .env EPROP_BATAS}
        {--fo= : database FO J-ERP (bawaan: koneksi utama)}
        {--bo= : database BO J-ERP (bawaan: koneksi ipro_bo)}
        {--bayang-fo=bayang_fo} {--bayang-bo=bayang_bo}
        {--modul= : fo = hanya dokumen FO (BO, KPR & jurnal tidak disentuh); kosong = semua}
        {--hanya-baru : hanya memasukkan dokumen yang belum ada; data J-ERP yang sudah ada tidak diubah}
        {--kering : jalankan lalu batalkan — hanya laporan}';

    protected $description = 'Bandingkan kiriman eProperty dengan J-ERP: tautkan yang sama, masukkan yang terlewat, tahan yang meragukan';

    public function handle(): int
    {
        $batas = (string) ($this->option('batas') ?: env('EPROP_BATAS', ''));
        if ($batas === '') {
            $this->error('Isi --batas (atau EPROP_BATAS di .env): waktu data eProperty dimuat ke server ini, mis. "2026-10-18 23:59:59".');

            return self::FAILURE;
        }
        $db = [
            'fo' => (string) ($this->option('fo') ?: DB::connection()->getDatabaseName()),
            'bo' => (string) ($this->option('bo') ?: DB::connection((string) config('ipro.connection', 'ipro_bo'))->getDatabaseName()),
            'bfo' => (string) $this->option('bayang-fo'),
            'bbo' => (string) $this->option('bayang-bo'),
        ];
        $kering = (bool) $this->option('kering');
        $modul = $this->option('modul') ?: null;
        if ($modul !== null && ! in_array($modul, ['fo', 'bo'], true)) {
            $this->error('--modul hanya fo atau bo.');

            return self::FAILURE;
        }
        $hanyaBaru = (bool) $this->option('hanya-baru');
        $this->info(sprintf('Sinkron eProperty → J-ERP %s%s%s  (FO %s ← %s · BO %s ← %s · batas %s)',
            $kering ? '[UJI — tidak menulis]' : '', $modul ? ' [HANYA ' . strtoupper($modul) . ']' : '', $hanyaBaru ? ' [HANYA DATA BARU]' : '', $db['fo'], $db['bfo'], $db['bo'], $db['bbo'], $batas));

        $mesin = new MesinSinkron($db, $batas, $kering, $this->option('kiriman') ?: null, $modul, $hanyaBaru);
        try {
            $hasil = $mesin->jalankan(fn (string $s) => $this->line($s));
        } catch (Throwable $e) {
            $this->error('GAGAL — tidak ada yang berubah: ' . $e->getMessage());
            report($e);

            return self::FAILURE;
        }

        // Kontrak baru dari eProperty → booking di BO (seperti kontrak yang dibuat di J-ERP).
        if (! $kering && $hasil['kontrak_baru']) {
            $sync = app(IproMasterSync::class);
            foreach ($hasil['kontrak_baru'] as $id) {
                try {
                    if ($k = Kontrak::query()->with(['customer', 'unit'])->find($id)) {
                        $k->customer && $sync->syncCustomer($k->customer);
                        $k->unit && $sync->syncUnit($k->unit);
                        $sync->syncSpr($k);
                    }
                } catch (Throwable $e) {
                    $this->warn("Sinkron BO kontrak #{$id}: {$e->getMessage()}");
                }
            }
        }

        // SPRS J-ERP berharga 0 yang diisi dari eProperty → seperti "Simpan sebagai SPR": Faktur Penjualan
        // terbit + booking BO ikut harga baru.
        if (! $kering && $hasil['kontrak_diisi']) {
            $sync = app(IproMasterSync::class);
            foreach ($hasil['kontrak_diisi'] as $id) {
                try {
                    if ($k = Kontrak::query()->with(['customer', 'unit'])->find($id)) {
                        \App\Modules\FO\FinanceAR\Services\FakturPenjualanService::ensureForKontrak($k);
                        $sync->syncSpr($k);
                        $this->line("  SPR {$k->no_kontrak} diisi dari eProperty — Faktur Penjualan & booking BO diperbarui.");
                    }
                } catch (Throwable $e) {
                    $this->warn("Faktur/BO kontrak #{$id}: {$e->getMessage()}");
                }
            }
        }

        // Webhook Survei: SPR yang masuk/diperbarui dari eProperty tidak memicu observer (ditulis
        // langsung ke tabel), jadi notifikasinya dikirim di sini — satu per SPR.
        if (! $kering && \App\Modules\FO\Survei\Services\WebhookSurvei::aktif()) {
            $this->kirimWebhookSurvei($hasil);
        }

        $this->newLine();
        $this->info('RINGKASAN (jalan #' . $hasil['jalan_id'] . ')');
        $baris = [];
        foreach ($hasil['ringkasan'] as $modul => $a) {
            $baris[] = [$modul, $a['masuk'] ?? 0, $a['cocok'] ?? 0, $a['ditahan'] ?? 0, $a['diperbarui'] ?? 0, $a['konflik'] ?? 0, $a['hilang'] ?? 0];
        }
        $this->table(['Modul', 'Masuk', 'Cocok', 'Ditahan', 'Diperbarui', 'Konflik', 'Hilang di eProp'], $baris);

        foreach (['ditahan' => 'PERLU DICEK — ditahan', 'konflik' => 'PERLU DICEK — konflik', 'masuk' => 'MASUK OTOMATIS', 'cocok' => 'COCOK (ditautkan)'] as $aksi => $judul) {
            $isi = array_values(array_filter($hasil['hasil'], fn ($h) => $h['aksi'] === $aksi && $h['modul'] !== 'Master'));
            if (! $isi) {
                continue;
            }
            $this->newLine();
            $this->warn($judul . ' (' . count($isi) . ')');
            $this->table(['Modul', 'No. eProperty', 'Tanggal', 'Isi', 'J-ERP / alasan'], array_map(fn ($h) => [
                $h['modul'], $h['kunci'], $h['tanggal'], mb_substr((string) $h['ringkas'], 0, 70),
                mb_substr(trim(($h['jerp'] ?? '') . ' ' . ($h['alasan'] ?? '')), 0, 90),
            ], array_slice($isi, 0, 80)));
        }

        $this->newLine();
        $this->info($kering ? 'UJI selesai — tidak ada data yang berubah.' : 'Selesai.');

        return self::SUCCESS;
    }

    /** Satu notifikasi per SPR yang masuk / diperbarui, termasuk SPR milik customer yang namanya berubah. */
    private function kirimWebhookSurvei(array $hasil): void
    {
        $noSpr = [];
        $custIds = [];
        foreach ($hasil['hasil'] as $h) {
            if (! in_array($h['aksi'] ?? '', ['masuk', 'diperbarui'], true)) {
                continue;
            }
            if (($h['tabel'] ?? '') === 'fo_kontrak') {
                $noSpr[(string) $h['kunci']] = $h['aksi'] === 'masuk' ? 'spr.created' : 'spr.updated';
            }
        }
        foreach ($hasil['hasil'] as $h) {
            if (($h['tabel'] ?? '') === 'fo_customers' && ($h['aksi'] ?? '') === 'diperbarui') {
                $custIds[] = (string) $h['kunci'];
            }
        }

        $n = 0;
        $q = Kontrak::query()->where('tahap', 'SPR')->where(function ($w) use ($noSpr, $hasil, $custIds) {
            $w->whereIn('no_kontrak', array_keys($noSpr) ?: ['-'])
                ->orWhereIn('id', $hasil['kontrak_baru'] ?: [0]);
            if ($custIds) {
                $w->orWhereHas('customer', fn ($c) => $c->whereIn('code', $custIds));
            }
        });
        foreach ($q->get() as $k) {
            $event = $noSpr[(string) $k->no_kontrak] ?? (in_array($k->id, $hasil['kontrak_baru'], true) ? 'spr.created' : 'spr.updated');
            if ($event !== 'spr.created' && strtoupper((string) $k->status) === 'BATAL') {
                $event = 'spr.cancelled';
            }
            \App\Modules\FO\Survei\Services\WebhookSurvei::antreKontrak($k, $event);
            $n++;
        }
        if ($n) {
            $this->line("Webhook Survei: {$n} SPR diantre untuk dikirim.");
        }
    }
}
