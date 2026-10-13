<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsEmployee;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Domain\Master\Customer\Models\Customer;

/**
 * PENERIMA Reimburse Karyawan & Deposit Renovasi — karyawan / customer yang dicatat sebagai
 * baris master supplier berkategori khusus.
 *
 * ── KENAPA LEWAT MASTER SUPPLIER ──
 *
 * Seluruh alur sesudah registrasi (Kuitansi, Approval Pengeluaran Kas & Bank, pembayaran,
 * Aging & Kartu Hutang) berporos pada supplier_id. Mendaftarkan penerima sebagai supplier
 * membuat kedua modul baru menempuh alur yang SAMA PERSIS dengan Invoice Hutang tanpa satu
 * pun layar hilir yang perlu diubah — dan rekening tujuan transfernya ikut terbawa.
 *
 * Kodenya diturunkan dari id sumbernya (KRY-{id} / CST-{id}), jadi satu orang selalu satu
 * baris: registrasi berikutnya memperbarui nama & rekeningnya, bukan membuat baris kedua.
 * Kategorinya memisahkan mereka dari supplier sungguhan — lihat KATEGORI.
 */
final class PenerimaHutang
{
    /** Kategori supplier untuk penerima ini; disaring dari pilihan supplier Invoice Hutang. */
    public const KATEGORI = ['karyawan', 'customer'];

    public function karyawan(BsEmployee $emp): BsSupplier
    {
        return $this->simpan('KRY-'.$emp->id, 'karyawan', [
            'name' => $emp->name,
            'bank_name' => $emp->bank_name,
            'bank_account_no' => $emp->bank_account,
            'bank_account_name' => $emp->name,
        ]);
    }

    /** Rekening customer diketik di form — master Customer tidak menyimpan data bank. */
    public function customer(Customer $cust, ?string $bank, ?string $rekening, ?string $atasNama): BsSupplier
    {
        return $this->simpan('CST-'.$cust->id, 'customer', [
            'name' => $cust->full_name,
            'bank_name' => $bank,
            'bank_account_no' => $rekening,
            'bank_account_name' => $atasNama ?: $cust->full_name,
        ]);
    }

    /** @param  array<string,mixed>  $isi */
    private function simpan(string $kode, string $kategori, array $isi): BsSupplier
    {
        $s = BsSupplier::withTrashed()->firstOrNew(['code' => $kode]);
        $s->fill($isi + ['category' => $kategori, 'is_active' => true]);
        if ($s->trashed()) {
            $s->restore();
        }
        $s->save();

        return $s;
    }
}
