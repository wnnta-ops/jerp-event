<x-filament-panels::page>
    <form wire:submit="submit">
        {{ $this->form }}
        <div class="flex justify-end gap-3 mt-4">
            <x-filament::button type="button" color="gray" icon="heroicon-o-check" wire:click="saveDisplay">
                Simpan Setelan
            </x-filament::button>
            <x-filament::button type="submit" icon="heroicon-o-photo">
                Upload Site Plan
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
