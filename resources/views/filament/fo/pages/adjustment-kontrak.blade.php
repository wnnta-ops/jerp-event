<x-filament-panels::page>
    @if ($selectedKontrakId)
        <div>
            <x-filament::button color="gray" icon="heroicon-o-arrow-left" wire:click="backToList">
                Kembali ke Daftar Kontrak
            </x-filament::button>
        </div>

        <form wire:submit="submit">
            {{ $this->form }}
            <div class="flex justify-end gap-3 mt-4">
                <x-filament::button type="submit" icon="heroicon-o-check">
                    Simpan Adjustment
                </x-filament::button>
            </div>
        </form>

        <p class="text-xs text-gray-500 mt-2">
            Catatan: Setelah simpan, jalankan <strong>Reschedule Tagihan</strong> untuk menyesuaikan nilai tagihan.
        </p>
    @else
        {{ $this->table }}
    @endif
</x-filament-panels::page>
