<x-filament-panels::page>
    {{ $this->form }}

    @php($t = $this->totals())

    <x-filament::section class="mt-4">
        <x-slot name="heading">Neraca Saldo</x-slot>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left">
                        <th class="py-2">Kode</th><th>Nama Akun</th><th>Tipe</th>
                        <th class="text-right">Debet</th><th class="text-right">Kredit</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->rows() as $row)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-1 font-mono">{{ $row->kode }}</td>
                            <td>{{ $row->nama }}</td>
                            <td class="text-gray-500">{{ $row->tipe_akun }}</td>
                            <td class="text-right">{{ number_format($row->debet, 0, ',', '.') }}</td>
                            <td class="text-right">{{ number_format($row->kredit, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                    <tr class="border-t-2 font-bold">
                        <td colspan="3" class="py-2">TOTAL</td>
                        <td class="text-right">{{ number_format($t['total_debet'], 0, ',', '.') }}</td>
                        <td class="text-right">{{ number_format($t['total_kredit'], 0, ',', '.') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="mt-3 text-sm font-semibold {{ $t['seimbang'] ? 'text-success-600' : 'text-danger-600' }}">
            {{ $t['seimbang'] ? 'SEIMBANG ✓' : 'TIDAK SEIMBANG ✗' }}
        </div>
    </x-filament::section>
</x-filament-panels::page>
