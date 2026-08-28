<?php

declare(strict_types=1);

namespace App\Modules\BO\IproBridge\Jobs;

use App\Modules\BO\IproBridge\Services\IproMasterSync;
use App\Modules\BO\IproBridge\Support\SyncFailureLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sinkronisasi master FO → BO (ipro_bo) secara ASINKRON (di-queue, dijalankan
 * horizon). Dilepas dari request FO → tak menambah latensi simpan. Reload model
 * by id (data terbaru). Gagal setelah retry → outbox fo_sync_failures.
 *
 * Jalan SETELAH transaksi FO commit (dispatch ->afterCommit() di BoServiceProvider).
 * Bila QUEUE_CONNECTION=sync, job tetap jalan (inline setelah commit).
 */
class SyncFoToIproJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    /**
     * @param  class-string  $modelClass
     * @param  bool  $withTrashed  ikut memuat baris yang sudah ditandai terhapus. Diperlukan
     *                             untuk peristiwa `deleted`: tanpa ini find() memulangkan null
     *                             tepat pada saat penghapusannya justru harus diteruskan ke BO,
     *                             sehingga master yang dinonaktifkan di FO tetap hidup di BO.
     *                             Bernilai bawaan supaya payload antrean lama tetap terbaca.
     */
    public function __construct(
        public string $method,
        public string $modelClass,
        public int|string $modelId,
        public bool $withTrashed = false,
    ) {}

    public function handle(IproMasterSync $sync): void
    {
        $q = $this->modelClass::query();
        if ($this->withTrashed && method_exists($this->modelClass, 'bootSoftDeletes')) {
            $q->withTrashed();
        }

        $model = $q->find($this->modelId);
        if (! $model) {
            return; // sumber FO sudah dihapus permanen — tidak ada yang disinkron
        }

        $sync->{$this->method}($model);
    }

    public function failed(\Throwable $e): void
    {
        SyncFailureLogger::record(class_basename($this->modelClass), $this->modelId, $this->method, $e);
    }
}
