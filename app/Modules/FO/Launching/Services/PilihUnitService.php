<?php

namespace App\Modules\FO\Launching\Services;

use App\Events\UnitStatusChanged;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Reservasi;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Services\Numerator\NumeratorServiceInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pilih Unit — hari-H launching. NUP yang sudah Aktivasi (status=CHECK_IN)
 * scan barcode mereka → operator klik unit di siteplan → atomic lock unit +
 * create Reservasi BOOKING + update NupRegistration status=PILIH_UNIT.
 *
 * Atomic lock dipakai untuk mencegah double-booking (RULE-06) ketika 2 operator
 * di-2-meja klik unit yang sama: hanya yang pertama yang sukses.
 */
class PilihUnitService
{
    public function __construct(
        private readonly NumeratorServiceInterface $numerator,
    ) {}

    /**
     * Lookup NupRegistration dari isi barcode/QR. Mendukung dua format:
     *  1. `no_nup` (mis. "NUP-00001") — barcode NUP langsung.
     *  2. KODE customer (mis. "CUST-000123") — QR Master Customer → ambil
     *     pendaftaran NUP terakhir yang sudah APPROVED milik customer tsb.
     */
    public function lookupByBarcode(string $barcode): NupRegistration
    {
        $code = trim($barcode);
        if ($code === '') {
            throw new RuntimeException('Barcode kosong.');
        }

        $reg = NupRegistration::with(['customer', 'event', 'projectPref'])
            ->where('no_nup', $code)
            ->first();

        // Bukan no_nup → coba sebagai kode customer (QR Master Customer).
        if (! $reg) {
            $customer = Customer::query()->where('code', $code)->first();
            if ($customer) {
                $reg = NupRegistration::with(['customer', 'event', 'projectPref'])
                    ->where('customer_id', $customer->id)
                    ->where('verif_status', NupRegistration::VERIF_APPROVED)
                    ->orderByDesc('id')
                    ->first();
                if (! $reg) {
                    throw new RuntimeException("Customer {$code} belum punya NUP yang APPROVED.");
                }
            }
        }

        if (! $reg) {
            throw new RuntimeException("Barcode '{$code}' tidak ditemukan.");
        }

        if ($reg->verif_status !== NupRegistration::VERIF_APPROVED) {
            throw new RuntimeException("NUP {$code} belum APPROVED ({$reg->verif_status}).");
        }

        if ($reg->status !== 'CHECK_IN') {
            throw new RuntimeException("NUP {$code} belum Aktivasi (status saat ini: {$reg->status}). Lakukan Aktivasi NUP dulu.");
        }

        if ($reg->is_hold) {
            throw new RuntimeException("NUP {$code} sedang HOLD. Buka hold dulu di menu Aktivasi NUP.");
        }

        return $reg;
    }

    /**
     * Eksekusi pemilihan unit. Atomic: hanya operator pertama yang sukses
     * untuk unit yang sama. Throw kalau unit sudah BOOKING/TERJUAL.
     */
    public function pickUnit(NupRegistration $reg, int $unitId): array
    {
        $result = DB::transaction(function () use ($reg, $unitId) {
            // 1) Lock baris unit + cek status saat ini
            $unit = Unit::lockForUpdate()->find($unitId);
            if (! $unit) {
                throw new RuntimeException('Unit tidak ditemukan.');
            }
            if (! $unit->is_active) {
                throw new RuntimeException('Unit sedang di-hold internal (nonaktif). Pilih unit lain.');
            }
            if ($unit->status !== 'TERSEDIA') {
                throw new RuntimeException(
                    'Unit '.trim(($unit->blok ? $unit->blok.' / ' : '').$unit->no_unit).
                    ' sudah '.$unit->status.'. Pilih unit lain.'
                );
            }

            // 2) Set BOOKING (atomic) + audit
            $unit->update(['status' => 'BOOKING']);

            // 3) Buat Reservasi BOOKING + tarik dari NUP
            $reservasi = Reservasi::create([
                'tenant_id' => $reg->tenant_id,
                'no_reservasi' => $this->numerator->next('RSV'),
                'customer_id' => $reg->customer_id,
                'unit_id' => $unit->id,
                'tgl_reservasi' => now()->toDateString(),
                'tgl_kadaluarsa' => now()->addDays(7)->toDateString(),
                'harga_jual' => $unit->harga_jual,
                'booking_fee' => $reg->booking_fee_paid ?? 0,
                'status' => 'AKTIF',
                'keterangan' => 'Dari NUP '.$reg->no_nup.' — Pilih Unit hari-H Launching',
            ]);

            // 4) Update NupRegistration: status PILIH_UNIT + link unit + reservasi
            $reg->update([
                'status' => 'PILIH_UNIT',
                'unit_id' => $unit->id,
                'reservasi_id' => $reservasi->id,
            ]);

            return [
                'registration' => $reg->refresh(),
                'unit' => $unit->refresh(),
                'reservasi' => $reservasi->refresh(),
                // Format "Lokasi Blok Nomer" (mis. "JRK3 XVI 18") — jelas cluster +
                // blok + nomer. Bagian kosong dilewati supaya tak ada spasi ganda.
                'unit_label' => trim(implode(' ', array_filter([$unit->lokasi?->kode, $unit->blok, $unit->no_unit]))) ?: $unit->label,
            ];
        });

        // Broadcast SETELAH commit — live map launching + site plan publik update
        // <1s tanpa refresh. Status TERSEDIA → BOOKING.
        event(UnitStatusChanged::fromUnit($result['unit'], 'BOOKING', 'TERSEDIA'));

        return $result;
    }
}
