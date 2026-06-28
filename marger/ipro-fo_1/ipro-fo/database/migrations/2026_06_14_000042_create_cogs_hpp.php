<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CG-4/CG-5: HPP RealCost per unit (metode persentase penyelesaian / POC) + posting 2 langkah.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bs_cogs_hpp')) {
            return;
        }
        Schema::create('bs_cogs_hpp', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('cogs_id')->nullable();           // CogsID
            $table->integer('period_year');
            $table->integer('period_month');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->string('unit_code')->nullable();
            $table->string('customer')->nullable();
            $table->string('no_kontrak')->nullable();
            $table->decimal('luas_tanah', 14, 2)->default(0);
            $table->decimal('luas_bangunan', 14, 2)->default(0);
            $table->decimal('dpp', 20, 2)->default(0);
            $table->decimal('nilai_kontrak', 20, 2)->default(0);
            $table->decimal('jumlah_pembayaran', 20, 2)->default(0);
            $table->decimal('persen_pelunasan', 8, 2)->default(0);
            $table->decimal('promo', 20, 2)->default(0);
            $table->decimal('blt', 20, 2)->default(0);
            $table->decimal('komisi', 20, 2)->default(0);
            // 7 komponen HPP
            $table->decimal('hpp_tanah', 20, 2)->default(0);
            $table->decimal('hpp_bangunan', 20, 2)->default(0);
            $table->decimal('hpp_sarana', 20, 2)->default(0);
            $table->decimal('hpp_permit', 20, 2)->default(0);
            $table->decimal('hpp_tools', 20, 2)->default(0);
            $table->decimal('hpp_kpr', 20, 2)->default(0);
            $table->decimal('hpp_bphtb', 20, 2)->default(0);
            $table->decimal('total_hpp', 20, 2)->default(0);
            $table->decimal('progress_pct', 8, 2)->default(0);
            // pengakuan (POC)
            $table->decimal('revenue_recognized', 20, 2)->default(0); // pengakuan pendapatan saat ini
            $table->decimal('hpp_recognized', 20, 2)->default(0);     // pengakuan HPP saat ini
            $table->decimal('revenue_accum', 20, 2)->default(0);
            $table->decimal('hpp_accum', 20, 2)->default(0);
            $table->string('status')->default('generated');           // generated|posted_persediaan|posted_hpp
            $table->unsignedBigInteger('journal_persediaan_id')->nullable();
            $table->unsignedBigInteger('journal_hpp_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'project_id', 'period_year', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_cogs_hpp');
    }
};
