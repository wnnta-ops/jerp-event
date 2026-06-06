<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_komisi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('no_komisi', 32);
            $table->date('tgl_komisi');
            $table->foreignId('agent_id')->constrained('fo_agents')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('kontrak_id')->constrained('fo_kontrak')->cascadeOnUpdate()->restrictOnDelete();
            $table->decimal('dpp', 18, 2)->default(0);                    // dasar pengenaan komisi
            $table->decimal('persen_komisi', 5, 2)->default(0);
            $table->decimal('amount', 18, 2)->default(0);
            $table->decimal('pph_persen', 5, 2)->default(0);              // PPh 21/23
            $table->decimal('pph_amount', 18, 2)->default(0);
            $table->decimal('amount_net', 18, 2)->default(0);
            $table->enum('status', ['DRAFT', 'APPROVED', 'PAID', 'BATAL'])->default('DRAFT');
            $table->date('tgl_bayar')->nullable();
            $table->foreignId('bkk_ref_id')->nullable();                  // future link to BO BKK
            $table->text('keterangan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'no_komisi']);
            $table->index(['tenant_id', 'agent_id']);
            $table->index(['tenant_id', 'kontrak_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_komisi');
    }
};
