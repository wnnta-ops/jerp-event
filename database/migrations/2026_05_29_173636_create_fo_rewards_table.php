<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('no_reward', 32);
            $table->date('tgl_reward');
            $table->foreignId('agent_id')->constrained('fo_agents')->cascadeOnUpdate()->restrictOnDelete();
            $table->enum('jenis', ['BONUS', 'TROPI', 'TIKET', 'CASHBACK', 'LAIN'])->default('BONUS');
            $table->string('nama_reward');
            $table->decimal('amount', 18, 2)->default(0);
            $table->enum('status', ['DRAFT', 'APPROVED', 'CLAIMED', 'BATAL'])->default('DRAFT');
            $table->date('tgl_claim')->nullable();
            $table->text('keterangan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'no_reward']);
            $table->index(['tenant_id', 'agent_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_rewards');
    }
};
