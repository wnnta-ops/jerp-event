<?php

namespace App\Modules\FO\Collection\Console;

use App\Modules\FO\Collection\Services\CollectionService;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Settings\CollectionSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Penagihan otomatis harian: pindai kontrak SPR, kirim reminder pra-jatuh-tempo
 * & Surat Peringatan I/II/III sesuai Termin SP. Idempotent — tiap (tagihan,
 * jenis) hanya dikirim sekali. Dijadwalkan harian (routes/console.php).
 *
 * Channel mengikuti toggle Email/WA di Setup Termin SP — KEDUANYA DEFAULT MATI
 * (insiden email massal ke customer): selama keduanya mati, command keluar
 * tanpa mengirim apa pun; pengiriman hanya lewat tombol Kirim manual.
 */
class CollectionRunCommand extends Command
{
    protected $signature = 'collection:run {--dry : Hanya tampilkan tanpa mengirim}';

    protected $description = 'Kirim reminder & Surat Peringatan (SP I/II/III) sesuai toggle channel Setup Termin SP (default mati = tidak ada kiriman)';

    public function handle(CollectionService $svc, CollectionSettings $set): int
    {
        $dry = (bool) $this->option('dry');
        $today = Carbon::today();

        // Channel ikut toggle Setup Termin SP — keduanya MATI (default) = tidak ada
        // pengiriman otomatis sama sekali. --dry tetap boleh untuk pratinjau.
        $autoChannels = array_values(array_filter([
            $set->channel_email ? 'EMAIL' : null,
            $set->channel_wa ? 'WA' : null,
        ]));
        if ($autoChannels === [] && ! $dry) {
            $this->warn('Kedua channel (Email & WA) MATI di Setup Termin SP — tidak ada pengiriman otomatis. Kirim manual lewat halaman Collection, atau nyalakan toggle bila ingin otomatis.');

            return self::SUCCESS;
        }

        $kontraks = Kontrak::query()
            ->where('tahap', 'SPR')
            ->whereNotIn('status', ['BATAL', 'LUNAS'])
            ->with(['customer', 'unit', 'tagihan'])
            ->get();

        $reminder = 0;
        $sp = 0;

        foreach ($kontraks as $kontrak) {
            foreach ($svc->arTagihan($kontrak) as $t) {
                $telat = $svc->hariTelat($t);

                if ($telat <= 0) {
                    // Belum jatuh tempo → reminder bila masuk jendela H- dan belum dikirim.
                    $hSampaiJT = $today->diffInDays($t->jatuh_tempo->copy()->startOfDay(), false);
                    if ($hSampaiJT >= 0 && $hSampaiJT <= $set->reminder_days_before
                        && ! $svc->sudahKirim($t->id, 'REMINDER')) {
                        $this->line("REMINDER · {$kontrak->no_kontrak} · {$t->nama_tagihan} (H-{$hSampaiJT})");
                        if (! $dry) {
                            $svc->kirim($kontrak, $t, 'REMINDER', $autoChannels, 0);
                        }
                        $reminder++;
                    }

                    continue;
                }

                // Sudah telat → tentukan level SP tertinggi yang memenuhi, kirim bila belum.
                $target = $this->levelTarget($telat, $set);
                if ($target && ! $svc->sudahKirim($t->id, $target)) {
                    $this->line("{$target} · {$kontrak->no_kontrak} · {$t->nama_tagihan} (telat {$telat} hari)");
                    if (! $dry) {
                        $svc->kirim($kontrak, $t, $target, $autoChannels, $telat);
                    }
                    $sp++;
                }
            }
        }

        $this->info(($dry ? '[DRY] ' : '')."Selesai — reminder: {$reminder}, SP: {$sp}, kontrak SPR diperiksa: ".$kontraks->count());

        return self::SUCCESS;
    }

    /** Level SP tertinggi yang memenuhi ambang keterlambatan (null bila belum). */
    private function levelTarget(int $telat, CollectionSettings $set): ?string
    {
        return match (true) {
            $telat >= $set->sp3_overdue_days => 'SP3',
            $telat >= $set->sp2_overdue_days => 'SP2',
            $telat >= $set->sp1_overdue_days => 'SP1',
            default => null,
        };
    }
}
