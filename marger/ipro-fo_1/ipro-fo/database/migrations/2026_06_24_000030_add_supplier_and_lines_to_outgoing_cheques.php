<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BG/Cek Keluar → referensi pelunasan hutang AP: supplier + beberapa invoice hutang.
 * Saat cek DISERAHKAN ke supplier, tiap baris invoice dilunasi (sinkron Invoice Hutang / Kartu Hutang).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_outgoing_cheques', function (Blueprint $table) {
            if (! Schema::hasColumn('bs_outgoing_cheques', 'supplier_id')) {
                $table->unsignedBigInteger('supplier_id')->nullable();
            }
        });

        if (! Schema::hasTable('bs_outgoing_cheque_lines')) {
            Schema::create('bs_outgoing_cheque_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('outgoing_cheque_id')->index();
                $table->unsignedBigInteger('ap_invoice_id');
                $table->decimal('amount', 20, 2)->default(0);
                $table->unsignedBigInteger('payment_id')->nullable(); // BsApPayment terbentuk saat handover
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_outgoing_cheque_lines');
        Schema::table('bs_outgoing_cheques', function (Blueprint $table) {
            if (Schema::hasColumn('bs_outgoing_cheques', 'supplier_id')) {
                $table->dropColumn('supplier_id');
            }
        });
    }
};
