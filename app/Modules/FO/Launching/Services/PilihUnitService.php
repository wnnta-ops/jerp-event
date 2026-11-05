<?php

namespace App\Modules\FO\Launching\Services;

use App\Events\UnitStatusChanged;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Reservasi;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Modules\FO\MarketingSales\Services\CustomerQrService;
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
    public function lookupByBarcode(string $barcode, ?int $lokasiId = null): NupRegistration
    {
        $code = trim($barcode);
        if ($code === '') {
            throw new RuntimeException('Barcode kosong.');
        }

        // QR Customer selalu berawalan "CUST:" → PASTI dicari sebagai kode customer,
        // tak pernah diadu dengan no_nup. Tanpa ini, kode customer berupa angka yang
        // kebetulan sama dengan sebuah No. NUP akan mengenali ORANG YANG SALAH.
        $kodeCustomer = null;
        if (str_starts_with(strtoupper($code), CustomerQrService::PREFIX)) {
            $kodeCustomer = trim(substr($code, strlen(CustomerQrService::PREFIX)));
            if ($kodeCustomer === '') {
                throw new RuntimeException('QR customer tidak valid (kode kosong).');
            }
        }

        $reg = $kodeCustomer !== null ? null : NupRegistration::with(['customer', 'event', 'projectPref'])
            ->where('no_nup', $code)
            ->first();

        // Bukan no_nup → coba sebagai kode customer (QR Master Customer).
        if (! $reg) {
            $cari = $kodeCustomer ?? $code;
            $customer = Customer::query()->where('code', $cari)->first();
            if ($kodeCustomer !== null && ! $customer) {
                throw new RuntimeException("Customer dengan kode {$kodeCustomer} tidak ditemukan.");
            }
            if ($customer) {
                $reg = NupRegistration::with(['customer', 'event', 'projectPref'])
                    ->where('customer_id', $customer->id)
                    ->where('verif_status', NupRegistration::VERIF_APPROVED)
                    ->orderByDesc('id')
                    ->first();
                if (! $reg) {
                    throw new RuntimeException("Customer {$cari} belum punya NUP yang APPROVED.");
                }
            }
        }

        if (! $reg) {
            throw new RuntimeException("Barcode '{$code}' tidak ditemukan.");
        }

        if ($reg->verif_status !== NupRegistration::VERIF_APPROVED) {
            throw new RuntimeException("NUP {$code} belum APPROVED ({$reg->verif_status}).");
        }

        // Sudah pilih unit → sebut unitnya (dulu jatuh ke pesan "belum Aktivasi" yang menyesatkan).
        if ($reg->status === 'PILIH_UNIT') {
            $unit = $reg->unit_id ? Unit::find($reg->unit_id) : null;
            $label = $unit ? ($unit->kode_unit ?: $unit->label) : '-';

            throw new RuntimeException("NUP {$reg->no_nup} sudah memilih unit {$label}. Lanjutkan di Closing NUP.");
        }

        if ($reg->status !== 'CHECK_IN') {
            throw new RuntimeException("NUP {$code} belum Aktivasi (status saat ini: {$reg->status}). Lakukan Aktivasi NUP dulu.");
        }

        if ($reg->is_hold) {
            throw new RuntimeException("NUP {$code} sedang HOLD. Buka hold dulu di menu Aktivasi NUP.");
        }

        $this->pastikanCluster($reg, $lokasiId);

        return $reg;
    }

    /**
     * Event NUP yang terikat cluster hanya boleh Pilih Unit di cluster itu.
     * Event tanpa cluster (data lama / berlaku semua) tidak dibatasi.
     */
    private function pastikanCluster(NupRegistration $reg, ?int $lokasiId): void
    {
        $event = $reg->event;
        if (! $event?->lokasi_id || ! $lokasiId || (int) $event->lokasi_id === $lokasiId) {
            return;
        }

        $cluster = $event->lokasi?->nama ?: ('#'.$event->lokasi_id);

        throw new RuntimeException("NUP {$reg->no_nup} terdaftar di event {$event->name} untuk cluster {$cluster}. Buka cluster tersebut untuk memilih unit.");
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
            // Benteng server: unit harus dari cluster event NUP (bila event terikat cluster).
            $this->pastikanCluster($reg, (int) $unit->lokasi_id);
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
                // No. Unit kanonik saja (mis. "JRK3-003-XVIII-12") — sama dgn yang
                // tampil di popup siteplan. JANGAN tempel kode lokasi di depan:
                // no_unit sudah memuat kode cluster → jadi dobel ("JRK3 JRK3-...").
                'unit_label' => $unit->kode_unit ?: $unit->label,
            ];
        });

        // Broadcast SETELAH commit — live map launching + site plan publik update
        // <1s tanpa refresh. Status TERSEDIA → BOOKING.
        event(UnitStatusChanged::fromUnit($result['unit'], 'BOOKING', 'TERSEDIA'));

        return $result;
    }
}
