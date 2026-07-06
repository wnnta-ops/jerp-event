<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_ap_refund_queue', function (Blueprint $table) {
            // Supplier (bs_suppliers.id) untuk baris GMC berkomponen — diisi dari FO
            // (per komponen), dipakai prefill dropdown supplier di Posting Invoice.
            $table->unsignedBigInteger('supplier_id')->nullable()->after('customer_name');
        });
    }

    public function down(): void
    {
        Schema::table('bs_ap_refund_queue', function (Blueprint $table) {
            $table->dropColumn('supplier_id');
        });
    }
};
