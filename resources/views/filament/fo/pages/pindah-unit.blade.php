<x-filament-panels::page>
    {{-- Info kontrak + unit lama --}}
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div class="rounded-xl border p-4" style="border-color: rgb(148 163 184 / .25);">
            <div class="text-xs uppercase tracking-wide text-gray-400">Kontrak</div>
            <div class="mt-1 grid grid-cols-2 gap-y-1 text-sm">
                <span class="text-gray-500">No. Kontrak</span><span class="font-semibold">{{ $this->kontrak?->no_kontrak }}</span>
                <span class="text-gray-500">Customer</span><span class="font-semibold">{{ $this->kontrak?->customer?->full_name ?? '—' }}</span>
                <span class="text-gray-500">Sales</span><span>{{ $this->kontrak?->agent?->name ?? '—' }}</span>
            </div>
        </div>
        <div class="rounded-xl border p-4" style="border-color: rgb(239 68 68 / .35);">
            <div class="text-xs uppercase tracking-wide text-gray-400">Unit Lama</div>
            <div class="mt-1 text-lg font-bold" style="color:#ef4444;">{{ $this->kontrak?->unit?->kode_unit ?? '—' }}</div>
            <div class="text-xs text-gray-500">Akan dikembalikan TERSEDIA setelah pindah.</div>
        </div>
    </div>

    {{-- Pilih cluster + tahap --}}
    <div class="flex flex-wrap items-end gap-4">
        <div>
            <label class="mb-1 block text-sm font-medium">Cluster (Lokasi)</label>
            <select wire:model.live="lokasiId" class="fi-input rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-700" style="min-width:220px;padding:.5rem .75rem;">
                <option value="">— Pilih cluster —</option>
                @foreach ($this->clusterOptions($this->projectId) as $id => $nama)
                    <option value="{{ $id }}">{{ $nama }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium">Tahap</label>
            <select wire:model.live="tahap" class="fi-input rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-700" style="min-width:140px;padding:.5rem .75rem;">
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
