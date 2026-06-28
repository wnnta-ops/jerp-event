<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CG-1: Setup COGS — Lokasi, Kategori Budget (+component HPP & COA default = "Set Akun Budget"),
 * Set COA Jenis Unit, Ketentuan (akun generate HPP per project).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bs_cogs_locations')) {
            Schema::create('bs_cogs_locations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('project_id')->nullable();
                $table->string('code')->index();
                $table->string('name');
                $table->boolean('is_active')->default(true);
                $table->string('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('bs_cogs_categories')) {
            Schema::create('bs_cogs_categories', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('code')->index();
                $table->string('name');
                $table->string('component')->default('bangunan'); // tanah|bangunan|sarana|permit|tools|kpr|bphtb|lain
                $table->unsignedBigInteger('default_account_id')->nullable(); // "Set Akun Budget"
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('bs_cogs_unit_type_accounts')) {
            Schema::create('bs_cogs_unit_type_accounts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('project_id')->nullable();
                $table->string('unit_type');
                $table->unsignedBigInteger('account_id'); // COA persediaan unit per jenis
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('bs_cogs_provisions')) {
            Schema::create('bs_cogs_provisions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('project_id')->unique();
                $table->unsignedBigInteger('acc_uang_muka_id')->nullable();   // Uang Muka Konsumen
                $table->unsignedBigInteger('acc_pendapatan_id')->nullable();  // Pendapatan
                $table->unsignedBigInteger('acc_piutang_id')->nullable();     // Piutang Konsumen
                $table->unsignedBigInteger('acc_cip_id')->nullable();         // CIP / pool biaya
                $table->unsignedBigInteger('acc_persediaan_unit_id')->nullable(); // Persediaan Unit
                $table->unsignedBigInteger('acc_hpp_id')->nullable();         // Beban Pokok Penjualan
                $table->string('basis')->default('price');                    // dasar alokasi: price|equal|luas
                $table->string('notes')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_cogs_provisions');
        Schema::dropIfExists('bs_cogs_unit_type_accounts');
        Schema::dropIfExists('bs_cogs_categories');
        Schema::dropIfExists('bs_cogs_locations');
    }
};
