<?php

namespace App\Console\Commands\Fo;

use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Services\NomorSprService;
use App\Services\Numerator\NumeratorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Satu kali: SPRS AKTIF yang terlanjur memakai nomor SPR (sebelum SPRS punya penomoran sendiri)
 * diberi nomor SPRS, lalu penghitung SPR dimundurkan ke tepat sebelum nomor urut terkecil yang
 * dilepas — nomor itu (mis. 001692) diterbitkan lagi untuk SPR berikutnya. Nomor sesudahnya yang
 * masih dipakai SPR lain (mis. 001694) dilewati pengaman nomor ganda NumeratorService.
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

            return self::SUCCESS;
        }

        $row = DB::table('numerators')->where('tenant_id', 1)->whereNull('company_id')->where('code', 'SPR')
            ->whereNull('deleted_at')->where('is_active', true)
            ->orderByRaw("CASE WHEN reset = 'tidak' THEN 0 ELSE 1 END")->orderByDesc('period')->first();

        $baris = [];
        $dilepas = [];
        foreach ($daftar as $k) {
            $lama = (string) $k->no_kontrak;
            if ($row && ($seq = NumeratorService::seqDari((string) $row->format, $lama)) !== null) {
                $dilepas[] = $seq;
            }
            $baru = $tulis
                ? DB::transaction(function () use ($k, $numerator, $nomorSpr) {
                    $nomorSpr->gantiNomor($k, $numerator->next('SPRS'));

                    return (string) $k->no_kontrak;
                })
                : $numerator->peek('SPRS').' (contoh)';
            $baris[] = [$k->id, $lama, $baru, $k->customer?->full_name];
        }
        $this->table(['id', 'nomor lama', 'nomor baru', 'customer'], $baris);

        if ($row && $dilepas) {
            $mundur = min((int) $row->last_seq, min($dilepas) - 1);
            if ($tulis && $mundur < (int) $row->last_seq) {
                DB::table('numerators')->where('id', $row->id)->update(['last_seq' => $mundur, 'updated_at' => now()]);
            }
            $this->line("Penghitung SPR: {$row->last_seq} → {$mundur} | nomor urut dilepas: ".implode(', ', $dilepas)
                .($tulis ? ' | SPR berikutnya: '.$numerator->peek('SPR') : ''));
        }

        if (! $tulis) {
            $this->warn('Belum ada yang ditulis. Jalankan ulang dengan --jalankan.');
        }

        return self::SUCCESS;
    }
}
