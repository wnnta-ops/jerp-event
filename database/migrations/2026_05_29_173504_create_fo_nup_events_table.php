<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_nup_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->date('tgl_launching');
            $table->dateTime('open_registration_at')->nullable();
            $table->dateTime('close_registration_at')->nullable();
            $table->unsignedInteger('kuota')->default(0);
            $table->decimal('booking_fee', 18, 2)->default(0);
            $table->enum('status', ['DRAFT', 'OPEN', 'CLOSED', 'EXECUTED', 'BATAL'])->default('DRAFT');
            $table->text('keterangan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'project_id']);
            $table->index(['tenant_id', 'tgl_launching']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_nup_events');
    }
};
