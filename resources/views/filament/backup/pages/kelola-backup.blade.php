<x-filament-panels::page>
    @if (empty($backupRows))
        <div class="rounded-xl border border-dashed p-8 text-center">
            <x-filament::icon icon="heroicon-o-archive-box" class="mx-auto h-10 w-10 text-gray-400" />
            <h3 class="mt-3 text-lg font-semibold">Belum ada backup</h3>
            <p class="mt-1 text-sm text-gray-500">
                Klik tombol <strong>Jalankan backup sekarang</strong> di kanan atas untuk membuat backup pertama.
            </p>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border" style="border-color: rgb(229 231 235 / 1);">
            <table class="min-w-full divide-y" style="border-color: rgb(229 231 235 / 1);">
                <thead style="background-color: rgb(249 250 251 / 1);">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wider">
                        <th class="px-4 py-3">File</th>
                        <th class="px-4 py-3">Disk</th>
                        <th class="px-4 py-3">Ukuran</th>
                        <th class="px-4 py-3">Tanggal</th>
                    </tr>
                </thead>
                <tbody class="divide-y" style="border-color: rgb(229 231 235 / 1);">
                    @foreach ($backupRows as $row)
                        <tr class="text-sm">
                            <td class="px-4 py-3 font-mono">{{ $row['filename'] }}</td>
                            <td class="px-4 py-3">
                                <x-filament::badge color="info">{{ $row['disk'] }}</x-filament::badge>
                            </td>
                            <td class="px-4 py-3 tabular-nums">{{ $row['size'] }}</td>
                            <td class="px-4 py-3">{{ $row['date'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
