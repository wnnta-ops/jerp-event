<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Project selector --}}
        <div class="rounded-xl border p-4 flex flex-col md:flex-row md:items-center md:justify-between gap-3"
             style="background-color: rgb(249 250 251 / 1);">
            <div>
                <div class="text-xs uppercase tracking-wider text-gray-500">Proyek</div>
                <select wire:change="setProject($event.target.value)" class="mt-1 rounded-lg border px-3 py-2 text-sm">
                    @foreach ($this->projects as $p)
                        <option value="{{ $p->id }}" @selected($p->id === $projectId)>{{ $p->code }} — {{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-center gap-3 text-xs">
                <span class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded-sm" style="background-color: oklch(0.65 0.18 145);"></span> TERSEDIA</span>
                <span class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded-sm" style="background-color: oklch(0.78 0.18 75);"></span> BOOKING</span>
                <span class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded-sm" style="background-color: oklch(0.55 0.18 250);"></span> TERJUAL</span>
                <span class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded-sm" style="background-color: oklch(0.60 0.22 25);"></span> BATAL/HOLD</span>
            </div>
        </div>

        @if ($this->units->isEmpty())
            <div class="rounded-xl border border-dashed p-8 text-center">
                <div class="text-gray-500">Belum ada unit untuk proyek ini.</div>
            </div>
        @else
            {{-- Grid peta unit — grouped by blok --}}
            @php
                $byBlok = $this->units->groupBy(fn ($u) => $u->blok ?: '—');
            @endphp

            @foreach ($byBlok as $blok => $units)
                <div class="rounded-xl border p-4">
                    <div class="text-sm font-semibold mb-3">Blok {{ $blok }}</div>
                    <div class="grid grid-cols-4 md:grid-cols-8 lg:grid-cols-12 gap-2">
                        @foreach ($units as $u)
                            @php
                                $bg = match ($u->status) {
                                    'TERSEDIA' => 'oklch(0.65 0.18 145)',
                                    'BOOKING' => 'oklch(0.78 0.18 75)',
                                    'TERJUAL', 'LUNAS' => 'oklch(0.55 0.18 250)',
                                    'BATAL', 'HOLD' => 'oklch(0.60 0.22 25)',
                                    default => 'oklch(0.70 0 0)',
                                };
                                $clickable = in_array($u->status, ['TERSEDIA', 'BOOKING']);
                                $action = $u->status === 'TERSEDIA' ? 'lockUnit' : ($u->status === 'BOOKING' ? 'releaseUnit' : null);
                            @endphp
                            <button type="button"
                                    @if ($clickable && $action) wire:click="{{ $action }}({{ $u->id }})" wire:loading.attr="disabled" @else disabled @endif
                                    title="{{ $u->status }} · {{ $u->blok ? $u->blok.'/' : '' }}{{ $u->no_unit }}"
                                    class="aspect-square rounded text-white text-xs font-bold flex items-center justify-center transition hover:opacity-80 disabled:cursor-not-allowed"
                                    style="background-color: {{ $bg }};">
                                {{ $u->no_unit }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @endforeach
        @endif
    </div>

    @push('scripts')
        <script>
            document.addEventListener('livewire:initialized', () => {
                if (!window.Echo) {
                    console.warn('[PetaUnitLive] window.Echo not configured — realtime updates disabled.');
                    return;
                }

                const projectId = @json($projectId);
                if (!projectId) return;

                window.Echo.join(`project.${projectId}.units`)
                    .here(users => console.debug('[PetaUnitLive] presence here:', users))
                    .joining(u => console.debug('[PetaUnitLive] joining:', u))
                    .leaving(u => console.debug('[PetaUnitLive] leaving:', u))
                    .listen('.unit.status.changed', (e) => {
                        console.debug('[PetaUnitLive] unit changed:', e);
                        // Livewire #[On] handler will also fire and refresh the grid.
                    });
            });
        </script>
    @endpush
</x-filament-panels::page>
