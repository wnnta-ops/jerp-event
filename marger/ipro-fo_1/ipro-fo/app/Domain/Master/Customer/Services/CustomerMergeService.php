<?php

declare(strict_types=1);

namespace App\Domain\Master\Customer\Services;

use App\Domain\Master\Customer\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gabung Customer (eProperty: CustomerGabung / "Gabung Nomor Customer").
 * Memindahkan SELURUH referensi (kontrak/booking/dll) dari Customer A → B,
 * lalu menonaktifkan (soft delete) Customer A.
 */
class CustomerMergeService
{
    /** Tabel yang TIDAK boleh ikut di-remap walau punya kolom customer_id. */
    private const SKIP_TABLES = ['customers'];

    /**
     * Hitung referensi customer di seluruh tabel (untuk preview).
     *
     * @return array<string,int>
     */
    public function references(Customer $customer): array
    {
        $out = [];
        foreach ($this->customerTables() as $table) {
            $n = DB::table($table)->where('customer_id', $customer->id)->count();
            if ($n > 0) {
                $out[$table] = $n;
            }
        }

        return $out;
    }

    /**
     * Gabungkan A ke B. Mengembalikan ringkasan jumlah baris yang dipindah per tabel.
     *
     * @return array<string,int>
     */
    public function merge(Customer $source, Customer $target, int $userId): array
    {
        abort_if($source->id === $target->id, 422, 'Customer A dan B tidak boleh sama.');

        return DB::transaction(function () use ($source, $target, $userId) {
            $moved = [];
            foreach ($this->customerTables() as $table) {
                $n = DB::table($table)->where('customer_id', $source->id)->update(['customer_id' => $target->id]);
                if ($n > 0) {
                    $moved[$table] = $n;
                }
            }

            // Nonaktifkan (soft delete) Customer A — jejak tercatat via HasAuditTrail.
            $source->delete();

            return $moved;
        });
    }

    /** @return array<int,string> tabel-tabel yang punya kolom customer_id. */
    private function customerTables(): array
    {
        return collect(Schema::getTableListing())
            // Buang prefix skema (mis. "main.bookings" → "bookings") untuk nama bersih.
            ->map(fn ($t) => str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t)
            ->reject(fn ($t) => in_array($t, self::SKIP_TABLES, true))
            ->filter(fn ($t) => Schema::hasColumn($t, 'customer_id'))
            ->values()->all();
    }
}
