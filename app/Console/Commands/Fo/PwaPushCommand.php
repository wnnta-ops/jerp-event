<?php

namespace App\Console\Commands\Fo;

use App\Modules\FO\Approval\Services\PushApprover;
use Illuminate\Console\Command;

/**
 * Kirim notifikasi push app approver (giliran saya, ajuan diputuskan, pengingat harian).
 * Dijadwalkan tiap menit di routes/console.php; lihat PushApprover.
 */
class PwaPushCommand extends Command
{
    protected $signature = 'pwa:push';

    protected $description = 'Cek BO Approval Inbox lalu kirim notifikasi push ke perangkat approver (PWA)';

    public function handle(PushApprover $push): int
    {
        $hasil = $push->jalankan();
        $this->line("Diperiksa {$hasil['user']} user, {$hasil['terkirim']} notifikasi terkirim.");

        return self::SUCCESS;
    }
}
