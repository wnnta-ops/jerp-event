<x-filament-panels::page>
    @php
        $report = $this->getReport();
        $rows = $report['rows'];
        $total = $report['total'];
        $count = $report['count'];
        $n = fn ($x) => number_format((float) $x, 2, '.', ',');
        $tdNum = 'px-2 py-1.5 text-right font-mono whitespace-nowrap';
        $tdTxt = 'px-2 py-1.5 whitespace-nowrap';
    @endphp

    {{-- Filter --}}
    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div class="lg:col-span-2">
                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Cluster</label>
                <select wire:model.live="lokasiId" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800">
                    <option value="">All Cluster</option>
                    @foreach ($this->getLokasiOptions() as $id => $nama)
                        <option value="{{ $id }}">{{ $nama }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Periode Dari</label>
                <input type="date" wire:model.live="from" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800" />
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Periode Sampai</label>
                <input type="date" wire:model.live="to" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800" />
            </div>
            <div class="flex items-end gap-2">
                <x-filament::button wire:click="preview" icon="heroicon-o-eye">Preview</x-filament::button>
                <x-filament::button tag="a" href="{{ $this->getExportUrl() }}" target="_blank" color="success" icon="heroicon-o-arrow-down-tray">
                    Export Excel
                </x-filament::button>
            </div>
        </div>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            Kosongkan tanggal untuk seluruh periode. Laporan memuat kontrak berstatus AKTIF (Penjualan Netto).
        </p>
    </div>

    {{-- Preview --}}
    @if ($ready)
        <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-4 py-3 dark:border-white/10">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">C. Penjualan Netto</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Cluster: {{ $this->getClusterLabel() }} · Periode: {{ $from ?: 'Awal' }} s/d {{ $to ?: 'Sekarang' }} · {{ number_format($count) }} kontrak
                    </p>
                </div>
                @if ($count > $rows->count())
                    <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-800 dark:bg-amber-500/20 dark:text-amber-300">
                        Preview dibatasi {{ $rows->count() }} baris pertama — Export Excel memuat semua ({{ number_format($count) }}).
                    </span>
                @endif
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="sticky top-0">
                        <tr class="bg-primary-600 text-left text-white">
                            <th rowspan="2" class="px-2 py-2">No.</th>
                            <th rowspan="2" class="px-2 py-2">Tgl. Kontrak</th>
                            <th rowspan="2" class="px-2 py-2">No. Kontrak</th>
                            <th rowspan="2" class="px-2 py-2">Customer</th>
                            <th rowspan="2" class="px-2 py-2">Sales</th>
                            <th rowspan="2" class="px-2 py-2">Project</th>
                            <th rowspan="2" class="px-2 py-2">Unit</th>
                            <th rowspan="2" class="px-2 py-2">Tipe</th>
                            <th rowspan="2" class="px-2 py-2">Tipe Properti</th>
                            <th rowspan="2" class="px-2 py-2 text-right">Luas Tanah</th>
                            <th rowspan="2" class="px-2 py-2 text-right">Luas Lebih</th>
                            <th rowspan="2" class="px-2 py-2 text-right">Luas Bangunan</th>
                            <th rowspan="2" class="px-2 py-2">Cara Bayar</th>
                            <th rowspan="2" class="px-2 py-2 text-right">Price List</th>
                            <th rowspan="2" class="px-2 py-2 text-right">Diskon</th>
                            <th rowspan="2" class="px-2 py-2 text-right">Diskon Tambahan</th>
                            <th rowspan="2" class="px-2 py-2 text-right">Tambahan Harga</th>
                            <th rowspan="2" class="px-2 py-2 text-right">Lain-lain</th>
                            <th rowspan="2" class="px-2 py-2 text-right">Bunga</th>
                            <th rowspan="2" class="px-2 py-2 text-right">Nilai Kontrak</th>
                            <th colspan="2" class="px-2 py-1 text-center">Perincian Kontrak</th>
                        </tr>
                        <tr class="bg-primary-600 text-white">
                            <th class="px-2 py-1 text-right">DPP</th>
                            <th class="px-2 py-1 text-right">PPN</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse ($rows as $r)
                            <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="{{ $tdTxt }}">{{ $r['no'] }}</td>
                                <td class="{{ $tdTxt }}">{{ $r['tgl'] }}</td>
                                <td class="{{ $tdTxt }} font-mono">{{ $r['no_kontrak'] }}</td>
                                <td class="{{ $tdTxt }}">{{ $r['customer'] }}</td>
                                <td class="{{ $tdTxt }}">{{ $r['sales'] }}</td>
                                <td class="{{ $tdTxt }}">{{ $r['project'] }}</td>
                                <td class="{{ $tdTxt }} font-mono">{{ $r['unit'] }}</td>
                                <td class="{{ $tdTxt }}">{{ $r['tipe'] }}</td>
                                <td class="{{ $tdTxt }}">{{ $r['tipe_properti'] }}</td>
                                <td class="{{ $tdNum }}">{{ $n($r['luas_tanah']) }}</td>
                                <td class="{{ $tdNum }}">{{ $n($r['luas_lebih']) }}</td>
                                <td class="{{ $tdNum }}">{{ $n($r['luas_bangun']) }}</td>
                                <td class="{{ $tdTxt }}">{{ $r['cara_bayar'] }}</td>
                                <td class="{{ $tdNum }}">{{ $n($r['price_list']) }}</td>
                                <td class="{{ $tdNum }}">{{ $n($r['diskon']) }}</td>
                                <td class="{{ $tdNum }}">{{ $n($r['diskon_tambahan']) }}</td>
                                <td class="{{ $tdNum }}">{{ $n($r['tambahan_harga']) }}</td>
                                <td class="{{ $tdNum }}">{{ $n($r['lain']) }}</td>
                                <td class="{{ $tdNum }}">{{ $n($r['bunga']) }}</td>
                                <td class="{{ $tdNum }} font-semibold">{{ $n($r['nilai_kontrak']) }}</td>
                                <td class="{{ $tdNum }}">{{ $n($r['dpp']) }}</td>
                                <td class="{{ $tdNum }}">{{ $n($r['ppn']) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="22" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Tidak ada kontrak AKTIF pada filter ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($rows->isNotEmpty())
                        <tfoot>
                            <tr class="bg-gray-100 font-semibold dark:bg-white/10">
                                <td colspan="9" class="px-2 py-2">TOTAL</td>
                                <td class="{{ $tdNum }}">{{ $n($total['luas_tanah'] ?? 0) }}</td>
                                <td class="{{ $tdNum }}">{{ $n($total['luas_lebih'] ?? 0) }}</td>
                                <td class="{{ $tdNum }}">{{ $n($total['luas_bangun'] ?? 0) }}</td>
                                <td></td>
                                <td class="{{ $tdNum }}">{{ $n($total['price_list'] ?? 0) }}</td>
                                <td class="{{ $tdNum }}">{{ $n($total['diskon'] ?? 0) }}</td>
                                <td class="{{ $tdNum }}">{{ $n($total['diskon_tambahan'] ?? 0) }}</td>
                                <td class="{{ $tdNum }}">{{ $n($total['tambahan_harga'] ?? 0) }}</td>
                                <td class="{{ $tdNum }}">{{ $n($total['lain'] ?? 0) }}</td>
                                <td class="{{ $tdNum }}">{{ $n($total['bunga'] ?? 0) }}</td>
                                <td class="{{ $tdNum }}">{{ $n($total['nilai_kontrak'] ?? 0) }}</td>
                                <td class="{{ $tdNum }}">{{ $n($total['dpp'] ?? 0) }}</td>
                                <td class="{{ $tdNum }}">{{ $n($total['ppn'] ?? 0) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    @endif
</x-filament-panels::page>
