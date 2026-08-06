<?php

namespace App\Modules\FO\Collection\Console;

use App\Modules\FO\Collection\Services\CollectionService;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Settings\CollectionSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Penagihan massal via CLI — pindai kontrak SPR, kirim reminder pra-jatuh-tempo
 * & Surat Peringatan I/II/III sesuai Termin SP. Idempotent — tiap (tagihan,
 * jenis) hanya dikirim sekali.
 *
 * TIDAK DIJADWALKAN (permintaan — insiden email massal ke customer): reminder
 * & SP dikirim MANUAL lewat tombol Kirim di halaman Collection. Command ini
 * hanya untuk pemakaian manual eksplisit dari CLI, dan channel-nya WA SAJA —
 * email tidak pernah dikirim dari sini.
 */
class CollectionRunCommand extends Command
{
    protected $signature = 'collection:run {--dry : Hanya tampilkan tanpa mengirim}';

    protected $description = 'Kirim reminder pra-jatuh-tempo & Surat Peringatan (SP I/II/III) via WA — email hanya manual';

    public function handle(CollectionService $svc, CollectionSettings $set): int
    {
        $dry = (bool) $this->option('dry');
        $today = Carbon::today();

        // Saklar dari Setup Termin SP — default MATI. --dry boleh jalan untuk pratinjau.
        if (! $set->auto_kirim_wa && ! $dry) {
            $this->warn('Kirim Otomatis MATI (Setup Termin SP) — tidak ada yang dikirim. Nyalakan toggle "Kirim Otomatis via WhatsApp" bila ingin otomatis.');

            return self::SUCCESS;
        }

        // Otomatis = WA SAJA (email manual dari halaman Collection).
        $autoChannels = ['WA'];

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
