<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jadwal backup MULTI — menggantikan BackupSettings tunggal. Tiap baris = satu
 * jadwal (frekuensi/jam/lokasi sendiri). Scheduler (routes/console.php) loop semua
 * yang enabled. Config lama (settings group 'backup') dimigrasikan jadi 1 baris.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Jadwal Backup');
            $table->boolean('enabled')->default(false);
            $table->string('frequency')->default('WEEKLY');   // DAILY/WEEKLY/MONTHLY/YEARLY
            $table->string('time', 5)->default('02:00');      // HH:MM
            $table->unsignedTinyInteger('weekly_day')->default(1);   // 0=Min … 6=Sab
            $table->unsignedTinyInteger('monthly_day')->default(1);  // 1–28
            $table->unsignedTinyInteger('yearly_month')->default(1); // 1–12
            $table->unsignedTinyInteger('yearly_day')->default(1);   // 1–28
            $table->boolean('only_db')->default(true);
            $table->string('disk')->default('backup_target');
            $table->string('local_path')->nullable();
            $table->unsignedInteger('retention_days')->default(365);
            $table->timestamps();
        });

        // Migrasikan config lama (Spatie settings group 'backup') → 1 baris jadwal.
        $get = function (string $name, $default) {
            $row = DB::table('settings')->where('group', 'backup')->where('name', $name)->first();

            return $row ? json_decode($row->payload, true) : $default;
        };

        DB::table('backup_schedules')->insert([
            'name' => 'Jadwal Utama',
            'enabled' => (bool) $get('enabled', false),
            'frequency' => (string) $get('frequency', 'WEEKLY'),
            'time' => (string) $get('time', '02:00'),
            'weekly_day' => (int) $get('weekly_day', 1),
            'monthly_day' => (int) $get('monthly_day', 1),
            'yearly_month' => (int) $get('yearly_month', 1),
            'yearly_day' => (int) $get('yearly_day', 1),
            'only_db' => (bool) $get('only_db', true),
            'disk' => (string) $get('disk', 'backup_target'),
            'local_path' => $get('local_path', null),
            'retention_days' => (int) $get('retention_days', 365),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_schedules');
    }
};
