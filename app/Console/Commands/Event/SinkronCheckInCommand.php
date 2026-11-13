<?php

namespace App\Console\Commands\Event;

use App\Modules\Event\Jobs\SyncCheckInJob;
use App\Modules\Event\Services\SyncCheckInService;
use Illuminate\Console\Command;

/**
 * Sinkron check-in FO → peserta event. Dijadwalkan tiap 10 detik
 * (routes/console.php); bisa juga dijalankan manual.
 *
 * Jalan langsung (bukan lewat antrean) supaya event tetap tersinkron walau
 * worker antrean sedang mati.
 */
class SinkronCheckInCommand extends Command
{
    protected $signature = 'event:sinkron {event? : ID et_events; kosong = semua event yang dibuka}';

    protected $description = 'Tarik Aktivasi NUP (FO) ke peserta event yang sedang dibuka';

    public function handle(SyncCheckInService $sync): int
    {
        $id = $this->argument('event');
        $hasil = (new SyncCheckInJob($id !== null ? (int) $id : null))->handle($sync);

        if ($this->getOutput()->isVerbose() || $id !== null) {
            foreach ($hasil as $h) {
                $this->line("Event #{$h['event_id']}: baru {$h['baru']}, diperbarui {$h['diperbarui']}, dibatalkan {$h['dibatalkan']}");
            }
        }

        return self::SUCCESS;
    }
}
