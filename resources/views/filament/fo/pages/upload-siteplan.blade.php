<x-filament-panels::page>
    <form wire:submit="submit">
        {{ $this->form }}
        <div class="flex justify-end mt-4">
            <x-filament::button type="submit" icon="heroicon-o-photo">
                Upload Site Plan
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
