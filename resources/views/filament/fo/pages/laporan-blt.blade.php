<x-filament-panels::page>
    @php
        $inp = 'w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800';
        $lbl = 'mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300';
        $clusters = $this->getClusters();
    @endphp

    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label class="{{ $lbl }}" for="from">Tgl Pencairan Dari</label>
                <input id="from" type="date" wire:model.live="from" class="{{ $inp }}" />
            </div>
            <div>
                <label class="{{ $lbl }}" for="to">Sampai</label>
                <input id="to" type="date" wire:model.live="to" class="{{ $inp }}" />
            </div>
            <div class="flex items-end gap-2 lg:col-span-2">
                <x-filament::button wire:click="preview" icon="heroicon-o-eye">Preview</x-filament::button>
                <x-filament::button tag="a" href="{{ $this->getExportUrl() }}" color="success" icon="heroicon-o-arrow-down-tray">Excel (Rekap + semua cluster)</x-filament::button>
            </div>
        </div>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            Memuat BLT yang sudah dicairkan (PAID). Kolom Sales/Reguler, Bank, Cash, Sold Out & Batal Sepihak belum tercatat di sistem (kosong);
            BLT sales masuk kolom Closing. GL = No. AP.INV dari Posting BO (BLT hasil migrasi belum punya).
        </p>
    </div>

    @if ($ready)
        <x-filament::tabs>
            <x-filament::tabs.item :active="$tab === 'rekap'" wire:click="$set('tab', 'rekap')">Rekap All Cluster</x-filament::tabs.item>
            @foreach ($clusters as $c)
                <x-filament::tabs.item :active="$tab === (string) $c['id']" wire:click="$set('tab', '{{ $c['id'] }}')">Detail {{ $c['label'] }}</x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="overflow-x-auto">
                @if ($tab === 'rekap')
                    @include('fo.laporan.blt-rekap-tabel', $this->getRekap() + ['excel' => false])
                @else
                    @include('fo.laporan.blt-detail-tabel', $this->getDetail() + ['excel' => false])
                @endif
            </div>
        </div>
    @endif
</x-filament-panels::page>
