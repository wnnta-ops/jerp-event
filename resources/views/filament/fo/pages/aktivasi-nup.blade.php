<x-filament-panels::page>
    <div class="mb-4">
        <div class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 flex items-center gap-2">
            <a href="{{ url('/fo') }}" class="hover:underline" style="color:#60a5fa;">Dashboard FO</a>
            <span>›</span>
            <a href="{{ url('/fo/launching') }}" class="hover:underline" style="color:#60a5fa;">Launching</a>
            <span>›</span>
            <span>Aktivasi NUP</span>
        </div>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">
            Check-in customer di hari-H launching. Hanya NUP yang sudah <strong>APPROVED</strong> di Verifikasi Data yang tampil.
            Setelah <em>Aktivasi</em>, customer dapat lanjut ke <strong>Pilih Unit</strong>.
        </p>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
