{{-- Scanner HP modul Event — warna brand Java Core. Logika & aturan di ScanService. --}}
<div class="mx-auto max-w-lg min-h-dvh flex flex-col">
@if (! $point)
    <div x-data="eventScannerPemulih" class="flex-1 p-4 space-y-4">
        <div class="pt-2">
            <div class="jc-brand text-xs uppercase">Java Core · Event</div>
            <h1 class="text-2xl font-bold">Scanner</h1>
        </div>

        @if (! $event)
            @if ($daftarEvent->isEmpty())
                <div class="jc-warn">
                    Belum ada event untuk akun ini. Minta superadmin menambahkan akun Anda di langkah Akses event.
                </div>
            @else
                <div class="text-sm jc-muted">Pilih event</div>
                <div class="space-y-3">
                    @foreach ($daftarEvent as $e)
                        @if ($e->status === 'open')
                            <button type="button" wire:click="pilihEvent({{ $e->id }})" wire:key="ev-{{ $e->id }}" class="jc-card jc-card-on">
                                <div class="min-w-0">
                                    <div class="text-lg font-bold truncate">{{ $e->name }}</div>
                                    <div class="jc-sub text-sm">{{ $e->event_date->translatedFormat('d M Y') }}{{ $e->venue ? ' · '.$e->venue : '' }}</div>
                                </div>
                                <span class="jc-pill">Aktif</span>
                            </button>
                        @else
                            <div wire:key="ev-{{ $e->id }}" class="jc-card jc-card-off" aria-disabled="true" title="Event belum dibuka superadmin">
                                <div class="min-w-0">
                                    <div class="text-lg font-bold truncate">{{ $e->name }}</div>
                                    <div class="jc-sub text-sm">{{ $e->event_date->translatedFormat('d M Y') }}{{ $e->venue ? ' · '.$e->venue : '' }}</div>
                                </div>
                                <span class="jc-off-tag">belum aktif</span>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        @else
            <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-sm jc-muted">Event</div>
                    <div class="text-lg font-bold truncate">{{ $event->name }}</div>
                </div>
                @if ($daftarEvent->count() > 1)
                    <button type="button" wire:click="gantiEvent" class="jc-link text-sm shrink-0">Ganti event</button>
                @endif
            </div>
            <div class="text-sm jc-muted">Pilih titik scan HP ini</div>
            @if ($titik->isEmpty())
                <div class="jc-warn">Akun ini belum diberi titik scan di event ini.</div>
            @endif
            <div class="space-y-3">
                @foreach ($titik as $t)
                    <button type="button" wire:click="pilihTitik({{ $t->id }})" wire:key="tt-{{ $t->id }}" class="jc-card jc-card-on">
                        <div class="min-w-0">
                            <div class="jc-sub text-xs">Pos {{ $t->station->sequence }}{{ $t->station->is_optional ? ' · opsional' : '' }}</div>
                            <div class="text-lg font-bold truncate">{{ $t->station->name }}</div>
                            <div class="jc-sub text-sm truncate">{{ $t->label }}</div>
                        </div>
                        <span class="jc-pill">{{ $t->kindLabel() }}</span>
                    </button>
                @endforeach
            </div>
        @endif

        <div class="pt-4 text-center">
            <a href="{{ route('dashboard') }}" class="jc-link text-sm">Menu utama</a>
        </div>
    </div>
@else
    <div x-data="eventScanner({{ $point->id }}, {{ $undoSeconds }})" wire:key="titik-{{ $point->id }}" class="flex-1 flex flex-col">
        {{-- Header: pos + jenis titik --}}
        <header class="jc-header sticky top-0 z-20 px-4 py-3 flex items-center gap-3">
            <div class="flex-1 min-w-0">
                <div class="text-xs truncate" style="opacity:.9">{{ $event->name }}</div>
                <div class="text-2xl font-black leading-tight truncate">{{ $point->station->name }} · {{ $point->kindLabel() }}</div>
            </div>
            <button type="button" class="jc-header-btn shrink-0" @click="jerpLupakanTitikEvent(); $wire.gantiTitik()">Ganti</button>
        </header>

        <div class="p-4 space-y-4">
            {{-- Customer yang sedang dipegang petugas ini: geser untuk selesai --}}
            <div class="space-y-3" @if (empty($milikSaya)) style="display:none" @endif>
                @foreach ($milikSaya as $d)
                    <div class="jc-mine" wire:key="mine-{{ $d['visit_id'] }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-xs font-bold uppercase" style="color:var(--jc-orange);letter-spacing:.05em">Customer Anda</div>
                                <div class="text-xl font-bold truncate">{{ $d['name'] }}</div>
                                <div class="text-sm jc-muted">{{ $d['no_nup'] ? 'NUP '.$d['no_nup'] : '' }}{{ $d['no_nup'] && $d['customer_code'] ? ' · ' : '' }}{{ $d['customer_code'] }}</div>
                            </div>
                            <div class="text-right shrink-0">
                                <div class="jc-time text-2xl" x-text="lama('{{ $d['since'] }}')"></div>
                                <div class="text-xs jc-muted">dilayani</div>
                            </div>
                        </div>
                        <div class="mt-3" x-data="geserSelesai({{ $d['participant_id'] }})">
                            <div class="jc-slide" x-ref="track" :class="selesaiKah && 'done'">
                                <div class="jc-slide-fill" :style="`width:${x + 52}px`"></div>
                                <div class="jc-slide-text" :style="`opacity:${1 - persen}`">Geser untuk selesai ›››</div>
                                <div class="jc-knob" x-ref="knob" role="slider" tabindex="0"
                                     aria-label="Geser untuk menandai {{ $d['name'] }} selesai"
                                     :aria-valuenow="Math.round(persen * 100)" aria-valuemin="0" aria-valuemax="100"
                                     :style="`transform:translateX(${x}px)`"
                                     @pointerdown="mulai($event)" @keydown.enter.prevent="konfirmasi()" @keydown.space.prevent="konfirmasi()">
                                    <svg width="22" height="22" viewBox="0 0 20 20" fill="currentColor"><path d="M7.3 4.3a1 1 0 0 1 1.4 0l5 5a1 1 0 0 1 0 1.4l-5 5a1 1 0 0 1-1.4-1.4L11.6 10 7.3 5.7a1 1 0 0 1 0-1.4z"/></svg>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Kamera --}}
            <div wire:ignore>
                <div id="qr-reader" class="w-full aspect-square rounded-xl overflow-hidden" style="background:#000"></div>
            </div>
            <p x-show="kameraGagal" x-text="kameraGagal" class="jc-warn" x-cloak></p>

            {{-- Ketik manual --}}
            <form @submit.prevent="kirimManual()" class="flex gap-2">
                <input x-model="manual" type="text" inputmode="text" autocomplete="off" autocapitalize="characters"
                       placeholder="Ketik No. NUP / kode customer" class="jc-input">
                <button type="submit" :disabled="sibuk" class="jc-btn">Kirim</button>
            </form>

            {{-- Batalkan scan terakhir (30 detik) --}}
            <button type="button" x-show="sisaUndo > 0" x-cloak @click="batalkan()" class="jc-btn-outline">
                Batalkan scan terakhir (<span x-text="sisaUndo"></span> dtk)
            </button>

            {{-- Daftar (diperbarui tiap 5 detik) --}}
            <div wire:poll.5s class="space-y-4">
                @if ($point->kind !== 'keluar')
                    <section>
                        <h2 class="jc-sec">Menunggu untuk pos ini ({{ count($menunggu) }})</h2>
                        <div class="jc-list">
                            @forelse ($menunggu as $m)
                                <div class="flex items-center gap-3 px-4 py-3" wire:key="w-{{ $m['participant_id'] }}">
                                    <div class="flex-1 min-w-0">
                                        <div class="font-semibold truncate">{{ $m['name'] }}</div>
                                        <div class="text-xs jc-muted">{{ $m['no_nup'] ? 'NUP '.$m['no_nup'] : '' }}{{ $m['no_nup'] && $m['customer_code'] ? ' · ' : '' }}{{ $m['customer_code'] }}</div>
                                    </div>
                                    <div class="jc-time" x-text="lama('{{ $m['since'] }}')"></div>
                                </div>
                            @empty
                                <div class="px-4 py-3 text-sm jc-muted">Tidak ada yang menunggu.</div>
                            @endforelse
                        </div>
                    </section>
                @endif

                @if ($point->kind !== 'masuk')
                    <section>
                        <h2 class="jc-sec">Sedang dilayani ({{ count($dilayani) }})</h2>
                        <div class="jc-list">
                            @forelse ($dilayani as $d)
                                <div class="flex items-center gap-3 px-4 py-3" wire:key="s-{{ $d['visit_id'] }}">
                                    <div class="flex-1 min-w-0">
                                        <div class="font-semibold truncate">{{ $d['name'] }}</div>
                                        <div class="text-xs jc-muted">{{ $d['no_nup'] ? 'NUP '.$d['no_nup'] : '' }}{{ $d['no_nup'] && $d['customer_code'] ? ' · ' : '' }}{{ $d['customer_code'] }}</div>
                                    </div>
                                    <div class="jc-time" x-text="lama('{{ $d['since'] }}')"></div>
                                    <button type="button" @click="selesai({{ $d['participant_id'] }})" :disabled="sibuk" class="jc-btn" style="padding:.5rem .9rem;font-size:.85rem">Selesai</button>
                                </div>
                            @empty
                                <div class="px-4 py-3 text-sm jc-muted">Tidak ada yang sedang dilayani.</div>
                            @endforelse
                        </div>
                    </section>
                @endif
            </div>
        </div>

        {{-- Kartu hasil layar penuh (hijau / kuning / biru / merah tetap, supaya jelas dari jauh) --}}
        <div x-show="fase === 'hasil'" x-cloak @click="tutupHasil()" :class="warnaHasil()"
             class="fixed inset-0 z-50 flex flex-col items-center justify-center p-6 text-center" style="color:#fff">
            <div class="text-7xl font-black mb-4" x-text="hasil?.status === 'accepted' ? '✓' : (hasil?.status === 'duplicate' ? '↺' : '✕')"></div>
            <div class="text-3xl font-black leading-tight" x-text="hasil?.message"></div>
            <div class="mt-6 text-2xl font-semibold" x-text="hasil?.name || ''"></div>
            <div class="mt-1 text-xl" style="opacity:.9" x-show="hasil?.no_nup" x-text="'NUP ' + (hasil?.no_nup || '')"></div>
            <div class="mt-6 rounded-lg px-4 py-2 text-lg" style="background:rgba(0,0,0,.2)" x-show="hasil?.warning" x-text="hasil?.warning"></div>
            <div class="mt-10 text-sm" style="opacity:.8">Ketuk untuk lanjut</div>
        </div>

        {{-- Pilih NUP (customer dengan beberapa NUP) --}}
        <div x-show="fase === 'pilih'" x-cloak class="fixed inset-0 z-50 flex flex-col p-6" style="background:#fff">
            <div class="text-sm jc-muted" x-text="hasil?.name || ''"></div>
            <div class="mt-1 text-2xl font-bold leading-tight" x-text="hasil?.message"></div>
            <div class="mt-6 flex-1 space-y-4 overflow-y-auto">
                <template x-for="c in (hasil?.candidates || [])" :key="c.nup_registration_id">
                    <button type="button" @click="pilih(c.nup_registration_id)" class="jc-pick" x-text="'NUP ' + c.no_nup"></button>
                </template>
            </div>
            <button type="button" @click="batalPilih()" class="jc-btn-outline mt-4">Batal</button>
        </div>
    </div>
@endif
</div>
