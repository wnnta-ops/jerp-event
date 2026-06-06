<x-filament-panels::page>
    <form wire:submit="generate" class="space-y-6">
        {{ $this->form }}

        @if (! empty($preview['lines']))
            <x-filament::section icon="heroicon-o-calculator">
                <x-slot name="heading">Simulasi — harga {{ 'Rp ' . number_format((float) $preview['harga'], 0, ',', '.') }}</x-slot>
                <x-slot name="description">
                    Mode {{ $preview['mode'] }}. Nominal nyata dihitung per unit dari price list saat skema diterapkan.
                </x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-white/10 text-left text-gray-500">
                                <th class="py-2 pr-4 font-medium">Komponen</th>
                                <th class="py-2 pr-4 font-medium text-right">Nominal</th>
                                <th class="py-2 font-medium">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($preview['lines'] as $line)
                                <tr class="border-b border-gray-100 dark:border-white/5 {{ $line['info'] ? 'text-gray-400 italic' : '' }}">
                                    <td class="py-1.5 pr-4">{{ $line['label'] }}</td>
                                    <td class="py-1.5 pr-4 text-right tabular-nums">{{ 'Rp ' . number_format((float) $line['nominal'], 0, ',', '.') }}</td>
                                    <td class="py-1.5 text-xs text-gray-400">{{ $line['keterangan'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="font-semibold">
                                <td class="py-2 pr-4">Total dibayar customer</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ 'Rp ' . number_format((float) $preview['total_customer'], 0, ',', '.') }}</td>
                                <td></td>
                            </tr>
                            @if ((float) $preview['plafond_kpr'] > 0)
                                <tr class="text-primary-600 dark:text-primary-400 font-semibold">
                                    <td class="py-1 pr-4">Plafond / Pencairan KPR</td>
                                    <td class="py-1 pr-4 text-right tabular-nums">{{ 'Rp ' . number_format((float) $preview['plafond_kpr'], 0, ',', '.') }}</td>
                                    <td></td>
                                </tr>
                            @endif
                        </tfoot>
                    </table>
                </div>
            </x-filament::section>
        @endif

        <div class="flex justify-end">
            <x-filament::button type="submit" icon="heroicon-o-check" size="lg">
                Generate Skema
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
