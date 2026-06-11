<x-filament-panels::page>
    <div class="rounded-xl border p-4" style="border-color: rgb(229 231 235 / .25);">
        <div class="text-xs uppercase tracking-wide text-gray-400">Pendaftar Booking</div>
        <div class="mt-1 text-lg font-semibold">{{ $this->registration?->nama_pendaftar }}</div>
        <div class="mt-1 text-xs text-gray-500">
            Unit diinginkan customer: <strong>{{ $this->registration?->pilihan_unit_1 ?: '—' }}</strong>
            · Booking fee: <strong>Rp {{ number_format((float) ($this->registration?->booking_fee_paid ?? 0), 0, ',', '.') }}</strong>
        </div>
        <div class="mt-2 text-xs text-primary-500">Pilih <strong>cluster</strong> dulu, lalu klik <strong>Pilih Unit Ini</strong> pada unit yang dimaksud.</div>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
