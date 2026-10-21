<?php

namespace App\Modules\FO\Survei\Jobs;

use App\Modules\FO\Survei\Services\WebhookSurvei;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Kirim satu baris webhook_keluar. Gagal sementara (koneksi, 429, 5xx) → diulang sesuai
 * WebhookSurvei::JEDA (7 percobaan ±10 jam); ditolak (401/413/422) → berhenti.
 * Setelah percobaan habis barisnya ditandai 'gagal' — bisa dikirim ulang dari
 * Pengaturan → Webhook Survei.
 */
class KirimWebhookSurvei implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 7;

    public function __construct(public int $webhookId) {}

    /** @return array<int,int> */
    public function backoff(): array
    {
        return WebhookSurvei::JEDA;
    }

    public function handle(): void
    {
        if (WebhookSurvei::kirim($this->webhookId) === 'ulang') {
            throw new RuntimeException('Webhook survei #' . $this->webhookId . ' belum diterima — diulang.');
        }
    }

    public function failed(?Throwable $e): void
    {
        DB::table('webhook_keluar')->where('id', $this->webhookId)->where('status', 'antre')
            ->update(['status' => 'gagal', 'updated_at' => now()]);
    }
}
