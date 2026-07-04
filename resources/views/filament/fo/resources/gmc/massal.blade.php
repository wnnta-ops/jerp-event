<x-filament-panels::page>
    <form wire:submit="create" class="space-y-6">
        {{ $this->form }}

        <div class="flex gap-3">
            <x-filament::button type="submit" icon="heroicon-o-check">
                Buat Semua
            </x-filament::button>
            <x-filament::button color="gray" tag="a" :href="\App\Filament\Fo\Resources\GmcResource::getUrl('index')">
                Batal
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
