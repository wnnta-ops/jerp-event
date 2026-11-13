{{-- Scanner HP modul Event. Logika & aturan di ScanService; di sini hanya tampilan. --}}
<div class="mx-auto max-w-lg min-h-dvh flex flex-col">
@if (! $point)
    <div x-data="eventScannerPemulih" class="flex-1 p-4 space-y-4">
        <div class="pt-2">
            <div class="text-xs uppercase tracking-wider text-orange-400 font-semibold">Java Core · Event</div>
            <h1 class="text-2xl font-bold">Scanner</h1>
        </div>

        @if ($events->isEmpty())
            <div class="rounded-xl bg-slate-800 p-4 text-slate-300">
                Belum ada event yang dibuka untuk akun ini. Minta superadmin menambahkan akun Anda di langkah Akses event.
            </div>
        @elseif (! $event)
            <div class="text-sm text-slate-400">Pilih event</div>
            <div class="space-y-3">
                @foreach ($events as $e)
                    <button type="button" wire:click="pilihEvent({{ $e->id }})" wire:key="ev-{{ $e->id }}"
                            class="w-full text-left rounded-xl bg-slate-800 active:bg-slate-700 p-4">
                        <div class="text-lg font-semibold">{{ $e->name }}</div>
                        <div class="text-sm text-slate-400">{{ $e->event_date->translatedFormat('d M Y') }}{{ $e->venue ? ' · '.$e->venue : '' }}</div>
                    </button>
                @endforeach
            </div>
        @else
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm text-slate-400">Event</div>
                    <div class="text-lg font-semibold">{{ $event->name }}</div>
                </div>
                @if ($events->count() > 1)
                    <button type="button" wire:click="gantiEvent" class="text-sm text-orange-400 underline">Ganti event</button>
                @endif
            </div>
            <div class="text-sm text-slate-400">Pilih titik scan HP ini</div>
            @if ($titik->isEmpty())
                <div class="rounded-xl bg-slate-800 p-4 text-slate-300">Akun ini belum diberi titik scan di event ini.</div>
            @endif
            <div class="space-y-3">
                @foreach ($titik as $t)
                    <button type="button" wire:click="pilihTitik({{ $t->id }})" wire:key="tt-{{ $t->id }}"
                            class="w-full text-left rounded-xl bg-slate-800 active:bg-slate-700 p-4 flex items-center justify-between gap-3">
                        <div>
                            <div class="text-xs text-slate-400">Pos {{ $t->station->sequence }}</div>
                            <div class="text-lg font-semibold">{{ $t->station->name }}</div>
                            <div class="text-sm text-slate-400">{{ $t->label }}</div>
                        </div>
                        <span @class([
                            'shrink-0 rounded-lg px-3 py-1 text-sm font-bold',
                            'bg-emerald-600' => $t->kind === 'masuk',
                            'bg-orange-600' => $t->kind === 'keluar',
                            'bg-sky-600' => $t->kind === 'single',
                        ])>{{ $t->kindLabel() }}</span>
                    </button>
                @endforeach
            </div>
        @endif

        <div class="pt-4 text-center">
            <a href="{{ route('dashboard') }}" class="text-sm text-slate-500 underline">Menu utama</a>
        </div>
    </div>
@else
    <div x-data="eventScanner({{ $point->id }}, {{ $undoSeconds }})" wire:key="titik-{{ $point->id }}" class="flex-1 flex flex-col">
        {{-- Header besar: pos + jenis titik --}}
        <header @class([
            'sticky top-0 z-20 px-4 py-3 flex items-center gap-3 shadow',
            'bg-emerald-700' => $point->kind === 'masuk',
            'bg-orange-600' => $point->kind === 'keluar',
            'bg-sky-700' => $point->kind === 'single',
        ])>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-80 truncate">{{ $event->name }}</div>
                <div class="text-2xl font-black leading-tight truncate">{{ $point->station->name }} · {{ $point->kindLabel() }}</div>
            </div>
            <button type="button" class="shrink-0 rounded-lg bg-black/25 px-3 py-2 text-sm"
                    @click="jerpLupakanTitikEvent(); $wire.gantiTitik()">Ganti</button>
        </header>

        <div class="p-4 space-y-4">
            {{-- Kamera --}}
            <div wire:ignore>
                <div id="qr-reader" class="w-full aspect-square rounded-xl overflow-hidden bg-black"></div>
            </div>
            <p x-show="kameraGagal" x-text="kameraGagal" class="rounded-lg bg-amber-900/60 p-3 text-sm text-amber-100" x-cloak></p>

            {{-- Ketik manual --}}
            <form @submit.prevent="kirimManual()" class="flex gap-2">
                <input x-model="manual" type="text" inputmode="text" autocomplete="off" autocapitalize="characters"
                       placeholder="Ketik No. NUP / kode customer"
                       class="flex-1 rounded-xl bg-slate-800 border border-slate-700 px-4 py-3 text-lg placeholder:text-slate-500 focus:outline-none focus:border-orange-500">
                <button type="submit" :disabled="sibuk" class="rounded-xl bg-orange-600 px-5 py-3 font-bold disabled:opacity-50">Kirim</button>
            </form>

            {{-- Batalkan scan terakhir (30 detik) --}}
            <button type="button" x-show="sisaUndo > 0" x-cloak @click="batalkan()"
                    class="w-full rounded-xl border border-slate-600 bg-slate-800 py-3 text-base">
                Batalkan scan terakhir (<span x-text="sisaUndo"></span> dtk)
            </button>

            {{-- Daftar (diperbarui tiap 5 detik) --}}
            <div wire:poll.5s class="space-y-4">
                @if ($point->kind !== 'keluar')
                    <section>
                        <h2 class="mb-2 text-sm font-semibold text-slate-300">Menunggu untuk pos ini ({{ count($menunggu) }})</h2>
                        <div class="divide-y divide-slate-800 rounded-xl bg-slate-900">
                            @forelse ($menunggu as $m)
                                <div class="flex items-center gap-3 px-4 py-3" wire:key="w-{{ $m['participant_id'] }}">
                                    <div class="flex-1 min-w-0">
                                        <div class="font-semibold truncate">{{ $m['name'] }}</div>
                                        <div class="text-xs text-slate-400">NUP {{ $m['no_nup'] }}{{ $m['customer_code'] ? ' · '.$m['customer_code'] : '' }}</div>
                                    </div>
                                    <div class="font-mono tabular-nums text-slate-300" x-text="lama('{{ $m['since'] }}')"></div>
                                </div>
                            @empty
                                <div class="px-4 py-3 text-sm text-slate-500">Tidak ada yang menunggu.</div>
                            @endforelse
                        </div>
                    </section>
                @endif

                @if ($point->kind !== 'masuk')
                    <section>
                        <h2 class="mb-2 text-sm font-semibold text-slate-300">Sedang dilayani ({{ count($dilayani) }})</h2>
                        <div class="divide-y divide-slate-800 rounded-xl bg-slate-900">
                            @forelse ($dilayani as $d)
                                <div class="flex items-center gap-3 px-4 py-3" wire:key="s-{{ $d['visit_id'] }}">
                                    <div class="flex-1 min-w-0">
                                        <div class="font-semibold truncate">{{ $d['name'] }}</div>
                                        <div class="text-xs text-slate-400">NUP {{ $d['no_nup'] }}{{ $d['customer_code'] ? ' · '.$d['customer_code'] : '' }}</div>
                                    </div>
                                    <div class="font-mono tabular-nums text-slate-300" x-text="lama('{{ $d['since'] }}')"></div>
                                    <button type="button" @click="selesai({{ $d['participant_id'] }})" :disabled="sibuk"
                                            class="rounded-lg bg-orange-600 px-4 py-2 text-sm font-bold disabled:opacity-50">Selesai</button>
                                </div>
                            @empty
                                <div class="px-4 py-3 text-sm text-slate-500">Tidak ada yang sedang dilayani.</div>
                            @endforelse
                        </div>
                    </section>
                @endif
            </div>
        </div>

        {{-- Kartu hasil layar penuh --}}
        <div x-show="fase === 'hasil'" x-cloak @click="tutupHasil()"
             :class="warnaHasil()"
             class="fixed inset-0 z-50 flex flex-col items-center justify-center p-6 text-center text-white">
            <div class="text-7xl font-black mb-4" x-text="hasil?.status === 'accepted' ? '✓' : (hasil?.status === 'duplicate' ? '↺' : '✕')"></div>
            <div class="text-3xl font-black leading-tight" x-text="hasil?.message"></div>
            <div class="mt-6 text-2xl font-semibold" x-text="hasil?.name || ''"></div>
            <div class="mt-1 text-xl opacity-90" x-show="hasil?.no_nup" x-text="'NUP ' + (hasil?.no_nup || '')"></div>
            <div class="mt-6 rounded-lg bg-black/25 px-4 py-2 text-lg" x-show="hasil?.warning" x-text="hasil?.warning"></div>
            <div class="mt-10 text-sm opacity-75">Ketuk untuk lanjut</div>
        </div>

        {{-- Pilih NUP (customer dengan beberapa NUP) --}}
        <div x-show="fase === 'pilih'" x-cloak class="fixed inset-0 z-50 flex flex-col bg-slate-950 p-6">
            <div class="text-sm text-slate-400" x-text="hasil?.name || ''"></div>
            <div class="mt-1 text-2xl font-bold leading-tight" x-text="hasil?.message"></div>
            <div class="mt-6 flex-1 space-y-4 overflow-y-auto">
                <template x-for="c in (hasil?.candidates || [])" :key="c.nup_registration_id">
                    <button type="button" @click="pilih(c.nup_registration_id)"
                            class="w-full rounded-2xl bg-orange-600 active:bg-orange-700 py-6 text-3xl font-black"
                            x-text="'NUP ' + c.no_nup"></button>
                </template>
            </div>
            <button type="button" @click="batalPilih()" class="mt-4 w-full rounded-xl border border-slate-600 py-3 text-lg">Batal</button>
        </div>
    </div>
@endif
</div>
