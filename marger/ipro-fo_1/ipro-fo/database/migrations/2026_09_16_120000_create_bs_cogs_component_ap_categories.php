<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Komponen COGS — tautan komponen HPP ke Kategori Invoice (Account Payable).
 *
 * Dua dari empat komponen (Tanah, License Permit) bersumber dari invoice hutang. Kategori
 * Invoice AP tidak punya kolom apa pun yang menyatakan "ini biaya tanah" — namanya memang
 * TANAH-C1, LICENSE-PERMIT-C1, dst., tetapi menebak dari nama berarti satu kategori baru
 * bernama "PEMBELIAN LAHAN" diam-diam tidak pernah terhitung. Karena itu tautannya DISIMPAN
 * eksplisit, dan dikelola dari sisi COGS supaya tabel milik modul Account Payable tidak
 * disentuh.
 *
 * Dua komponen lainnya (Housing, Infrastruktur) tidak butuh tabel ini: Surat Perintah Kerja
 * sudah membawa kolom jenis (bangunan / sarana_prasarana) yang labelnya memang "Housing" dan
 * "Infrastruktur".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bs_cogs_component_ap_categories')) {
            Schema::create('bs_cogs_component_ap_categories', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('tenant_id')->constrained();
                // Kunci komponen HPP yang SAMA dengan BsCogsCategory::COMPONENTS ('tanah',
                // 'permit'), bukan slug URL — supaya tautan ini bicara bahasa yang sama dengan
                // CogsService::generateHpp().
                $t->string('component', 20);
                $t->foreignId('ap_category_id')->constrained('bs_ap_categories')->cascadeOnDelete();
                $t->timestamps();
                // Satu kategori invoice hanya boleh jadi milik SATU komponen. Tanpa ini invoice
                // yang sama terhitung dua kali — sekali sebagai tanah, sekali sebagai perizinan.
                $t->unique(['tenant_id', 'ap_category_id'], 'bs_cogs_comp_ap_cat_unik');
                $t->index(['tenant_id', 'component']);
            });
        }

        // Izin dibuat di sini juga (selain GranularPermissionSeeder) karena server menjalankan
        // migrate, bukan seeder: tanpa ini menu Komponen COGS tidak muncul untuk siapa pun
        // selain SuperAdmin sampai seseorang ingat menjalankan seeder.
        foreach (['cogs.component.view', 'cogs.component.manage'] as $nama) {
            Permission::updateOrCreate(
                ['name' => $nama, 'guard_name' => 'web'],
                ['module' => 'cogs', 'label' => $nama === 'cogs.component.view' ? 'Component View' : 'Component Manage'],
            );
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_cogs_component_ap_categories');
        Permission::whereIn('name', ['cogs.component.view', 'cogs.component.manage'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
