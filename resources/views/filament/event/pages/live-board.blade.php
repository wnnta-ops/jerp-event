{{-- Live board modul Event. Data dari AntreanService::papan; diperbarui tiap 5 detik. --}}
<x-filament-panels::page>
<style>
    .lb-bar{display:flex;flex-wrap:wrap;gap:.75rem;align-items:center}
    .lb-chip{display:inline-flex;gap:.35rem;align-items:center;border-radius:999px;padding:.25rem .7rem;font-size:.8rem;font-weight:600;background:rgb(243 244 246)}
    .dark .lb-chip{background:rgb(31 41 55)}
    .lb-cols{display:flex;gap:1rem;overflow-x:auto;padding-bottom:.5rem}
    .lb-col{flex:0 0 18rem;border-radius:.75rem;border:1px solid rgb(229 231 235);background:rgb(249 250 251);display:flex;flex-direction:column;max-height:75vh}
    .dark .lb-col{border-color:rgb(55 65 81);background:rgb(17 24 39)}
    .lb-head{padding:.75rem .9rem;border-bottom:1px solid rgb(229 231 235)}
    .dark .lb-head{border-color:rgb(55 65 81)}
    .lb-title{font-weight:700;font-size:1rem}
    .lb-sub{font-size:.75rem;opacity:.7}
    .lb-stats{display:flex;gap:.5rem;margin-top:.4rem;font-size:.75rem;flex-wrap:wrap}
    .lb-body{overflow-y:auto;padding:.6rem;display:flex;flex-direction:column;gap:.5rem}
    .lb-sec{font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;opacity:.6;margin:.3rem .1rem 0}
    .lb-card{border-radius:.6rem;padding:.55rem .7rem;background:white;border:1px solid rgb(229 231 235);border-left-width:4px}
    .dark .lb-card{background:rgb(31 41 55);border-color:rgb(55 65 81)}
    .lb-card.svc{border-left-color:rgb(14 165 233)}
    .lb-card.wait{border-left-color:rgb(156 163 175)}
    .lb-card.amber{border-left-color:rgb(245 158 11);background:rgb(255 251 235)}
    .lb-card.red{border-left-color:rgb(225 29 72);background:rgb(255 241 242)}
    .dark .lb-card.amber{background:rgb(69 26 3)}
    .dark .lb-card.red{background:rgb(76 5 25)}
    .lb-card.hit{outline:2px solid rgb(234 88 12)}
    .lb-row{display:flex;justify-content:space-between;gap:.5rem;align-items:baseline}
    .lb-name{font-weight:600;font-size:.875rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .lb-meta{font-size:.72rem;opacity:.7}
    .lb-time{font-family:ui-monospace,monospace;font-variant-numeric:tabular-nums;font-weight:700;font-size:.875rem}
    .lb-acts{display:flex;gap:.6rem;margin-top:.35rem;font-size:.72rem}
    .lb-acts button{color:rgb(234 88 12);font-weight:600}
    .lb-acts button.del{color:rgb(225 29 72)}
    .lb-empty{font-size:.75rem;opacity:.5;padding:.25rem .1rem}
    .lb-flag{font-size:.65rem;border-radius:.3rem;padding:0 .3rem;background:rgb(254 243 199);color:rgb(146 64 14);margin-left:.25rem}
    .lb-tbl{width:100%;font-size:.85rem;border-collapse:collapse}
    .lb-tbl td,.lb-tbl th{padding:.45rem .5rem;border-bottom:1px solid rgb(229 231 235);text-align:left}
    .dark .lb-tbl td,.dark .lb-tbl th{border-color:rgb(55 65 81)}
    .lb-toggle{display:inline-flex;border:1px solid rgb(209 213 219);border-radius:.6rem;overflow:hidden}
    .dark .lb-toggle{border-color:rgb(55 65 81)}
    .lb-toggle button{padding:.45rem .8rem;font-size:.85rem;font-weight:600}
    .lb-toggle button.on{background:#ff8c00;color:#fff}
    /* Tampilan per peserta */
    .jr-wrap{overflow-x:auto;border:1px solid rgb(229 231 235);border-radius:.75rem}
    .dark .jr-wrap{border-color:rgb(55 65 81)}
    .jr{width:100%;border-collapse:collapse;font-size:.85rem}
    .jr th{font-size:.72rem;font-weight:600;opacity:.75;padding:.6rem .4rem;text-align:center;white-space:nowrap;border-bottom:1px solid rgb(229 231 235)}
    .jr th.kiri,.jr td.kiri{text-align:left;padding-left:.9rem}
    .jr td{padding:.55rem .4rem;border-bottom:1px solid rgb(243 244 246);vertical-align:middle}
    .dark .jr th,.dark .jr td{border-color:rgb(31 41 55)}
    .jr td.dot-cell{position:relative;text-align:center;min-width:5.5rem}
    .jr td.dot-cell::before{content:"";position:absolute;top:50%;left:0;right:0;height:2px;background:rgb(229 231 235);z-index:0}
    .dark .jr td.dot-cell::before{background:rgb(55 65 81)}
    .jr td.dot-cell.first::before{left:50%}
    .jr td.dot-cell.last::before{right:50%}
    .jr td.dot-cell.lewat::before{background:rgb(16 185 129)}
    .dot{position:relative;z-index:1;display:inline-block;width:16px;height:16px;border-radius:50%;vertical-align:middle}
    .dot.tepat{background:rgb(16 185 129)}
    .dot.telat{background:rgb(225 29 72)}
    .dot.menunggu,.dot.dilayani{background:#fff;border:2px solid #ff8c00}
    .dot.belum{background:#fff;border:2px solid rgb(209 213 219)}
    .dark .dot.belum{background:rgb(17 24 39);border-color:rgb(75 85 99)}
    .dot.dilewati{background:transparent;border:2px dashed rgb(156 163 175)}
    .dot.dilayani::before,.dot.dilayani::after{content:"";position:absolute;inset:-2px;border-radius:50%;border:2px solid #ff8c00;animation:radar 1.8s ease-out infinite}
    .dot.dilayani::after{animation-delay:.9s}
    @keyframes radar{0%{transform:scale(1);opacity:.85}100%{transform:scale(3.2);opacity:0}}
    .jr-legend{display:flex;flex-wrap:wrap;gap:1rem;font-size:.75rem;opacity:.85;align-items:center}
    .jr-legend span{display:inline-flex;gap:.4rem;align-items:center}
    .lb-field{min-width:18rem}
    /* Kartu per peserta (HP) */
    .jr-cards{display:none;flex-direction:column;gap:.6rem}
    .jr-card{border:1px solid rgb(229 231 235);border-radius:.8rem;padding:.7rem .8rem;background:white}
    .dark .jr-card{border-color:rgb(55 65 81);background:rgb(17 24 39)}
    .jr-track{display:grid;margin-top:.6rem}
    .jr-step{position:relative;display:flex;flex-direction:column;align-items:center;gap:.3rem;min-width:0}
    .jr-step::before{content:"";position:absolute;top:7px;left:0;right:0;height:2px;background:rgb(229 231 235);z-index:0}
    .dark .jr-step::before{background:rgb(55 65 81)}
    .jr-step.first::before{left:50%}
    .jr-step.last::before{right:50%}
    .jr-step.lewat::before{background:rgb(16 185 129)}
    .jr-lbl{font-size:.6rem;line-height:1.1;opacity:.7;text-align:center;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:0 .1rem}
    @media (max-width: 640px) {
        .lb-bar{gap:.5rem}
        .lb-field{min-width:0;width:100%}
        .lb-bar .lb-sub{margin-left:0 !important;width:100%}
        .jr-legend{gap:.35rem .8rem;font-size:.68rem}
        .jr-legend .dot{width:12px;height:12px}
        .jr-wrap{display:none}
        .jr-cards{display:flex}
        .lb-cols{scroll-snap-type:x mandatory;gap:.6rem;margin:0 -.25rem;padding:0 .25rem .5rem}
        .lb-col{flex:0 0 88%;scroll-snap-align:start;max-height:none}
        .lb-tbl{display:block;overflow-x:auto;white-space:nowrap}
    }
</style>

<div wire:poll.5s
     x-data="{ now: Date.now(), t: null,
        init() { this.t = setInterval(() => this.now = Date.now(), 1000) }, destroy() { clearInterval(this.t) },
        dtk(iso) { return iso ? Math.max(0, Math.floor((this.now - new Date(iso).getTime()) / 1000)) : 0 },
        fmt(iso) { const d = this.dtk(iso), j = Math.floor(d/3600), m = Math.floor(d%3600/60), s = d%60;
            return (j ? j + ':' + String(m).padStart(2,'0') : String(m).padStart(2,'0')) + ':' + String(s).padStart(2,'0') },
        warna(iso, target) { if (!target) return ''; const m = this.dtk(iso) / 60;
            return m >= target * 1.5 ? 'red' : (m >= target ? 'amber' : '') } }"
     class="space-y-4">

    <div class="lb-bar">
        <x-filament::input.wrapper class="lb-field">
            <x-filament::input.select wire:model.live="eventId">
                @forelse ($events as $e)
                    <option value="{{ $e->id }}">{{ $e->name }} · {{ $e->event_date->translatedFormat('d M Y') }}{{ $e->status === 'closed' ? ' (ditutup)' : '' }}</option>
                @empty
                    <option value="">Belum ada event yang dibuka</option>
                @endforelse
            </x-filament::input.select>
        </x-filament::input.wrapper>
        <x-filament::input.wrapper class="lb-field" prefix-icon="heroicon-m-magnifying-glass">
            <x-filament::input type="search" placeholder="Cari nama / No. NUP / kode customer" wire:model.live.debounce.400ms="cari" />
        </x-filament::input.wrapper>
        <div class="lb-toggle" role="group" aria-label="Tampilan">
            <button type="button" class="{{ $perPeserta ? '' : 'on' }}" wire:click="gantiTampilan('pos')">Per pos</button>
            <button type="button" class="{{ $perPeserta ? 'on' : '' }}" wire:click="gantiTampilan('peserta')">Per peserta</button>
        </div>
        @if ($papan)
            @php $r = $papan['ringkasan']; @endphp
            <span class="lb-chip">Peserta {{ $r['total'] }}</span>
            <span class="lb-chip" style="color:rgb(2 132 199)">Aktif {{ $r['aktif'] }}</span>
            <span class="lb-chip" style="color:rgb(5 150 105)">Selesai {{ $r['selesai'] }}</span>
            @if ($r['pulang']) <span class="lb-chip" style="color:rgb(225 29 72)">Pulang {{ $r['pulang'] }}</span> @endif
            @if ($r['batal']) <span class="lb-chip">Batal aktivasi {{ $r['batal'] }}</span> @endif
            @unless ($perPeserta)
                <span class="lb-sub" style="margin-left:auto">Kuning = lewat target · merah = lewat 1,5× target</span>
            @endunless
        @endif
    </div>

    @if (! $event)
        <x-filament::section>Belum ada event yang bisa ditampilkan untuk akun ini.</x-filament::section>
    @else
        @php $cari = mb_strtolower(trim($this->cari)); @endphp

        @if ($hasilCari->isNotEmpty() && ! $perPeserta)
            <x-filament::section :heading="'Hasil pencarian ('.$hasilCari->count().')'" compact>
                <table class="lb-tbl">
                    <thead><tr><th>Nama</th><th>No. NUP</th><th>Kode</th><th>Posisi</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($hasilCari as $p)
                        <tr wire:key="cari-{{ $p->id }}">
                            <td>{{ $p->name_snapshot }}</td>
                            <td>{{ $p->no_nup ?? '—' }}</td>
                            <td>{{ $p->customer_code ?? '—' }}</td>
                            <td>{{ $p->currentStation?->name ?? '—' }} · {{ ['waiting' => 'menunggu', 'in_service' => 'dilayani', 'done' => 'selesai'][$p->current_state] ?? $p->current_state }}</td>
                            <td>{{ ['active' => 'Aktif', 'finished' => 'Selesai', 'left' => 'Pulang', 'cancelled' => 'Batal aktivasi'][$p->status] ?? $p->status }}</td>
                            <td class="lb-acts" style="margin:0">
                                @if ($bolehKoreksi)
                                    @if ($p->status === 'active')
                                        <button type="button" wire:click="mountAction('mulaiLayanan', { participant: {{ $p->id }} })">Mulai layanan…</button>
                                        <button type="button" class="del" wire:click="mountAction('tandaiPulang', { participant: {{ $p->id }} })">Pulang</button>
                                    @elseif (in_array($p->status, ['finished', 'left'], true))
                                        <button type="button" wire:click="mountAction('bukaKembali', { participant: {{ $p->id }} })">Buka kembali</button>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </x-filament::section>
        @elseif (mb_strlen($cari) >= 2 && ! $perPeserta)
            <div class="lb-sub">Tidak ada peserta yang cocok dengan "{{ $this->cari }}".</div>
        @endif

        @if ($perPeserta)
            <div class="jr-legend">
                <span><i class="dot tepat"></i> Selesai, tepat / lebih cepat</span>
                <span><i class="dot telat"></i> Selesai, lewat target</span>
                <span><i class="dot dilayani"></i> Sedang dilayani</span>
                <span><i class="dot menunggu"></i> Menunggu di pos ini</span>
                <span><i class="dot dilewati"></i> Dilewati</span>
                <span><i class="dot belum"></i> Belum</span>
            </div>
            <div class="jr-wrap">
                <table class="jr">
                    <thead>
                        <tr>
                            <th class="kiri">Peserta</th>
                            @foreach ($perjalanan['pos'] as $pos)
                                <th>{{ $pos['name'] }}@if ($pos['optional'])<br><span style="font-weight:400;font-style:italic">opsional</span>@endif
                                    @if ($pos['target'])<br><span style="font-weight:400">{{ $pos['target'] }} mnt</span>@endif</th>
                            @endforeach
                            <th>Di pos ini</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($perjalanan['baris'] as $b)
                            @php $n = count($b['titik']); @endphp
                            <tr wire:key="jr-{{ $b['participant_id'] }}">
                                <td class="kiri">
                                    <div style="font-weight:600">{{ $b['name'] }}</div>
                                    <div class="lb-meta">{{ $b['no_nup'] ? 'NUP '.$b['no_nup'] : '' }}{{ $b['no_nup'] && $b['customer_code'] ? ' · ' : '' }}{{ $b['customer_code'] }}
                                        @if ($b['status'] === 'finished') · <span style="color:rgb(5 150 105)">selesai</span>
                                        @elseif ($b['status'] === 'left') · <span style="color:rgb(225 29 72)">pulang</span> @endif
                                    </div>
                                </td>
                                @foreach ($b['titik'] as $i => $t)
                                    @php
                                        $judul = match ($t['state']) {
                                            'tepat', 'telat' => $t['detik'] !== null ? 'Dilayani '.gmdate($t['detik'] >= 3600 ? 'G:i:s' : 'i:s', $t['detik']).($t['state'] === 'telat' ? ' — lewat target' : '') : 'Selesai',
                                            'dilayani' => 'Sedang dilayani',
                                            'menunggu' => 'Menunggu di pos ini',
                                            'dilewati' => 'Dilewati',
                                            default => 'Belum',
                                        };
                                        $lewat = in_array($t['state'], ['tepat', 'telat', 'dilewati'], true);
                                    @endphp
                                    <td @class(['dot-cell', 'first' => $i === 0, 'last' => $i === $n - 1, 'lewat' => $lewat])>
                                        <i class="dot {{ $t['state'] }}" title="{{ $perjalanan['pos'][$i]['name'] }}: {{ $judul }}"></i>
                                    </td>
                                @endforeach
                                <td style="text-align:center">
                                    @if ($b['since'])<span class="lb-time" x-text="fmt('{{ $b['since'] }}')"></span>@else <span class="lb-sub">—</span> @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td class="kiri" colspan="{{ count($perjalanan['pos']) + 2 }}"><span class="lb-sub">Belum ada peserta{{ mb_strlen(trim($this->cari)) >= 2 ? ' yang cocok' : '' }}.</span></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- HP: kartu per peserta, jalur titik selebar layar --}}
            <div class="jr-cards">
                @forelse ($perjalanan['baris'] as $b)
                    @php $n = count($b['titik']); @endphp
                    <div class="jr-card" wire:key="jrc-{{ $b['participant_id'] }}">
                        <div class="lb-row">
                            <div style="min-width:0">
                                <div class="lb-name">{{ $b['name'] }}</div>
                                <div class="lb-meta">{{ $b['no_nup'] ? 'NUP '.$b['no_nup'] : $b['customer_code'] }}
                                    @if ($b['status'] === 'finished') · <span style="color:rgb(5 150 105)">selesai</span>
                                    @elseif ($b['status'] === 'left') · <span style="color:rgb(225 29 72)">pulang</span> @endif
                                </div>
                            </div>
                            @if ($b['since'])<span class="lb-time" x-text="fmt('{{ $b['since'] }}')"></span>@endif
                        </div>
                        <div class="jr-track" style="grid-template-columns:repeat({{ $n }}, minmax(0, 1fr))">
                            @foreach ($b['titik'] as $i => $t)
                                <div @class(['jr-step', 'first' => $i === 0, 'last' => $i === $n - 1, 'lewat' => in_array($t['state'], ['tepat', 'telat', 'dilewati'], true)])>
                                    <i class="dot {{ $t['state'] }}"></i>
                                    <span class="jr-lbl">{{ $perjalanan['pos'][$i]['name'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <div class="lb-sub">Belum ada peserta{{ mb_strlen(trim($this->cari)) >= 2 ? ' yang cocok' : '' }}.</div>
                @endforelse
            </div>
        @else
        <div class="lb-cols">
            @foreach ($papan['pos'] as $pos)
                @php
                    $tertua = collect($pos['dilayani'])->pluck('since')->merge(collect($pos['menunggu'])->pluck('since'))->filter()->min();
                    $cocok = fn ($c) => $cari !== '' && (str_contains(mb_strtolower((string) $c['name']), $cari) || str_contains(mb_strtolower((string) $c['no_nup']), $cari) || str_contains(mb_strtolower((string) $c['customer_code']), $cari));
                @endphp
                <div class="lb-col" wire:key="pos-{{ $pos['id'] }}">
                    <div class="lb-head">
                        <div class="lb-row">
                            <div class="lb-title">{{ $pos['name'] }}</div>
                            <div class="lb-sub">Pos {{ $pos['sequence'] }}</div>
                        </div>
                        <div class="lb-sub">
                            {{ $pos['target'] ? 'Target '.$pos['target'].' mnt' : 'Tanpa target' }}{{ $pos['optional'] ? ' · opsional' : '' }}{{ $pos['finish'] ? ' · pos Selesai' : '' }}
                        </div>
                        <div class="lb-stats">
                            <span class="lb-chip">Menunggu {{ count($pos['menunggu']) }}</span>
                            <span class="lb-chip" style="color:rgb(2 132 199)">Dilayani {{ count($pos['dilayani']) }}</span>
                            <span class="lb-chip" style="color:rgb(5 150 105)">Selesai {{ $pos['selesai'] }}</span>
                        </div>
                        @if ($tertua)
                            <div class="lb-sub" style="margin-top:.35rem">Terlama sekarang: <span class="lb-time" x-text="fmt('{{ $tertua }}')"></span></div>
                        @endif
                    </div>
                    <div class="lb-body">
                        <div class="lb-sec">Sedang dilayani</div>
                        @forelse ($pos['dilayani'] as $c)
                            <div class="lb-card svc {{ $cocok($c) ? 'hit' : '' }}" wire:key="v-{{ $c['visit_id'] }}"
                                 :class="warna('{{ $c['since'] }}', {{ (int) $pos['target'] }})">
                                <div class="lb-row">
                                    <div class="lb-name">{{ $c['name'] }}</div>
                                    <div class="lb-time" x-text="fmt('{{ $c['since'] }}')"></div>
                                </div>
                                <div class="lb-meta">
                                    {{ $c['no_nup'] ? 'NUP '.$c['no_nup'] : '' }}{{ $c['no_nup'] && $c['customer_code'] ? ' · ' : '' }}{{ $c['customer_code'] }}
                                    @foreach ($c['flags'] as $f) <span class="lb-flag">{{ str_replace('_', ' ', $f) }}</span> @endforeach
                                </div>
                                @if ($bolehKoreksi)
                                    <div class="lb-acts">
                                        <button type="button" wire:click="mountAction('tutupKunjungan', { visit: {{ $c['visit_id'] }} })">Tutup paksa</button>
                                        <button type="button" class="del" wire:click="mountAction('tandaiPulang', { participant: {{ $c['participant_id'] }} })">Pulang</button>
                                    </div>
                                @endif
                            </div>
                        @empty
                            <div class="lb-empty">Tidak ada.</div>
                        @endforelse

                        <div class="lb-sec">Menunggu</div>
                        @forelse ($pos['menunggu'] as $c)
                            <div class="lb-card wait {{ $cocok($c) ? 'hit' : '' }}" wire:key="w-{{ $c['participant_id'] }}"
                                 :class="warna('{{ $c['since'] }}', {{ (int) $pos['target'] }})">
                                <div class="lb-row">
                                    <div class="lb-name">{{ $c['name'] }}</div>
                                    <div class="lb-time" x-text="fmt('{{ $c['since'] }}')"></div>
                                </div>
                                <div class="lb-meta">{{ $c['no_nup'] ? 'NUP '.$c['no_nup'] : '' }}{{ $c['no_nup'] && $c['customer_code'] ? ' · ' : '' }}{{ $c['customer_code'] }}</div>
                                @if ($bolehKoreksi)
                                    <div class="lb-acts">
                                        <button type="button" wire:click="mountAction('mulaiLayanan', { participant: {{ $c['participant_id'] }}, station: {{ $pos['id'] }} })">Mulai di sini</button>
                                        <button type="button" class="del" wire:click="mountAction('tandaiPulang', { participant: {{ $c['participant_id'] }} })">Pulang</button>
                                    </div>
                                @endif
                            </div>
                        @empty
                            <div class="lb-empty">Tidak ada.</div>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
        @endif
    @endif
</div>
</x-filament-panels::page>
