<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUSINESS SUPPORT — Cost of Good Sold (HPP unit terjual).
 * Saat unit/serah terima diakui: Dr Beban Pokok Penjualan / Cr Persediaan/CIP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bs_cogs_recognitions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('cogs_number', 48)->unique();
            $t->date('recognition_date');
            $t->string('reference')->nullable();              // no. booking / kode unit
            $t->string('description');
            $t->unsignedBigInteger('inventory_account_id');   // Persediaan/CIP dikredit
            $t->unsignedBigInteger('cogs_account_id');        // Beban Pokok Penjualan didebit
            $t->unsignedBigInteger('cost_center_id')->nullable();
            $t->decimal('amount', 20, 2);
            $t->unsignedBigInteger('journal_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_cogs_recognitions');
    }
};
