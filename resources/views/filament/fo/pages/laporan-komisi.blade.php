<x-filament-panels::page>
    @php
        $report = $this->getReport();
        $inp = 'w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800';
        $lbl = 'mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300';
    @endphp

    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
            <div>
                <label class="{{ $lbl }}" for="tanggal">Berdasarkan</label>
                <select id="tanggal" wire:model.live="tanggal" class="{{ $inp }}">
                    <option value="pengajuan">Tanggal Pengajuan</option>
                    <option value="cair">Tanggal Cair (Periode)</option>
                </select>
            </div>
            <div>
                <label class="{{ $lbl }}" for="from">Dari</label>
                <input id="from" type="date" wire:model.live="from" class="{{ $inp }}" />
            </div>
            <div>
                <label class="{{ $lbl }}" for="to">Sampai</label>
                <input id="to" type="date" wire:model.live="to" class="{{ $inp }}" />
            </div>
            <div>
                <label class="{{ $lbl }}" for="lokasiId">Cluster</label>
                <select id="lokasiId" wire:model.live="lokasiId" class="{{ $inp }}">
                    <option value="">All Cluster</option>
                    @foreach ($this->getLokasiOptions() as $id => $nama)
                        <option value="{{ $id }}">{{ $nama }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="{{ $lbl }}" for="status">Status</label>
                <select id="status" wire:model.live="status" class="{{ $inp }}">
                    <option value="">Semua (kecuali Batal)</option>
                    <option value="PENDING">Pending</option>
                    <option value="APPROVED">Approved</option>
                    <option value="PAID">Paid</option>
                </select>
            </div>
            <div class="flex items-end gap-2">
                <x-filament::button wire:click="preview" icon="heroicon-o-eye">Preview</x-filament::button>
                <x-filament::button tag="a" href="{{ $this->getExportUrl() }}" color="success" icon="heroicon-o-arrow-down-tray">Excel</x-filament::button>
            </div>
        </div>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            Kolom TGL AKAD/LUNAS belum tercatat di sistem (kosong). BPHTB & PPh mengikuti rumus Excel Finance dari DPP.
        </p>
    </div>

    @if ($ready)
        <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-4 py-3 dark:border-white/10">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ number_format($report['count']) }} komisi</h3>
                @if ($report['count'] > $report['rows']->count())
                    <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-800 dark:bg-amber-500/20 dark:text-amber-300">
                        Preview {{ $report['rows']->count() }} baris pertama — Excel memuat semua.
                    </span>
                @endif
            </div>
            <div class="overflow-x-auto">
                @include('fo.laporan.komisi-tabel', ['rows' => $report['rows'], 'total' => $report['total'], 'excel' => false])
            </div>
        </div>
    @endif
</x-filament-panels::page>
