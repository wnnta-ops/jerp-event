<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * COA laba/rugi pelepasan aset tetap + perbaikan tipe subtree 601.
 *
 * 1) Tambah 3 akun Detil Sub Transaksi (level 6):
 *      60101010104 LABA PENJUALAN ASET TETAP      (income, K)  — di bawah 601 PENDAPATAN LAIN-LAIN
 *      70101010114 RUGI PENJUALAN ASET TETAP      (expense, D) — di bawah 701 BEBAN LAIN-LAIN
 *      70101010115 RUGI PENGHAPUSAN ASET TETAP    (expense, D) — di bawah 701 BEBAN LAIN-LAIN
 *
 * 2) Perbaiki tipe subtree 601: seeder menurunkan tipe dari digit pertama kode ('6' => beban),
 *    padahal 601 adalah PENDAPATAN LAIN-LAIN. Akibatnya akun pendapatan tersimpan bertipe
 *    'expense' bersaldo normal 'D' → tampil di sisi beban pada Laba Rugi dan salah arah saat
 *    tutup buku (keduanya memfilter berdasarkan kolom type + normal_balance). Subtree 601 belum
 *    pernah tersentuh jurnal sama sekali, sehingga perbaikan ini tidak mengubah angka historis.
 *
 * KENAPA MIGRASI *DAN* SEEDER: seeder (database/data/eproperty_coa.php) hanya jalan pada
 * instalasi baru / db:seed. Database yang sudah berjalan tidak akan pernah membacanya, jadi
 * penambahan akun untuk instalasi berjalan harus lewat migrasi. Keduanya sengaja dibuat
 * menghasilkan baris yang sama persis.
 *
 * PELAJARAN dari 2026_06_03_000070_fixed_asset_disposal.php: migrasi itu MENCOBA membuat akun
 * laba/rugi tetapi diam-diam tidak melakukan apa pun — ia jalan di batch 1, SEBELUM seeder
 * mengisi COA, lalu keluar lewat guard "parent tidak ada". Akibatnya config menunjuk akun yang
 * tidak pernah lahir. Karena itu migrasi ini TIDAK bergantung pada parent: baris dibuat dengan
 * parent_code (yang memang otoritatif), sedangkan parent_id diisi bila parent-nya sudah ada —
 * dan pada instalasi baru seeder-lah yang membentuk semuanya dari data file.
 */
return new class extends Migration
{
    /** @var list<array{0:string,1:string,2:string}> kode, nama, tipe */
    private const ACCOUNTS = [
        ['60101010104', 'LABA PENJUALAN ASET TETAP', 'income'],
        ['70101010114', 'RUGI PENJUALAN ASET TETAP', 'expense'],
        ['70101010115', 'RUGI PENGHAPUSAN ASET TETAP', 'expense'],
    ];

    /** Subtree PENDAPATAN LAIN-LAIN yang tipenya salah turun dari digit '6'. */
    private const INCOME_FIX = [
        '601', '60101', '6010101', '601010101',
        '60101010101', '60101010102', '60101010103',
    ];

    public function up(): void
    {
        foreach (DB::table('bs_accounts')->distinct()->pluck('tenant_id') as $tenantId) {
            $this->seedFor((int) $tenantId);
        }
    }

    private function seedFor(int $tenantId): void
    {
        $now = now();

        foreach (self::ACCOUNTS as [$code, $name, $type]) {
            $parentCode = substr($code, 0, strlen($code) - 2);
            $payload = [
                'name'           => $name,
                'type'           => $type,
                'level'          => 6,
                'parent_code'    => $parentCode,
                // category_code = digit pertama, konsisten dgn BsAccount::categoryOf untuk 6xx/7xx.
                'category_code'  => $code[0],
                'normal_balance' => $type === 'expense' ? 'D' : 'K',
                'is_postable'    => 1,
                'is_active'      => 1,
                'updated_at'     => $now,
            ];
            // parent_id hanya diisi bila parent-nya memang sudah ada; TIDAK dijadikan syarat
            // (justru itu yang membuat migrasi terdahulu gagal senyap).
            $parentId = DB::table('bs_accounts')->where('tenant_id', $tenantId)->where('code', $parentCode)->value('id');
            if ($parentId) {
                $payload['parent_id'] = $parentId;
            }

            $exists = DB::table('bs_accounts')->where('tenant_id', $tenantId)->where('code', $code)->exists();
            if ($exists) {
                DB::table('bs_accounts')->where('tenant_id', $tenantId)->where('code', $code)->update($payload);
            } else {
                DB::table('bs_accounts')->insert($payload + [
                    'tenant_id'  => $tenantId,
                    'code'       => $code,
                    'created_at' => $now,
                ]);
            }
        }

        // Pendapatan Lain-lain: beban → pendapatan (saldo normal ikut D → K).
        DB::table('bs_accounts')
            ->where('tenant_id', $tenantId)
            ->whereIn('code', self::INCOME_FIX)
            ->where('type', 'expense')
            ->update(['type' => 'income', 'normal_balance' => 'K', 'updated_at' => $now]);
    }

    public function down(): void
    {
        // Hapus hanya bila belum terpakai di jurnal (aman) — mengikuti pola
        // 2026_06_24_000020_create_outgoing_cheque_liability_account.
        //
        // TANPA penjagaan ini akan merusak data secara PERMANEN & SENYAP: tidak ada foreign key
        // dari bs_journal_lines ke bs_accounts, jadi penghapusan lolos begitu saja; barisnya
        // menjadi yatim sehingga nilainya HILANG dari Laba Rugi & tutup buku (keduanya nge-join
        // ke bs_accounts) padahal jurnalnya sendiri tetap balance — Neraca dan Laba Rugi jadi
        // tidak nyambung. Menjalankan ulang migrasi TIDAK menyembuhkan: akunnya lahir dengan id
        // baru, sedangkan bs_journal_lines masih menunjuk id lama.
        foreach (DB::table('bs_accounts')->whereIn('code', array_column(self::ACCOUNTS, 0))->pluck('id') as $id) {
            if (! DB::table('bs_journal_lines')->where('account_id', $id)->exists()) {
                DB::table('bs_accounts')->where('id', $id)->delete();
            }
        }

        // Kembalikan tipe subtree 601 seperti semula (turunan digit '6' = beban).
        DB::table('bs_accounts')
            ->whereIn('code', self::INCOME_FIX)
            ->where('type', 'income')
            ->update(['type' => 'expense', 'normal_balance' => 'D', 'updated_at' => now()]);
    }
};
