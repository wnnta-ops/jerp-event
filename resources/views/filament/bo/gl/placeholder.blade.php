<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Menunggu penyesuaian</x-slot>
        <x-slot name="description">Struktur menu mengikuti GL legacy eProperty</x-slot>

        <div class="text-sm text-gray-600 dark:text-gray-300">
            {{ $this->getKeterangan() }}
        </div>
        <div class="mt-3 rounded-md bg-warning-50 p-3 text-sm text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
            Halaman ini masih placeholder. Isi tampilan, kolom, dan alur (flow) akan dibangun
            setelah screenshot detail menu ini dikirim.
        </div>
    </x-filament::section>
</x-filament-panels::page>
