<x-filament-panels::page>
    <form wire:submit="submit">
        {{ $this->form }}
        <div class="flex justify-end mt-4">
            <x-filament::button type="submit" icon="heroicon-o-arrow-up-tray">
                Upload &amp; Import
            </x-filament::button>
        </div>
    </form>

    @if ($result)
        <div class="mt-6 rounded-xl border p-5"
             style="border-color: var(--gray-200); background: var(--gray-50);">
            <h3 class="font-semibold mb-3">Hasil Import</h3>
            <div class="grid grid-cols-3 gap-3 mb-3">
                <div class="rounded-lg p-3 text-center" style="background:#dcfce7;">
                    <div class="text-2xl font-bold" style="color:#15803d;">{{ $result['created'] ?? 0 }}</div>
                    <div class="text-xs text-gray-600">Dibuat</div>
                </div>
                <div class="rounded-lg p-3 text-center" style="background:#dbeafe;">
                    <div class="text-2xl font-bold" style="color:#1e40af;">{{ $result['updated'] ?? 0 }}</div>
                    <div class="text-xs text-gray-600">Diperbarui</div>
                </div>
                <div class="rounded-lg p-3 text-center" style="background:#fef3c7;">
                    <div class="text-2xl font-bold" style="color:#92400e;">{{ $result['skipped'] ?? 0 }}</div>
                    <div class="text-xs text-gray-600">Dilewati</div>
                </div>
            </div>
            @if (! empty($result['errors']))
                {{-- Sengaja disebut "catatan", bukan "error": sebagian di antaranya peringatan
                     (mis. kolom bulan tidak lengkap) pada baris yang TETAP tersimpan. --}}
                <details class="mt-2" open>
                    <summary class="cursor-pointer text-sm font-medium text-amber-700">{{ count($result['errors']) }} catatan</summary>
                    <ul class="mt-2 text-xs text-amber-700 space-y-1 max-h-60 overflow-y-auto">
                        @foreach ($result['errors'] as $err)
                            <li>• {{ $err }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif
            <p class="mt-3 text-xs text-gray-500">
                Grafik <strong>Sales Report — SPR per Bulan</strong> di Dashboard langsung memakai angka ini.
            </p>
        </div>
    @endif
</x-filament-panels::page>
