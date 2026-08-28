<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\BO\IproBridge\Services\IproMasterSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill master FO J-ERP → BO ipro-fo (ipro_bo).
 *   php artisan ipro:backfill            (semuanya, berurutan)
 *   php artisan ipro:backfill lokasi     (master Lokasi = "cluster" di BO)
 *   php artisan ipro:backfill customers
 *   php artisan ipro:backfill units
 *
 * Sekaligus berfungsi sebagai rekonsiliasi: aman diulang, dan mengonvergensikan apa pun yang
 * hilang karena antrean mati atau sinkron dimatikan sementara — tanpa perlu tahu sebabnya.
 */
class IproBackfillCommand extends Command
{
    protected $signature = 'ipro:backfill {entity=all : lokasi|customers|units|spr|tts|kpr|all}';

    protected $description = 'Sinkron data FO (J-ERP) ke BO ipro-fo (ipro_bo): Lokasi(cluster), customer, unit, SPR(booking), TTS, KPR';

    public function handle(IproMasterSync $sync): int
    {
        $entity = (string) $this->argument('entity');

        // Lokasi DULUAN pada mode `all`: unit menunjuk cluster, jadi menyinkronkannya belakangan
        // berarti gelombang unit pertama tersimpan dengan cluster_id kosong.
        if (in_array($entity, ['lokasi', 'all'], true)) {
            $n = 0;
            // Query builder, jadi Lokasi yang ditandai terhapus pun ikut terbaca — statusnya
            // memang harus diteruskan supaya cluster yang dipensiunkan di FO ikut pensiun di BO.
            foreach (DB::table('fo_lokasi')->orderBy('id')->cursor() as $l) {
                try {
                    $sync->syncLokasi($l);
                    $n++;
                } catch (\Throwable $e) {
                    $this->error("lokasi {$l->id}: {$e->getMessage()}");
                }
            }
            $this->info("Lokasi(cluster): {$n} disinkron ke ipro_bo");
        }

        if (in_array($entity, ['customers', 'all'], true)) {
            $n = 0;
            foreach (DB::table('fo_customers')->orderBy('id')->cursor() as $c) {
                try {
                    $sync->syncCustomer($c);
                    $n++;
                } catch (\Throwable $e) {
                    $this->error("customer {$c->code}: {$e->getMessage()}");
                }
            }
            $this->info("customers: {$n} disinkron ke ipro_bo");
        }

        if (in_array($entity, ['units', 'all'], true)) {
            $n = 0;
            foreach (DB::table('fo_units')->orderBy('id')->cursor() as $u) {
                try {
                    $sync->syncUnit($u);
                    $n++;
                } catch (\Throwable $e) {
                    $this->error("unit {$u->id}: {$e->getMessage()}");
                }
            }
            $this->info("units: {$n} disinkron ke ipro_bo");
        }

        if (in_array($entity, ['spr', 'all'], true)) {
            $n = 0;
            $skip = 0;
            foreach (DB::table('fo_kontrak')->orderBy('id')->cursor() as $k) {
                try {
                    $sync->syncSpr($k) ? $n++ : $skip++;
                } catch (\Throwable $e) {
                    $this->error("SPR {$k->no_kontrak}: {$e->getMessage()}");
                }
            }
            $this->info("SPR(booking): {$n} disinkron, {$skip} dilewati (master belum ada)");
        }

        if (in_array($entity, ['tts', 'all'], true)) {
            $n = 0;
            $skip = 0;
            foreach (DB::table('fo_tts')->orderBy('id')->cursor() as $t) {
                try {
                    $sync->syncTts($t) ? $n++ : $skip++;
                } catch (\Throwable $e) {
                    $this->error("TTS {$t->no_tts}: {$e->getMessage()}");
                }
            }
            $this->info("TTS(receipt): {$n} disinkron, {$skip} dilewati");
        }

        if (in_array($entity, ['kpr', 'all'], true)) {
            $n = 0;
            $skip = 0;
            foreach (DB::table('fo_kpr_pengajuan')->orderBy('id')->cursor() as $p) {
                try {
                    $sync->syncKpr($p) ? $n++ : $skip++;
                } catch (\Throwable $e) {
                    $this->error("KPR {$p->no_pengajuan}: {$e->getMessage()}");
                }
            }
            $this->info("KPR(initiation): {$n} disinkron, {$skip} dilewati");
        }

        return self::SUCCESS;
    }
}
