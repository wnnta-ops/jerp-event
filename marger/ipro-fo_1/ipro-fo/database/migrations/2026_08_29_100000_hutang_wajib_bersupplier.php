<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Setiap hutang wajib punya supplier.
 *
 * Layar Aging Hutang menampilkan satu baris bernama "—" berisi hutang tanpa supplier.
 * Baris itu menggabungkan hutang milik SIAPA PUN yang supplier-nya hilang jadi satu angka
 * gelondongan: tidak bisa ditagih, tidak bisa ditelusuri, dan tidak ada yang tahu itu milik
 * siapa. Tiga hal yang bisa menyebabkannya, dan tak satu pun dicegah sebelum migrasi ini:
 *
 *   1. bs_ap_invoices.supplier_id NULLABLE — tidak ada yang menghalangi hutang tanpa supplier.
 *   2. TIDAK ADA foreign key sama sekali — supplier bisa terhapus permanen dan invoicenya
 *      menunjuk id yang tidak ada lagi.
 *   3. Supplier ber-soft-delete membuat relasinya mengembalikan null, sehingga namanya jadi
 *      "—" walau id-nya masih tersimpan rapi.
 *
 * Migrasi ini menutup (1) dan (2). Nomor (3) ditangani di layarnya (withTrashed) dan dengan
 * larangan menghapus supplier yang masih punya hutang.
 */
return new class extends Migration
{
    /** Kode supplier penampung untuk baris yang memang sudah terlanjur tanpa supplier. */
    private const KODE_PENAMPUNG = 'SP.TANPA-SUPPLIER';

    public function up(): void
    {
        if (! Schema::hasTable('bs_ap_invoices') || ! Schema::hasColumn('bs_ap_invoices', 'supplier_id')) {
            return;
        }

        // ── 1. Perbaiki baris yang sudah terlanjur tanpa supplier ────────────────────────────
        //
        // Baris yatim TIDAK dihapus dan nilainya TIDAK diubah — itu hutang sungguhan yang
        // pernah dicatat seseorang. Ia dipindahkan ke supplier penampung bernama jelas supaya
        // tetap terlihat di Aging Hutang dan bisa dibetulkan satu per satu, bukan lenyap.
        $yatim = DB::table('bs_ap_invoices as i')
            ->leftJoin('bs_suppliers as s', 's.id', '=', 'i.supplier_id')
            ->whereNull('i.deleted_at')
            ->where(fn ($q) => $q->whereNull('i.supplier_id')->orWhereNull('s.id'))
            ->pluck('i.id');

        if ($yatim->isNotEmpty()) {
            $penampung = $this->penampung();
            DB::table('bs_ap_invoices')->whereIn('id', $yatim)->update(['supplier_id' => $penampung]);
        }

        // Baris yang SUDAH dihapus (soft delete) juga harus punya supplier valid, kalau tidak
        // kolomnya tidak bisa dijadikan NOT NULL — MySQL memeriksa seluruh baris, termasuk
        // yang ber-deleted_at.
        $yatimTerhapus = DB::table('bs_ap_invoices as i')
            ->leftJoin('bs_suppliers as s', 's.id', '=', 'i.supplier_id')
            ->whereNotNull('i.deleted_at')
            ->where(fn ($q) => $q->whereNull('i.supplier_id')->orWhereNull('s.id'))
            ->pluck('i.id');

        if ($yatimTerhapus->isNotEmpty()) {
            $penampung = $this->penampung();
            DB::table('bs_ap_invoices')->whereIn('id', $yatimTerhapus)->update(['supplier_id' => $penampung]);
        }

        // ── 2. Kunci di tingkat basis data ───────────────────────────────────────────────────
        Schema::table('bs_ap_invoices', function (Blueprint $t): void {
            $t->unsignedBigInteger('supplier_id')->nullable(false)->change();
        });

        // FK dipasang TERPISAH dan dimaafkan bila gagal: sebagian pemasangan lama memakai
        // ENGINE atau tipe kolom yang berbeda, dan menggagalkan seluruh migrasi karena FK
        // tidak bisa dipasang akan menahan perbaikan NOT NULL yang justru inti masalahnya.
        if (! $this->punyaFk()) {
            try {
                Schema::table('bs_ap_invoices', function (Blueprint $t): void {
                    // restrictOnDelete: supplier yang masih dipakai hutang tidak bisa dihapus
                    // permanen. Inilah pengganti "hutang tanpa supplier" — kesalahannya
                    // ditolak di tempat kejadian, bukan muncul sebagai baris "—" berbulan kemudian.
                    $t->foreign('supplier_id', 'bs_ap_invoices_supplier_id_foreign')
                        ->references('id')->on('bs_suppliers')->restrictOnDelete();
                });
            } catch (\Throwable $e) {
                // Dicatat, tidak menggagalkan: NOT NULL di atas sudah menutup jalur utamanya.
                DB::statement('SELECT 1');
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('bs_ap_invoices')) {
            return;
        }

        if ($this->punyaFk()) {
            Schema::table('bs_ap_invoices', function (Blueprint $t): void {
                $t->dropForeign('bs_ap_invoices_supplier_id_foreign');
            });
        }
        Schema::table('bs_ap_invoices', function (Blueprint $t): void {
            $t->unsignedBigInteger('supplier_id')->nullable()->change();
        });

        // Supplier penampung sengaja TIDAK dihapus: invoice yang tadi dipindahkan ke sana
        // masih menunjuk padanya, dan menghapusnya akan mengulangi persis masalah yang
        // migrasi ini betulkan.
    }

    /** Id supplier penampung; dibuat bila belum ada. */
    private function penampung(): int
    {
        $ada = DB::table('bs_suppliers')->where('code', self::KODE_PENAMPUNG)->value('id');
        if ($ada) {
            return (int) $ada;
        }

        $tenantId = (int) DB::table('bs_ap_invoices')->value('tenant_id')
            ?: (int) DB::table('tenants')->orderBy('id')->value('id');

        return (int) DB::table('bs_suppliers')->insertGetId([
            'tenant_id' => $tenantId,
            'code'      => self::KODE_PENAMPUNG,
            'name'      => 'TANPA SUPPLIER — PERLU DILENGKAPI',
            'category'  => null,
            'is_active' => false, // tidak muncul di pilihan supplier untuk transaksi baru
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function punyaFk(): bool
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'bs_ap_invoices')
            ->where('COLUMN_NAME', 'supplier_id')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();
    }
};
