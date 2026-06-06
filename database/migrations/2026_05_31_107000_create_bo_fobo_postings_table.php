<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_fobo_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('source_modul', 32);          // FO module that emitted the event
            $table->string('source_no', 64);             // source document number
            $table->enum('jenis', ['KAS_MASUK', 'SALES', 'KOMISI', 'AKAD', 'BUYBACK', 'REFUND', 'MEMO']);
            $table->foreignId('jurnal_id')->nullable()->constrained('bo_jurnal_header')->nullOnDelete();
            $table->enum('status', ['PENDING', 'POSTED', 'FAILED', 'SKIPPED'])->default('PENDING');
            $table->decimal('amount', 18, 2)->default(0);
            $table->json('meta')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Idempotency: one posting per (source, jenis). The unique key omits
            // deleted_at, so the guard holds even across soft-deletes.
            $table->unique(['tenant_id', 'source_modul', 'source_no', 'jenis'], 'fobo_idempotency');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bo_fobo_postings');
    }
};
