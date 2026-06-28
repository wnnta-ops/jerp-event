<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 — CRM Activity Log (eProperty "Jurnal" tab).
 *
 * Polymorphic: bisa di-attach ke Contract, Booking, Customer, dll.
 * NOTE: ini BUKAN accounting journal — ini CRM activity log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Polymorphic subject
            $t->string('subject_type', 50);  // morph alias: 'contract', 'booking', 'customer'
            $t->unsignedBigInteger('subject_id');

            $t->string('category', 30);      // follow_up_document, complain, dll
            $t->text('description');
            $t->timestamp('logged_at')->useCurrent();
            $t->foreignId('logged_by')->nullable()->constrained('users')->nullOnDelete();

            // Attachment (scan dokumen)
            $t->string('attachment_path', 255)->nullable();
            $t->string('attachment_name', 255)->nullable();
            $t->string('attachment_mime', 100)->nullable();
            $t->unsignedBigInteger('attachment_size')->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->index(['subject_type', 'subject_id']);
            $t->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
