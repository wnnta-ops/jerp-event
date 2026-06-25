<x-filament-panels::page>
    <form wire:submit="cetak">
        {{ $this->form }}

        <div class="flex justify-end gap-3 pt-4">
            <x-filament::button tag="a" color="gray" href="{{ \App\Filament\Fo\Resources\CollectionResource::getUrl() }}">
                Kembali
            </x-filament::button>
            <x-filament::button type="submit" icon="heroicon-o-printer">
                Terbitkan &amp; Cetak SP
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
