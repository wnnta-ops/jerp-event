<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lebarkan kolom bs_fixed_assets.name (varchar(255) → TEXT). Nama aset legacy Java Core
 * kadang berupa deskripsi panjang gabungan beberapa item (mis. set meubel kantor) yang
 * melebihi 255 karakter → upload massal gagal "Data too long for column 'name'".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bs_fixed_assets', 'name')) {
            return;
        }
        // Pakai raw agar tak butuh doctrine/dbal; TEXT menampung panjang berapa pun.
        DB::statement('ALTER TABLE `bs_fixed_assets` MODIFY `name` TEXT NOT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bs_fixed_assets', 'name')) {
            return;
        }
        DB::statement('ALTER TABLE `bs_fixed_assets` MODIFY `name` VARCHAR(255) NOT NULL');
    }
};
