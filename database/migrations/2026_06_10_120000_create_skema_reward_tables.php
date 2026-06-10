<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Setup Skema Reward (Closing Fee) — aturan reward per (project, tipe sales).
 * Beda dari fo_rewards (catatan reward per-agent). Punya baris rumus reward
 * per level: tipe target (Unit/Nilai) + rentang target + tipe reward (Uang/Non).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_skema_reward', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->string('kode', 32);
            $table->string('nama');
            $table->date('periode_dari')->nullable();
            $table->date('periode_sampai')->nullable();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('sales_type_id')->nullable()->constrained('fo_sales_types')->nullOnDelete();
            $table->enum('rumus_closing_fee', ['SATUAN_PER_UNIT', 'TARGET_KUMULATIF'])->default('TARGET_KUMULATIF');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'kode']);
        });

        Schema::create('fo_skema_reward_line', function (Blueprint $table) {
            $table->id();
            $table->foreignId('skema_reward_id')->constrained('fo_skema_reward')->cascadeOnDelete();
            $table->enum('level', ['KANTOR', 'KOORDINATOR', 'SALES'])->default('SALES');
            $table->enum('tipe_target', ['UNIT', 'NILAI'])->default('UNIT');
            $table->decimal('target_bawah', 18, 2)->default(0);
            $table->decimal('target_atas', 18, 2)->default(0);
            $table->enum('tipe_reward', ['UANG', 'NON_UANG'])->default('UANG');
            $table->decimal('reward_amount', 18, 2)->default(0);     // jika UANG
            $table->string('reward_keterangan')->nullable();          // jika NON_UANG (mis. Tiket, Tropi)
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_skema_reward_line');
        Schema::dropIfExists('fo_skema_reward');
    }
};
