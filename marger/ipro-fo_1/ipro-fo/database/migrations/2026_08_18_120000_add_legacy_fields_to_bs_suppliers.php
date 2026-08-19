<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom tambahan bs_suppliers supaya Data Supplier dari software lama masuk UTUH.
 *
 * Berkas sumbernya punya 13 kolom, sementara tabel ini hanya menampung sebagian: nama
 * bisnis, nomor telepon kedua, faks, dan tiga alamat tambahan belum punya tempat. Tanpa
 * kolom-kolom ini, memindahkan datanya berarti membuang sebagian isinya diam-diam —
 * padahal seluruh gunanya justru supaya peralihan dari software lama tidak membingungkan.
 *
 * Semuanya nullable: 757 baris yang dipindahkan pun kebanyakan mengosongkannya, dan
 * supplier yang sudah ada tidak boleh terganggu.
 */
return new class extends Migration
{
    /** @var array<string, int> nama kolom => panjang varchar */
    private const KOLOM = [
        'business_name'    => 255,   // Nama Bisnis
        'phone2'           => 30,    // No. Telp (selain No. HP yang sudah ada di `phone`)
        'fax'              => 30,    // No. Fax
        'address_billing'  => 255,   // Alamat Penagihan
        'address_mail'     => 255,   // Alamat Surat Menyurat
        'address_identity' => 255,   // Alamat Identitas
    ];

    public function up(): void
    {
        Schema::table('bs_suppliers', function (Blueprint $table): void {
            // `address` yang sudah ada dipakai untuk Alamat Domisili — tidak diubah supaya
            // data & layar yang sudah memakainya tidak ikut berpindah arti.
            $sesudah = 'address';
            foreach (self::KOLOM as $nama => $panjang) {
                if (! Schema::hasColumn('bs_suppliers', $nama)) {
                    $table->string($nama, $panjang)->nullable()->after($sesudah);
                    $sesudah = $nama;
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('bs_suppliers', function (Blueprint $table): void {
            $ada = array_filter(array_keys(self::KOLOM), fn ($k) => Schema::hasColumn('bs_suppliers', $k));
            if ($ada) {
                $table->dropColumn(array_values($ada));
            }
        });
    }
};
