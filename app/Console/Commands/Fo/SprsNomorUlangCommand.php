<?php

namespace App\Console\Commands\Fo;

use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Services\NomorSprService;
use App\Services\Numerator\NumeratorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Satu kali: SPRS AKTIF yang terlanjur memakai nomor SPR (sebelum SPRS punya penomoran sendiri)
 * diberi nomor SPRS, lalu penghitung SPR dimundurkan ke nomor urut SPR tertinggi yang masih
 * dipakai — nomor yang dilepas (mis. 001692) diterbitkan lagi untuk SPR berikutnya.
 *
 * Nomor SPR lamanya disimpan di no_kontrak_sementara; booking BO ikut diganti nomornya.
 */
class SprsNomorUlangCommand extends Command
{
    protected $signature = 'fo:sprs-nomor-ulang
        {--jalankan : Benar-benar menulis ke database. Tanpa ini hanya menampilkan daftarnya.}';

    protected $description = 'Ganti nomor SPRS aktif yang memakai nomor SPR menjadi nomor SPRS; mundurkan penghitung SPR';

    public function handle(NumeratorService $numerator, NomorSprService $nomorSpr): int
    {
        $tulis = (bool) $this->option('jalankan');

        $daftar = Kontrak::query()->where('tahap', 'SPRS')->where('status', 'AKTIF')->orderBy('id')->get()
            ->filter(fn (Kontrak $k) => $numerator->cocokFormat('SPR', (string) $k->no_kontrak));

        if ($daftar->isEmpty()) {
            $this->info('Tidak ada SPRS aktif yang memakai nomor SPR.');
        }

        $baris = [];
        foreach ($daftar as $k) {
            $lama = (string) $k->no_kontrak;
            $baru = $tulis
                ? DB::transaction(function () use ($k, $numerator, $nomorSpr) {
                    $nomorSpr->gantiNomor($k, $numerator->next('SPRS'));

                    return (string) $k->no_kontrak;
                })
                : $numerator->peek('SPRS').' (contoh)';
            $baris[] = [$k->id, $lama, $baru, $k->customer?->full_name];
        }
        if ($baris) {
            $this->table(['id', 'nomor lama', 'nomor baru', 'customer'], $baris);
        }

        // Penghitung SPR → nomor urut SPR tertinggi yang masih ada di data (hanya dimundurkan).
        $row = DB::table('numerators')->where('tenant_id', 1)->whereNull('company_id')->where('code', 'SPR')
            ->whereNull('deleted_at')->where('is_active', true)
            ->orderByRaw("CASE WHEN reset = 'tidak' THEN 0 ELSE 1 END")->orderByDesc('period')->first();
        if ($row) {
            $tertinggi = (int) NumeratorService::tertinggiDiData('SPR', (string) $row->format);
            if ($tulis && $tertinggi < (int) $row->last_seq) {
                DB::table('numerators')->where('id', $row->id)->update(['last_seq' => $tertinggi, 'updated_at' => now()]);
            }
            $this->line("Penghitung SPR: {$row->last_seq} → ".min($tertinggi, (int) $row->last_seq)
                .' | SPR berikutnya: '.$numerator->peek('SPR').($tulis ? '' : ' (sebelum dijalankan)'));
        }

        if (! $tulis) {
            $this->warn('Belum ada yang ditulis. Jalankan ulang dengan --jalankan.');
        }

        return self::SUCCESS;
    }
}
