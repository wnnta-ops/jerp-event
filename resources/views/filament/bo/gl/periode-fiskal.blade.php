<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Periode Fiskal</x-slot>
        {{ $this->form }}
    </x-filament::section>

    @php($bulanNama = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'])

    <x-filament::section class="mt-4">
        <x-slot name="heading">Jurnal per Bulan — {{ $tahun }}</x-slot>
        <x-slot name="description">Baru = Draft · Final = Approved · Approved = Posted · Closed = periode ditutup (pemetaan sementara)</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left">
                        <th class="py-2">Tahun</th>
                        <th>Bulan</th>
                        <th class="text-right">Baru</th>
                        <th class="text-right">Final</th>
                        <th class="text-right">Approved</th>
                        <th class="text-center">Closed</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->getRows() as $row)
                        <tr class="border-b border-gray-100 dark:border-gray-800 {{ $row['closed'] ? 'bg-warning-50 dark:bg-warning-500/10' : '' }}">
                            <td class="py-1">{{ $tahun }}</td>
                            <td>{{ $row['bulan'] }} — {{ $bulanNama[$row['bulan']] }}</td>
                            <td class="text-right">{{ $row['baru'] ?: '' }}</td>
                            <td class="text-right">{{ $row['final'] ?: '' }}</td>
                            <td class="text-right">{{ $row['approved'] ?: '' }}</td>
                            <td class="text-center">
                                @if ($row['closed'])
                                    <span class="rounded bg-danger-100 px-2 py-0.5 text-xs font-semibold text-danger-700">CLOSED</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
