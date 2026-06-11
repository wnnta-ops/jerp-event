<x-filament-panels::page>
    <div class="rounded-xl border p-4" style="border-color: rgb(148 163 184 / .25);">
        <div class="text-xs uppercase tracking-wide text-gray-400">Pendaftar Booking</div>
        <div class="mt-1 text-lg font-semibold">{{ $this->registration?->nama_pendaftar }}</div>
        <div class="mt-1 text-xs text-gray-500">
            Unit diinginkan customer: <strong>{{ $this->registration?->pilihan_unit_1 ?: '—' }}</strong>
            · Booking fee: <strong>Rp {{ number_format((float) ($this->registration?->booking_fee_paid ?? 0), 0, ',', '.') }}</strong>
        </div>
        <div class="mt-2 text-xs text-primary-500">Pilih <strong>cluster</strong> & <strong>tahap</strong>, lalu klik unit <strong>hijau (Tersedia)</strong> pada tabel stok.</div>
    </div>

    {{-- Pilih cluster + tahap --}}
    <div class="flex flex-wrap items-end gap-4">
        <div>
            <label class="mb-1 block text-sm font-medium">Cluster (Lokasi)</label>
            <select wire:model.live="lokasiId" class="rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-700" style="min-width:220px;padding:.5rem .75rem;">
                <option value="">— Pilih cluster —</option>
                @foreach ($this->clusterOptions($this->projectId) as $id => $nama)
                    <option value="{{ $id }}">{{ $nama }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium">Tahap</label>
            <select wire:model.live="tahap" class="rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-700" style="min-width:140px;padding:.5rem .75rem;">
                <option value="">— Semua tahap —</option>
                @foreach ($this->tahapOptions($this->projectId, $this->lokasiId) as $t => $lbl)
                    <option value="{{ $t }}">{{ $lbl }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Tabel stok visual --}}
    <div class="rounded-xl border p-4" style="border-color: rgb(148 163 184 / .2);">
        @include('filament.fo.partials.stock-grid', ['grid' => $this->getGrid()])
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
