<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Hapus data FO contoh buatan EventDemoFoSeeder (hanya baris berlabel demo).
 * Event modul Event yang terhubung ke event NUP demo TIDAK ikut dihapus.
 */
class EventDemoFoHapusSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('EventDemoFoHapusSeeder hanya untuk lingkungan lokal/testing.');
        }

        $eventIds = DB::table('fo_nup_events')->where('tenant_id', 1)
            ->where('code', EventDemoFoSeeder::EVENT_CODE)->pluck('id');

        DB::transaction(function () use ($eventIds) {
            DB::table('fo_nup_registrations')->where('tenant_id', 1)->whereIn('nup_event_id', $eventIds)->delete();
            DB::table('fo_customers')->where('tenant_id', 1)->where('code', 'like', EventDemoFoSeeder::CUST_PREFIX.'%')->delete();
            DB::table('fo_nup_events')->whereIn('id', $eventIds)->delete();
        });

        $this->command?->info('  Data FO contoh [DEMO-EVENT] dihapus.');
    }
}
