<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Domain\Foundation\Tenant\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chart of Account riil eProperty (6 level) + saldo awal pada akun nyata.
 * Idempoten: reset destruktif (hapus CoA mainan + demo GL lama) hanya saat
 * migrasi pertama ke skema kode eProperty.
 */
class EpropertyCoaSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::whereRaw('LOWER(code)=?', ['mup'])->first() ?? Tenant::orderBy('id')->first();
        if (! $tenant) {
            return;
        }
        app()->instance('tenant.id', $tenant->id);

        $canonical = '10101010101'; // KAS OPERASIONAL — penanda CoA eProperty sudah terpasang
        $needsReset = ! BsAccount::where('code', $canonical)->exists();

        if ($needsReset) {
            $this->wipeGlDemo();
        }

        $rows = require database_path('data/eproperty_coa.php');

        foreach ($rows as $row) {
            [$code, $name] = $row;
            $len   = strlen($code);
            $level = intdiv($len + 1, 2);
            // Elemen ke-3 (opsional) menimpa tipe turunan — dipakai subtree 601 PENDAPATAN
            // LAIN-LAIN yang berada di head "6" (BEBAN). Lihat catatan di data/eproperty_coa.php.
            $type  = $row[2] ?? match ($code[0]) {
                '1' => 'asset', '2' => 'liability', '3', '8' => 'equity',
                '4' => 'income', '5', '6', '7' => 'expense', default => 'asset',
            };
            BsAccount::updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $code],
                [
                    'name'           => $name,
                    'category_code'  => BsAccount::categoryOf($code),
                    'type'           => $type,
                    'level'          => $level,
                    'parent_code'    => $len > 1 ? substr($code, 0, $len - 2) : null,
                    'normal_balance' => in_array($type, ['asset', 'expense'], true) ? 'D' : 'K',
                    'is_postable'    => $level === 6,
                    'is_active'      => true,
                ],
            );
        }

        // Catatan: blok pembersih lama (hapus 601* dan 30102*/30103*) DIHAPUS —
        // COA resmi eProperty (8 file .xls) kini memuat 601 PENDAPATAN LAIN-LAIN
        // (di head 6) serta 30102/30103 L/R di Kategori 3 (MODAL), sehingga akun
        // tersebut wajib dipertahankan, bukan dihapus.

        // Hubungkan parent_id berdasarkan parent_code.
        $byCode = BsAccount::where('tenant_id', $tenant->id)->pluck('id', 'code');
        foreach (BsAccount::where('tenant_id', $tenant->id)->whereNotNull('parent_code')->get(['id', 'parent_code', 'parent_id']) as $acc) {
            $pid = $byCode[$acc->parent_code] ?? null;
            if ($pid && $acc->parent_id !== $pid) {
                $acc->update(['parent_id' => $pid]);
            }
        }

        $this->seedOpeningBalances($tenant->id);
    }

    /** Hapus CoA lama + seluruh demo GL/AP/AR/stok agar buku besar bersih di atas CoA riil. */
    private function wipeGlDemo(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ([
            'bs_journal_lines', 'bs_journals',
            'bs_ap_payments', 'bs_ap_invoices', 'bs_ar_payments', 'bs_ar_invoices',
            'bs_stock_movements', 'bs_stock_transaction_lines', 'bs_stock_transactions',
            'bs_accounts',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
        Schema::enableForeignKeyConstraints();
    }

    private function seedOpeningBalances(int $tenantId): void
    {
        if (BsJournal::count() > 0) {
            return; // sudah ada jurnal
        }
        $acc = fn (string $code) => BsAccount::where('tenant_id', $tenantId)->where('code', $code)->value('id');
        $svc = app(JournalService::class);
        $year = (int) now()->year;

        // Saldo Awal (neraca pembuka) pada akun riil.
        $svc->post(
            ['journal_date' => "{$year}-01-01", 'source' => 'saldo_awal', 'description' => 'Saldo Awal Tahun '.$year],
            [
                ['account_id' => $acc('10101010101'), 'debit' => 100_000_000, 'credit' => 0, 'description' => 'Kas Operasional'],
                ['account_id' => $acc('10101020204'), 'debit' => 2_000_000_000, 'credit' => 0, 'description' => 'BCA 6155428914'],
                ['account_id' => $acc('10104010101'), 'debit' => 50_000_000, 'credit' => 0, 'description' => 'Bangunan Siap Jual'],
                ['account_id' => $acc('20201010101'), 'debit' => 0, 'credit' => 500_000_000, 'description' => 'Utang Bank Jk Panjang - BCA'],
                ['account_id' => $acc('30101010101'), 'debit' => 0, 'credit' => 1_650_000_000, 'description' => 'Modal Ditempatkan/Disetor'],
            ],
        );

        // Contoh penjualan unit (memunculkan pendapatan untuk Laba Rugi).
        $svc->post(
            ['journal_date' => now()->toDateString(), 'source' => 'memorial', 'description' => 'Penjualan unit (contoh)'],
            [
                ['account_id' => $acc('10101020204'), 'debit' => 943_500_000, 'credit' => 0, 'description' => 'Terima pembayaran'],
                ['account_id' => $acc('40101010101'), 'debit' => 0, 'credit' => 850_000_000, 'description' => 'Penjualan usaha'],
                ['account_id' => $acc('20104040406'), 'debit' => 0, 'credit' => 93_500_000, 'description' => 'PPN Keluaran'],
            ],
        );
    }
}
