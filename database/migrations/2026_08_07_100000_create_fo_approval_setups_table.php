<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Setup Approval — penunjukan USER approver per jenis approval (L1 & L2).
 * Aturan (lihat ApprovalService/ApprovalPolicy):
 *   - L1 diisi, L2 kosong  → single level: persetujuan L1 langsung final & eksekusi.
 *   - L1 & L2 diisi        → dua level berjalan (L1 lalu L2/final).
 *   - Keduanya kosong      → ikut aturan lama (permission per jenis + final_roles).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fo_approval_setups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1)->index();
            $table->string('jenis', 32);
            $table->foreignId('level1_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('level2_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Bypass SAAT EVENT NUP berlangsung: pengajuan jenis ini langsung
            // tereksekusi TANPA approval selama ada event NUP yang sedang buka.
            $table->boolean('bypass_saat_event')->default(false);
            $table->timestamps();
            $table->softDeletes(); // TenantAwareModel memakai SoftDeletes

            $table->unique(['tenant_id', 'jenis']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_approval_setups');
    }
};
