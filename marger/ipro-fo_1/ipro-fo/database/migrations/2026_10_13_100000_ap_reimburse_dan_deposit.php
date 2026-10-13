<?php

declare(strict_types=1);

use App\Domain\BusinessSupport\Services\BoNumberService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Account Payable › Master: REIMBURSE BIAYA KARYAWAN dan DEPOSIT RENOVASI CUSTOMER.
 *
 * Keduanya BUKAN alur baru — alurnya sama persis dengan Invoice Hutang (registrasi → Memo
 * Internal + BPP → Kuitansi → Approval Kas & Bank → dibayar). Yang dibedakan hanya penerima
 * dan akun jurnalnya, jadi keduanya disimpan di bs_ap_invoices dengan penanda `jenis`:
 *
 *   invoice    Invoice Hutang ke supplier (bawaan — seluruh data lama)
 *   reimburse  pengganti uang pribadi karyawan
 *   deposit    pengembalian deposit renovasi customer
 *
 * Akunnya dari Kategori Invoice yang juga ber-`jenis`: user membuat kategori Reimburse /
 * Deposit sendiri dan mengisi akun debit & akun hutangnya di sana.
 *
 * Izin barunya lahir TANPA pemegang: modul baru, aksesnya diberikan admin lewat Grup Akses BO
 * (izin aksi + sakelar "Tampil di menu"). SuperAdmin tetap melihatnya.
 */
return new class extends Migration
{
    private const IZIN = [
        'ap.reimburse.view' => ['ap', 'Reimburse Karyawan — Lihat'],
        'ap.reimburse.create' => ['ap', 'Reimburse Karyawan — Buat'],
        'ap.deposit.view' => ['ap', 'Deposit Renovasi — Lihat'],
        'ap.deposit.create' => ['ap', 'Deposit Renovasi — Buat'],
        'menu.bs.ap.reimburse.index' => ['menu', 'Tampil: Generate Invoice › Reimburse Biaya Karyawan'],
        'menu.bs.ap.deposit.index' => ['menu', 'Tampil: Generate Invoice › Deposit Renovasi Customer'],
    ];

    public function up(): void
    {
        foreach (['bs_ap_invoices', 'bs_ap_categories'] as $tabel) {
            if (! Schema::hasColumn($tabel, 'jenis')) {
                Schema::table($tabel, function (Blueprint $t): void {
                    $t->string('jenis', 20)->default('invoice')->index();
                });
            }
        }

        foreach (self::IZIN as $nama => [$modul, $label]) {
            if (! DB::table('permissions')->where('name', $nama)->where('guard_name', 'web')->exists()) {
                DB::table('permissions')->insert([
                    'name' => $nama, 'guard_name' => 'web', 'module' => $modul, 'label' => $label,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Penomoran AP.RMB & AP.DEP — idempoten, baris yang sudah ada tidak disentuh.
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            BoNumberService::tanamMaster((int) $tenantId);
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::IZIN))->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['bs_ap_invoices', 'bs_ap_categories'] as $tabel) {
            if (Schema::hasColumn($tabel, 'jenis')) {
                Schema::table($tabel, function (Blueprint $t) use ($tabel): void {
                    $t->dropIndex($tabel.'_jenis_index');
                    $t->dropColumn('jenis');
                });
            }
        }
    }
};
