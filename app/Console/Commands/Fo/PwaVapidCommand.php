<?php

namespace App\Console\Commands\Fo;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Buat pasangan kunci VAPID untuk notifikasi push app approver (PWA) dan tulis ke .env —
 * seperti key:generate, kunci privat tidak pernah dicetak. Sekali per lingkungan; --force
 * mengganti kunci lama (semua langganan push yang ada jadi tidak berlaku).
 */
class PwaVapidCommand extends Command
{
    protected $signature = 'pwa:vapid {--force : Ganti kunci yang sudah ada}';

    protected $description = 'Buat kunci VAPID (Web Push) untuk app approver PWA dan simpan ke .env';

    public function handle(): int
    {
        $path = app()->environmentFilePath();
        $isi = (string) @file_get_contents($path);
        $ada = preg_match('/^VAPID_PRIVATE_KEY=\S+/m', $isi) === 1;

        if ($ada && ! $this->option('force')) {
            $this->info('Kunci VAPID sudah ada di .env — tidak diubah (pakai --force untuk mengganti).');

            return self::SUCCESS;
        }

        $kunci = VAPID::createVapidKeys();
        foreach (['VAPID_PUBLIC_KEY' => $kunci['publicKey'], 'VAPID_PRIVATE_KEY' => $kunci['privateKey']] as $k => $v) {
            $isi = preg_match("/^{$k}=.*$/m", $isi)
                ? preg_replace("/^{$k}=.*$/m", "{$k}={$v}", $isi)
                : rtrim($isi, "\n")."\n{$k}={$v}\n";
        }
        file_put_contents($path, $isi);

        $this->info('Kunci VAPID ditulis ke .env. Jalankan config:clear bila config di-cache.');
        $this->line('Public key: '.$kunci['publicKey']);

        return self::SUCCESS;
    }
}
