<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_sumber_data', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1)->index();
            $table->string('kode', 32);
            $table->string('nama', 100);
            $table->unsignedInteger('urutan')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('keterangan')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['tenant_id', 'kode']);
        });

        // Seed opsi bawaan (sebelumnya hardcoded di form NUP/Booked) agar perilaku tetap.
        $now = now();
        $defaults = [
            ['INSTAGRAM', 'Instagram'],
            ['FACEBOOK', 'Facebook'],
            ['WEBSITE', 'Website'],
            ['REFERRAL', 'Referral / Teman'],
            ['BANNER', 'Banner / Baliho'],
            ['AGEN', 'Agen Properti'],
            ['LAIN', 'Lainnya'],
        ];
        $rows = [];
        foreach ($defaults as $i => [$kode, $nama]) {
            $rows[] = [
                'tenant_id' => 1,
                'kode' => $kode,
                'nama' => $nama,
                'urutan' => ($i + 1) * 10,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('fo_sumber_data')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_sumber_data');
    }
};
