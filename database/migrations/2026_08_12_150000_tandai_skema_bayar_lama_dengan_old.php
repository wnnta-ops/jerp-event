<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Beri awalan "OLD" pada Skema Bayar yang sudah ada saat migrasi ini dijalankan.
 *
 * Migrasi data dari ePro ikut membawa SELURUH skema bayar lama. Di sistem baru
 * skema-skema itu tampil bercampur dengan skema baru, sehingga operator bisa
 * memilih skema lama tanpa sadar dan hitungan kontrak memakai aturan lama.
 *
 * FORWARD-ONLY: semua skema yang ADA PADA SAAT INI dianggap lama dan ditandai;
 * skema yang dibuat SESUDAH ini tidak tersentuh. Yang diubah HANYA kolom `name`
 * — angka, baris termin, keaktifan, dan kontrak yang memakainya dibiarkan apa
 * adanya, jadi tidak ada hitungan lama yang berubah.
 *
 * Idempoten: skema yang sudah berawalan "OLD " dilewati.
 */
return new class extends Migration
{
    private const PREFIX = 'OLD ';

    public function up(): void
    {
        $n = DB::table('fo_skema')
            ->where('name', 'not like', self::PREFIX.'%')
            ->update(['name' => DB::raw("CONCAT('".self::PREFIX."', name)")]);

        echo "  → {$n} skema bayar ditandai \"OLD\".\n";
    }

    public function down(): void
    {
        DB::table('fo_skema')
            ->where('name', 'like', self::PREFIX.'%')
            ->update(['name' => DB::raw('SUBSTRING(name, '.(strlen(self::PREFIX) + 1).')')]);
    }
};
