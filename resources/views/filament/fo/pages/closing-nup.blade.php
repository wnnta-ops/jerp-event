<x-filament-panels::page>
    <div class="mb-4">
        <div class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 flex items-center gap-2">
            <a href="{{ url('/fo') }}" class="hover:underline" style="color:#60a5fa;">Dashboard FO</a>
            <span>›</span>
            <a href="{{ url('/fo/launching') }}" class="hover:underline" style="color:#60a5fa;">Launching</a>
            <span>›</span>
            <span>Closing NUP</span>
        </div>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">
            Naik kontrak dari NUP yang sudah <strong>Pilih Unit</strong>. Pilih skema cara bayar + sales,
            lalu <em>Closing</em> akan membuat <strong>Kontrak</strong> + <strong>TTS</strong> (UTJ) yang siap dibuka jadi kwitansi.
        </p>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
