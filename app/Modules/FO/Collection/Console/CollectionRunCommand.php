<?php

namespace App\Modules\FO\Collection\Console;

use App\Modules\FO\Collection\Services\CollectionService;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Settings\CollectionSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Penagihan otomatis: pindai kontrak SPR, kirim reminder pra-jatuh-tempo &
 * Surat Peringatan I/II/III sesuai Termin SP (via Email/WA). Idempotent —
 * tiap (tagihan, jenis) hanya dikirim sekali. Jadwalkan harian (routes/console.php).
 */
class CollectionRunCommand extends Command
{
    protected $signature = 'collection:run {--dry : Hanya tampilkan tanpa mengirim}';

    protected $description = 'Kirim reminder pra-jatuh-tempo & Surat Peringatan (SP I/II/III) untuk AR kontrak SPR';

    public function handle(CollectionService $svc, CollectionSettings $set): int
    {
        $dry = (bool) $this->option('dry');
        $today = Carbon::today();

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
                            $svc->kirim($kontrak, $t, 'REMINDER', null, 0);
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
                        $svc->kirim($kontrak, $t, $target, null, $telat);
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
