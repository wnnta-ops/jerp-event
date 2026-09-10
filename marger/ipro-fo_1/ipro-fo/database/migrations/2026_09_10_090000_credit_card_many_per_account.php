<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SATU AKUN GL BOLEH DIPAKAI BEBERAPA KARTU KREDIT/VA.
 *
 * KENAPA DULU DIBATASI SATU. Saldo kartu dulu dibandingkan dengan MUTASI AKUNNYA di buku besar:
 * pembayaran Debit VA mendebit akun kartu, realisasinya mengkredit akun itu, dan "Pemakaian"
 * kartu dibaca dari SUM(credit) akun tersebut. Dengan dua kartu berakun sama, satu angka di buku
 * besar tidak lagi bisa dibagi ke kartu mana — karena itu akun dikunci satu kartu.
 *
 * KENAPA SEKARANG TIDAK PERLU LAGI. Sejak pembayaran Debit VA tidak berjurnal, akun kartu tidak
 * bermutasi sama sekali. Keempat angka kartu (Saldo Awal · Penambahan · Pemakaian · Sisa Saldo)
 * dihitung per KARTU dari dokumennya sendiri — pembayaran bon yang menunjuk credit_card_id, dan
 * baris Alokasi Kasbon yang juga menunjuk credit_card_id. Tidak ada satu pun angka kartu yang
 * dibagi dari akunnya, jadi beberapa kartu boleh berbagi satu akun GL tanpa menjadi ambigu.
 *
 * Yang menggantikan pembedanya adalah NOMOR KARTU — lihat CreditCardController::store(). Akun GL
 * tetap wajib: ia menentukan rekening bank mana yang menaungi VA itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bs_credit_cards')) {
            return;
        }

        foreach ($this->namaIndeksUnik() as $nama) {
            // Dilepas lewat SQL mentah, bukan dropUnique(): nama indeksnya bisa berbeda antar
            // basis data yang lahir dari migrasi berbeda, jadi dibaca dulu dari skemanya.
            DB::statement("ALTER TABLE `bs_credit_cards` DROP INDEX `{$nama}`");
        }

        // Penggantinya indeks BIASA — pencarian kartu per akun tetap cepat, tanpa mengunci
        // jumlahnya menjadi satu.
        if (! $this->punyaIndeks('bs_cc_tenant_account_idx')) {
            Schema::table('bs_credit_cards', function ($t): void {
                $t->index(['tenant_id', 'account_id'], 'bs_cc_tenant_account_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('bs_credit_cards')) {
            return;
        }

        if ($this->punyaIndeks('bs_cc_tenant_account_idx')) {
            DB::statement('ALTER TABLE `bs_credit_cards` DROP INDEX `bs_cc_tenant_account_idx`');
        }

        /* Keunikannya hanya dipasang kembali bila datanya memang masih memungkinkan. Sesudah
           beberapa kartu berbagi satu akun, memaksakan unique akan menggagalkan rollback di
           tengah jalan dan meninggalkan skema separuh jadi. */
        $bentrok = DB::table('bs_credit_cards')->whereNull('deleted_at')
            ->selectRaw('tenant_id, account_id, COUNT(*) c')->groupBy('tenant_id', 'account_id')
            ->havingRaw('COUNT(*) > 1')->exists();
        if (! $bentrok) {
            Schema::table('bs_credit_cards', function ($t): void {
                $t->unique(['tenant_id', 'account_id']);
            });
        }
    }

    /** @return array<int,string> nama indeks UNIK yang memuat account_id */
    private function namaIndeksUnik(): array
    {
        $rows = DB::select('SHOW INDEX FROM `bs_credit_cards`');
        $unik = [];
        foreach ($rows as $r) {
            $arr = (array) $r;
            if ((int) ($arr['Non_unique'] ?? 1) === 0 && ($arr['Column_name'] ?? '') === 'account_id') {
                $unik[] = (string) $arr['Key_name'];
            }
        }

        return array_values(array_unique($unik));
    }

    private function punyaIndeks(string $nama): bool
    {
        return collect(DB::select('SHOW INDEX FROM `bs_credit_cards`'))
            ->contains(fn ($r) => ((array) $r)['Key_name'] === $nama);
    }
};
