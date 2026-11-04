<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan Budget Invoice — invoice hutang yang butuh Alokasi / Koreksi Budget di langkah Struktur
 * tertahan sebagai draft (nomor invoice dipesan) sampai Finance menyetujui; baru setelah itu boleh
 * lanjut ke Selesai & jurnal terbit.
 *
 *  - bs_ap_budget_requests: draft wizard + status pengajuan (menunggu / disetujui / ditolak / dipakai / batal).
 *  - bs_budget_allocations.approval_id: alokasi dari pengajuan diputuskan lewat Approval Inbox.
 *  - Chain approval satu tahap Finance: KB-DEFAULT (Koreksi Budget — juga koreksi manual) diganti
 *    jadi Finance, dan AL-DEFAULT baru untuk Alokasi Budget. User approvernya diatur di
 *    System › Approval Chain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_ap_budget_requests', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('user_id');
            $t->string('jenis', 20)->default('invoice');
            $t->unsignedBigInteger('invoice_id')->nullable();   // edit invoice yang sudah ada
            $t->unsignedBigInteger('link_id')->nullable();      // dari Generate Link (nomor milik link)
            $t->string('invoice_number')->nullable();           // nomor dipesan (registrasi baru)
            $t->json('data');
            $t->string('status', 20)->default('menunggu');
            $t->string('catatan')->nullable();                  // alasan ditolak
            $t->unsignedBigInteger('ap_invoice_id')->nullable();
            $t->timestamp('diajukan_at')->nullable();           // ronde pengajuan terakhir (ajukan ulang = ronde baru)
            $t->timestamp('decided_at')->nullable();
            $t->timestamp('seen_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'status']);
        });
        Schema::table('bs_budget_allocations', function (Blueprint $t): void {
            $t->unsignedBigInteger('approval_id')->nullable()->after('status');
        });

        $sekarang = now();
        $langkahFinance = fn (int $chainId) => DB::table('approval_chain_steps')->insert([
            'approval_chain_id' => $chainId, 'level' => 1, 'approver_type' => 'role', 'approver_value' => 'Finance',
            'label' => 'Finance', 'is_final' => true, 'created_at' => $sekarang, 'updated_at' => $sekarang,
        ]);
        foreach (DB::table('approval_chains')->where('code', 'KB-DEFAULT')->get() as $kb) {
            DB::table('approval_chain_steps')->where('approval_chain_id', $kb->id)->delete();
            $langkahFinance($kb->id);
            DB::table('approval_chains')->where('id', $kb->id)->update(['name' => 'Koreksi Budget', 'updated_at' => $sekarang]);
            if (! DB::table('approval_chains')->where('tenant_id', $kb->tenant_id)->where('code', 'AL-DEFAULT')->exists()) {
                $id = DB::table('approval_chains')->insertGetId([
                    'tenant_id' => $kb->tenant_id, 'project_id' => null, 'document_type' => 'budget_allocation', 'code' => 'AL-DEFAULT',
                    'name' => 'Alokasi Budget dari Invoice Hutang', 'match_conditions' => null, 'is_active' => true,
                    'created_at' => $sekarang, 'updated_at' => $sekarang,
                ]);
                $langkahFinance($id);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('approval_chains')->where('code', 'AL-DEFAULT')->pluck('id') as $id) {
            DB::table('approval_chain_steps')->where('approval_chain_id', $id)->delete();
            DB::table('approval_chains')->where('id', $id)->delete();
        }
        Schema::table('bs_budget_allocations', function (Blueprint $t): void {
            $t->dropColumn('approval_id');
        });
        Schema::dropIfExists('bs_ap_budget_requests');
    }
};
