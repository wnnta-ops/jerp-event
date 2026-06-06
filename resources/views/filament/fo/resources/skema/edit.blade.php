<x-filament-panels::page>
    @php
        $inp = 'w-full rounded-md border-gray-300 dark:border-white/20 dark:bg-white/5 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';
        $num = $inp . ' text-right tabular-nums';
    @endphp

    {{-- Top bar: identitas + aksi --}}
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 dark:border-white/10 pb-3">
        <div class="text-sm text-gray-600 dark:text-gray-300">
            <span class="font-semibold">Nomor:</span> {{ $record->code }}
            <span class="mx-2 text-gray-300">|</span>
            <span class="font-semibold">Nama:</span> {{ $record->name }}
        </div>
        <div class="flex items-center gap-2">
            <x-filament::button color="danger" icon="heroicon-o-trash" size="sm"
                wire:click="deleteRecord"
                wire:confirm="Hapus skema ini beserta seluruh termin?">Delete</x-filament::button>
        </div>
    </div>

    {{-- Header skema --}}
    @php
        $lbl = 'block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5';
        $chip = 'inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border border-gray-300 dark:border-white/15 cursor-pointer text-sm transition hover:bg-gray-50 dark:hover:bg-white/5';
        $radio = 'text-primary-600 focus:ring-primary-500 border-gray-300';
    @endphp
    <x-filament::section>
        <div class="flex items-start justify-between gap-6">
            <div class="grid flex-1 grid-cols-1 gap-x-10 gap-y-5 lg:grid-cols-2">
                {{-- Kolom kiri --}}
                <div class="space-y-5">
                    <div>
                        <label class="{{ $lbl }}">Project</label>
                        <select wire:model="header.project_id" class="{{ $inp }}">
                            <option value="">—</option>
                            @foreach ($projectOptions as $id => $nm)
                                <option value="{{ $id }}">{{ $nm }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $lbl }}">Nama Skema</label>
                        <input type="text" wire:model="header.name" class="{{ $inp }}">
                    </div>
                    <div>
                        <label class="{{ $lbl }}">Jenis</label>
                        <div class="flex flex-wrap gap-2">
                            @foreach (['KPR' => 'KPR', 'CASH_BERTAHAP' => 'Cash bertahap', 'CASH_KERAS' => 'Cash keras'] as $val => $opt)
                                <label class="{{ $chip }}">
                                    <input type="radio" wire:model="header.cara_bayar" value="{{ $val }}" class="{{ $radio }}">
                                    {{ $opt }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Kolom kanan --}}
                <div class="space-y-5">
                    <div>
                        <label class="{{ $lbl }}">Status</label>
                        <div class="flex flex-wrap gap-2">
                            <label class="{{ $chip }}">
                                <input type="radio" wire:model="header.is_active" value="1" class="{{ $radio }}">
                                <span class="font-medium text-success-600">Aktif</span>
                            </label>
                            <label class="{{ $chip }}">
                                <input type="radio" wire:model="header.is_active" value="0" class="{{ $radio }}">
                                <span class="font-medium text-danger-600">Inaktif</span>
                            </label>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="{{ $lbl }}">Diskon <span class="normal-case text-gray-400">(% bertingkat)</span></label>
                            <input type="number" step="0.01" wire:model="header.diskon_persen" class="{{ $num }}">
                        </div>
                        <div>
                            <label class="{{ $lbl }}">Subsidi developer <span class="normal-case text-gray-400">(%)</span></label>
                            <input type="number" step="0.01" wire:model="header.subsidi_persen" class="{{ $num }}">
                        </div>
                    </div>
                    <div>
                        <label class="{{ $lbl }}">Present Value <span class="normal-case text-gray-400">(harga acuan simulasi)</span></label>
                        <input type="number" step="0.01" wire:model="header.present_value" class="{{ $num }}">
                    </div>
                </div>
            </div>

            {{-- Site Plan --}}
            <label class="{{ $chip }} shrink-0">
                <input type="checkbox" wire:model="header.tampil_siteplan" class="rounded {{ $radio }}">
                Site Plan
            </label>
        </div>

        {{-- Booking Fee dipotong di --}}
        <div class="mt-6 border-t border-gray-100 dark:border-white/10 pt-5">
            <label class="{{ $lbl }}">Booking Fee dipotong di</label>
            <div class="flex flex-wrap gap-2">
                @foreach (['DP1' => 'DP 1', 'ANGSURAN1' => 'Angsuran 1', 'DP_DISEBAR' => 'DP Disebar', 'ANGSURAN_DISEBAR' => 'Angsuran Disebar', 'TIDAK_DISEBAR' => 'Booking Fee Tidak Disebar'] as $val => $opt)
                    <label class="{{ $chip }}">
                        <input type="radio" wire:model="header.bf_dipotong_di" value="{{ $val }}" class="{{ $radio }}">
                        {{ $opt }}
                    </label>
                @endforeach
            </div>
        </div>
    </x-filament::section>

    {{-- Tabel termin --}}
    <div class="overflow-x-auto rounded-xl ring-1 ring-gray-200 dark:ring-white/10">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-primary-600 text-white text-left">
                    <th class="px-3 py-2 font-medium w-12">No.</th>
                    <th class="px-3 py-2 font-medium">Tipe</th>
                    <th class="px-3 py-2 font-medium">Nama</th>
                    <th class="px-3 py-2 font-medium">Nilai</th>
                    <th class="px-3 py-2 font-medium">Jadwal</th>
                    <th class="px-3 py-2 font-medium w-20">Setelah</th>
                    <th class="px-3 py-2 font-medium text-center w-12">BF</th>
                    <th class="px-3 py-2 font-medium text-center w-12">KPR</th>
                    <th class="px-3 py-2 w-16"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($rows as $i => $row)
                    <tr wire:key="row-{{ $i }}" class="bg-white dark:bg-transparent align-top">
                        <td class="px-3 py-3">
                            <input type="number" wire:model="rows.{{ $i }}.urutan" class="{{ $num }} w-12">
                        </td>
                        <td class="px-3 py-3">
                            <div class="flex flex-wrap gap-x-3 gap-y-1">
                                @foreach (['BF', 'DP', 'ANG', 'ADM'] as $t)
                                    <label class="inline-flex items-center gap-1">
                                        <input type="radio" wire:model="rows.{{ $i }}.tipe" value="{{ $t }}" class="text-primary-600 focus:ring-primary-500">
                                        <span>{{ $t }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </td>
                        <td class="px-3 py-3">
                            <input type="text" wire:model="rows.{{ $i }}.nama_termin" class="{{ $inp }} min-w-40">
                        </td>
                        <td class="px-3 py-3">
                            <div class="flex items-center gap-2">
                                <div class="flex flex-col gap-0.5 text-xs">
                                    <label class="inline-flex items-center gap-1">
                                        <input type="radio" wire:model="rows.{{ $i }}.basis" value="PERSEN" class="text-primary-600 focus:ring-primary-500"> %
                                    </label>
                                    <label class="inline-flex items-center gap-1">
                                        <input type="radio" wire:model="rows.{{ $i }}.basis" value="NOMINAL" class="text-primary-600 focus:ring-primary-500"> F
                                    </label>
                                </div>
                                <input type="number" step="0.0001" wire:model="rows.{{ $i }}.nilai" class="{{ $num }} w-36">
                            </div>
                        </td>
                        <td class="px-3 py-3">
                            <div class="flex items-center gap-2">
                                <div class="flex flex-col gap-0.5 text-xs">
                                    <label class="inline-flex items-center gap-1">
                                        <input type="radio" wire:model="rows.{{ $i }}.jadwal_basis" value="BULAN" class="text-primary-600 focus:ring-primary-500"> M
                                    </label>
                                    <label class="inline-flex items-center gap-1">
                                        <input type="radio" wire:model="rows.{{ $i }}.jadwal_basis" value="HARI" class="text-primary-600 focus:ring-primary-500"> D
                                    </label>
                                    <label class="inline-flex items-center gap-1">
                                        <input type="radio" wire:model="rows.{{ $i }}.jadwal_basis" value="TANGGAL" class="text-primary-600 focus:ring-primary-500"> F
                                    </label>
                                </div>
                                <input type="number" wire:model="rows.{{ $i }}.jadwal_nilai" class="{{ $num }} w-24">
                            </div>
                        </td>
                        <td class="px-3 py-3">
                            <input type="number" wire:model="rows.{{ $i }}.setelah" class="{{ $num }} w-16">
                        </td>
                        <td class="px-3 py-3 text-center">
                            <input type="checkbox" wire:model="rows.{{ $i }}.flag_bf" class="rounded text-primary-600 focus:ring-primary-500">
                        </td>
                        <td class="px-3 py-3 text-center">
                            <input type="checkbox" wire:model="rows.{{ $i }}.flag_kpr" class="rounded text-primary-600 focus:ring-primary-500">
                        </td>
                        <td class="px-3 py-3 text-right">
                            <button type="button" wire:click="deleteRow({{ $i }})"
                                class="text-xs text-danger-600 hover:underline">Delete…</button>
                        </td>
                    </tr>
                @endforeach

                <tr class="bg-gray-50 dark:bg-white/5">
                    <td class="px-3 py-3 text-xs text-gray-400">Baru:</td>
                    <td colspan="8" class="px-3 py-3">
                        <x-filament::button color="gray" size="sm" icon="heroicon-o-plus" wire:click="addRow">
                            Tambah termin
                        </x-filament::button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Preview simulasi --}}
    @if (! empty($preview['lines']))
        <x-filament::section icon="heroicon-o-calculator">
            <x-slot name="heading">Simulasi — harga {{ 'Rp ' . number_format((float) $preview['harga'], 0, ',', '.') }}</x-slot>
            <x-slot name="description">Mode {{ $preview['mode'] }}. Nominal nyata dihitung per unit dari price list.</x-slot>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <tbody>
                        @foreach ($preview['lines'] as $line)
                            <tr class="border-b border-gray-100 dark:border-white/5 {{ $line['info'] ? 'text-gray-400 italic' : '' }}">
                                <td class="py-1.5 pr-4">{{ $line['label'] }}</td>
                                <td class="py-1.5 pr-4 text-right tabular-nums">{{ 'Rp ' . number_format((float) $line['nominal'], 0, ',', '.') }}</td>
                                <td class="py-1.5 text-xs text-gray-400">{{ $line['keterangan'] }}</td>
                            </tr>
                        @endforeach
                        <tr class="font-semibold">
                            <td class="py-2 pr-4">Total dibayar customer</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ 'Rp ' . number_format((float) $preview['total_customer'], 0, ',', '.') }}</td>
                            <td></td>
                        </tr>
                        @if ((float) $preview['plafond_kpr'] > 0)
                            <tr class="text-primary-600 font-semibold">
                                <td class="py-1 pr-4">Plafond / Pencairan KPR</td>
                                <td class="py-1 pr-4 text-right tabular-nums">{{ 'Rp ' . number_format((float) $preview['plafond_kpr'], 0, ',', '.') }}</td>
                                <td></td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    {{-- Aksi --}}
    <div class="flex flex-wrap items-center gap-2">
        <x-filament::button wire:click="save(false)" icon="heroicon-o-check">OK</x-filament::button>
        <x-filament::button color="warning" wire:click="save(true)" icon="heroicon-o-bookmark">Apply</x-filament::button>
        <x-filament::button color="gray" icon="heroicon-o-calculator" wire:click="simulasi">Simulasi</x-filament::button>
        <x-filament::button color="danger" tag="a" :href="\App\Filament\Fo\Resources\SkemaResource::getUrl('index')" icon="heroicon-o-x-mark">Cancel</x-filament::button>
    </div>
</x-filament-panels::page>
