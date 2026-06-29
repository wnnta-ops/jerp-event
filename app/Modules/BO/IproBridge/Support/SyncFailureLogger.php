<?php

declare(strict_types=1);

namespace App\Modules\BO\IproBridge\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mencatat kegagalan sync/GL FO→BO ke outbox `fo_sync_failures` + log error,
 * agar divergensi terlihat & bisa di-replay (bukan ditelan diam-diam).
 */
class SyncFailureLogger
{
    public static function record(string $entity, int|string|null $entityId, string $operation, \Throwable $e): void
    {
        Log::error("ipro sync gagal [{$entity}:{$operation} #{$entityId}]: ".$e->getMessage());

        try {
            DB::table('fo_sync_failures')->insert([
                'target' => 'ipro_bo',
                'entity' => mb_substr($entity, 0, 64),
                'entity_id' => $entityId !== null ? mb_substr((string) $entityId, 0, 64) : null,
                'operation' => mb_substr($operation, 0, 64),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'resolved' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $ignore) {
            Log::error('Gagal menulis fo_sync_failures: '.$ignore->getMessage());
        }
    }
}
