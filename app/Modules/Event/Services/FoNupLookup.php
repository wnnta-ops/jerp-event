<?php

namespace App\Modules\Event\Services;

use App\Modules\Event\Data\FoCustomerData;
use App\Modules\Event\Data\FoParticipantData;
use App\Modules\FO\Launching\Models\NupEvent;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Services\CustomerQrService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * SATU-SATUNYA pintu modul Event ke data FO. Hanya SELECT.
 *
 * Jangan pernah memanggil AktivasiNupService, PilihUnitService,
 * CustomerQrService::ensureCode() (menulis kode!) atau method FO lain yang
 * menulis, mengirim WA/email, atau mengubah status. Yang dipakai dari FO hanya
 * model (untuk query baca) dan konstanta CustomerQrService::PREFIX.
 *
 * Aturan pencarian meniru PilihUnitService::lookupByBarcode, dengan dua beda:
 *  - selalu disaring per event NUP (FO sendiri tidak);
 *  - QR customer mengembalikan SEMUA NUP customer itu di event, bukan satu.
 */
class FoNupLookup
{
    /** Kolom yang dibaca dari fo_nup_registrations. */
    private const KOLOM = [
        'id', 'tenant_id', 'nup_event_id', 'no_nup', 'customer_id', 'nama_pendaftar', 'phone',
        'verif_status', 'status', 'is_hold', 'tgl_check_in',
    ];

    /**
     * NUP yang BOLEH di-scan untuk payload ini di event NUP ini (0..n).
     *
     * - "CUST:<kode>" → hanya dicari sebagai kode customer, tak pernah sebagai No. NUP.
     * - Tanpa awalan (ketik manual) → No. NUP dulu, lalu kode customer.
     *
     * @return Collection<int, FoParticipantData>
     */
    public function findByScan(string $payload, int $nupEventId): Collection
    {
        return $this->allByScan($payload, $nupEventId)
            ->filter(fn (FoParticipantData $d) => $d->isEligible())
            ->values();
    }

    /**
     * Alasan findByScan() kosong, dalam bahasa yang tampil di HP scanner.
     * Null bila ternyata ada NUP yang eligible.
     */
    public function explainMiss(string $payload, int $nupEventId): ?string
    {
        $parsed = $this->parse($payload);
        if ($parsed === null) {
            return 'QR tidak dikenali';
        }

        $diEvent = $this->allByScan($payload, $nupEventId);
        if ($diEvent->isEmpty()) {
            return $this->existsAnywhere($parsed) ? 'Bukan peserta event ini' : 'Kode tidak ditemukan';
        }

        if ($diEvent->contains(fn (FoParticipantData $d) => $d->isEligible())) {
            return null;
        }

        // Beberapa NUP, semuanya tak eligible → sebut alasan yang paling "dekat" lolos.
        foreach (['NUP sedang di-hold', 'Belum aktivasi di pos 1', 'NUP belum disetujui'] as $alasan) {
            if ($diEvent->contains(fn (FoParticipantData $d) => $d->rejectionReason() === $alasan)) {
                return $alasan;
            }
        }

        return 'Kode tidak ditemukan';
    }

    /**
     * Event MANDIRI: customer untuk payload ini, atau null.
     *
     * - "CUST:<kode>" → hanya kode customer.
     * - Tanpa awalan → No. NUP dulu (event mana pun; diambil customer-nya), lalu kode customer.
     *
     * Tidak ada syarat APPROVED / aktivasi / hold — itu aturan event NUP.
     */
    public function findCustomerByScan(string $payload): ?FoCustomerData
    {
        $parsed = $this->parse($payload);
        if ($parsed === null) {
            return null;
        }
        [$jenis, $kode] = $parsed;

        $customer = null;
        $noNup = null;
        if ($jenis === 'nup') {
            $reg = NupRegistration::query()->select(['id', 'customer_id', 'no_nup'])
                ->where('no_nup', $kode)->whereNotNull('customer_id')->first();
            if ($reg) {
                $customer = $this->customer()->whereKey($reg->customer_id)->first();
                $noNup = $reg->no_nup;
            }
        }
        $customer ??= $this->customer()->where('code', $kode)->first();

        return $customer ? new FoCustomerData(
            customer_id: (int) $customer->id,
            customer_code: $customer->code,
            name: $customer->full_name,
            phone: $customer->mobile ?: $customer->phone,
            no_nup: $noNup,
        ) : null;
    }

    /** Pesan untuk findCustomerByScan() yang kosong. */
    public function explainCustomerMiss(string $payload): string
    {
        return $this->parse($payload) === null ? 'QR tidak dikenali' : 'Kode tidak ditemukan';
    }

    /**
     * NUP event ini yang sudah aktivasi (tgl_check_in terisi) sejak $since.
     * $since null = semuanya. Termasuk yang di-hold — hadir tetap hadir; scan-nya
     * yang ditolak.
     *
     * @return Collection<int, FoParticipantData>
     */
    public function checkedInSince(int $nupEventId, ?CarbonInterface $since): Collection
    {
        $q = $this->base()
            ->where('nup_event_id', $nupEventId)
            ->where('verif_status', NupRegistration::VERIF_APPROVED)
            ->whereNotNull('tgl_check_in')
            ->whereIn('status', ['CHECK_IN', 'PILIH_UNIT']);

        if ($since !== null) {
            $q->where('tgl_check_in', '>=', $since);
        }

        return $this->map($q->orderBy('tgl_check_in')->get());
    }

    /**
     * Dari $knownIds, mana yang aktivasinya sudah dibatalkan di FO
     * (tgl_check_in kembali null — AktivasiNupService::cancelAktivasi) atau
     * pendaftarannya sudah hilang.
     *
     * @param  Collection<int, int>|array<int, int>  $knownIds
     * @return Collection<int, int>
     */
    public function cancelledSince(int $nupEventId, Collection|array $knownIds): Collection
    {
        $ids = collect($knownIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        $masihAktif = NupRegistration::query()
            ->whereIn('id', $ids)
            ->where('nup_event_id', $nupEventId)
            ->whereNotNull('tgl_check_in')
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        return $ids->diff($masihAktif)->values();
    }

    /**
     * Potret terbaru sejumlah NUP (untuk memeriksa ulang hold/batal saat scan).
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, FoParticipantData>
     */
    public function findByIds(int $nupEventId, array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return $this->map($this->base()->where('nup_event_id', $nupEventId)->whereIn('id', $ids)->get());
    }

    /**
     * Pilihan event NUP untuk wizard setup.
     *
     * @return Collection<int, array{id:int, code:string, name:string, tgl_launching:string}>
     */
    public function nupEvents(): Collection
    {
        return NupEvent::query()
            ->select(['id', 'code', 'name', 'tgl_launching'])
            ->orderByDesc('tgl_launching')
            ->orderByDesc('id')
            ->get()
            ->map(fn (NupEvent $e) => [
                'id' => (int) $e->id,
                'code' => $e->code,
                'name' => $e->name,
                'tgl_launching' => $e->tgl_launching->toDateString(),
            ]);
    }

    /** @return array{id:int, code:string, name:string, tgl_launching:string}|null */
    public function nupEvent(int $id): ?array
    {
        return $this->nupEvents()->firstWhere('id', $id);
    }

    // ── internal ────────────────────────────────────────────────────────

    /**
     * Semua NUP (eligible atau tidak) di event ini yang cocok dengan payload.
     *
     * @return Collection<int, FoParticipantData>
     */
    private function allByScan(string $payload, int $nupEventId): Collection
    {
        $parsed = $this->parse($payload);
        if ($parsed === null) {
            return collect();
        }

        [$jenis, $kode] = $parsed;

        if ($jenis === 'nup') {
            $byNup = $this->base()->where('nup_event_id', $nupEventId)->where('no_nup', $kode)->get();
            if ($byNup->isNotEmpty()) {
                return $this->map($byNup);
            }
            // Bukan No. NUP di event ini — tapi kalau No. NUP itu ada di event lain,
            // jangan diteruskan ke pencarian kode customer (bisa kebetulan sama).
            if ($this->base()->where('no_nup', $kode)->exists()) {
                return collect();
            }
        }

        $customerIds = Customer::query()->where('code', $kode)->pluck('id');
        if ($customerIds->isEmpty()) {
            return collect();
        }

        return $this->map(
            $this->base()
                ->where('nup_event_id', $nupEventId)
                ->whereIn('customer_id', $customerIds)
                ->orderBy('no_nup')
                ->get()
        );
    }

    /**
     * @return array{0:'customer'|'nup', 1:string}|null
     */
    private function parse(string $payload): ?array
    {
        $kode = trim($payload);
        if ($kode === '' || mb_strlen($kode) > 100) {
            return null;
        }

        $prefix = CustomerQrService::PREFIX;
        if (str_starts_with(strtoupper($kode), $prefix)) {
            $kode = trim(substr($kode, strlen($prefix)));

            return $kode === '' ? null : ['customer', $kode];
        }

        return ['nup', $kode];
    }

    /** @param array{0:string,1:string} $parsed */
    private function existsAnywhere(array $parsed): bool
    {
        [$jenis, $kode] = $parsed;

        if ($jenis === 'nup' && $this->base()->where('no_nup', $kode)->exists()) {
            return true;
        }

        return Customer::query()->where('code', $kode)->exists();
    }

    /** @return Builder<Customer> */
    private function customer(): Builder
    {
        return Customer::query()->select(['id', 'code', 'full_name', 'phone', 'mobile']);
    }

    /** @return Builder<NupRegistration> */
    private function base(): Builder
    {
        return NupRegistration::query()
            ->select(self::KOLOM)
            ->with(['customer' => fn ($q) => $q->select(['id', 'code', 'full_name', 'phone', 'mobile'])]);
    }

    /**
     * @param  iterable<int, NupRegistration>  $rows
     * @return Collection<int, FoParticipantData>
     */
    private function map(iterable $rows): Collection
    {
        return collect($rows)->map(fn (NupRegistration $r) => new FoParticipantData(
            nup_registration_id: (int) $r->id,
            nup_event_id: (int) $r->nup_event_id,
            no_nup: $r->no_nup,
            customer_code: $r->customer?->code,
            customer_id: $r->customer_id ? (int) $r->customer_id : null,
            name: $r->customer?->full_name ?: $r->nama_pendaftar,
            phone: $r->customer?->mobile ?: ($r->customer?->phone ?: $r->phone),
            verif_status: $r->verif_status,
            status: $r->status,
            is_hold: (bool) $r->is_hold,
            tgl_check_in: $r->tgl_check_in,
        ))->values();
    }
}
