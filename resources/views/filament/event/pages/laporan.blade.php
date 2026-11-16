{{-- Laporan satu event. Angka dari EventReportService; durasi mm:ss. --}}
@php
    use App\Modules\Event\Services\EventReportService as R;
    $d = fn ($x) => R::durasi($x);
    $statusLabel = ['active' => 'Aktif', 'finished' => 'Selesai', 'left' => 'Pulang', 'cancelled' => 'Batal aktivasi'];
@endphp
<x-filament-panels::page>
<style>
    .lp-bar{display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end}
    .lp-field{display:flex;flex-direction:column;gap:.25rem;font-size:.75rem;font-weight:600;opacity:.9}
    .lp-field input{border:1px solid rgb(209 213 219);border-radius:.5rem;padding:.45rem .6rem;font-size:.875rem;background:transparent}
    .dark .lp-field input{border-color:rgb(55 65 81)}
    .lp-chips{display:flex;flex-wrap:wrap;gap:.4rem}
    .lp-chip{display:inline-flex;align-items:center;gap:.35rem;border:1px solid rgb(209 213 219);border-radius:999px;padding:.25rem .7rem;font-size:.8rem;cursor:pointer;user-select:none}
    .dark .lp-chip{border-color:rgb(55 65 81)}
    .lp-chip input{accent-color:#ff8c00}
    .lp-chip.on{border-color:#ff8c00;background:rgb(255 244 229);color:#9a3412}
    .dark .lp-chip.on{background:rgb(67 20 7);color:#fdba74}
    .lp-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(9.5rem,1fr));gap:.75rem}
    .lp-stat{border:1px solid rgb(229 231 235);border-radius:.75rem;padding:.75rem .9rem;background:white}
    .dark .lp-stat{border-color:rgb(55 65 81);background:rgb(17 24 39)}
    .lp-stat .k{font-size:.72rem;opacity:.7}
    .lp-stat .v{font-size:1.4rem;font-weight:800;font-variant-numeric:tabular-nums}
    .lp-wrap{overflow-x:auto}
    .lp-tbl{width:100%;border-collapse:collapse;font-size:.85rem}
    .lp-tbl th{font-size:.72rem;font-weight:600;opacity:.75;text-align:left;padding:.5rem .6rem;border-bottom:1px solid rgb(229 231 235);white-space:nowrap}
    .lp-tbl td{padding:.5rem .6rem;border-bottom:1px solid rgb(243 244 246);white-space:nowrap;font-variant-numeric:tabular-nums}
    .dark .lp-tbl th,.dark .lp-tbl td{border-color:rgb(31 41 55)}
    .lp-tbl td.num,.lp-tbl th.num{text-align:right}
    .lp-tbl tr.grup td{border-top:2px solid rgb(229 231 235)}
    .dark .lp-tbl tr.grup td{border-top-color:rgb(55 65 81)}
    .lp-late{color:rgb(225 29 72);font-weight:700}
    .lp-skip{opacity:.45;font-style:italic}
    .lp-barbg{height:.6rem;border-radius:999px;background:rgb(243 244 246);min-width:6rem}
    .dark .lp-barbg{background:rgb(31 41 55)}
    .lp-barfill{height:.6rem;border-radius:999px;background:#ff8c00}
    .lp-muted{font-size:.75rem;opacity:.65}
</style>

<div class="space-y-6">
    {{-- Filter --}}
    <x-filament::section compact>
        <div class="space-y-3">
            <div class="lp-bar">
                <label class="lp-field" style="min-width:16rem;flex:1">Event
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="eventId">
                            @forelse ($events as $e)
                                <option value="{{ $e->id }}">{{ $e->name }} · {{ $e->event_date->translatedFormat('d M Y') }}{{ $e->status === 'closed' ? ' (ditutup)' : '' }}</option>
                            @empty
                                <option value="">Belum ada event</option>
                            @endforelse
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <label class="lp-field">Mulai kunjungan dari <input type="datetime-local" wire:model.live="dari"></label>
                <label class="lp-field">sampai <input type="datetime-local" wire:model.live="sampai"></label>
                <x-filament::button color="gray" size="sm" wire:click="resetFilter">Reset filter</x-filament::button>
            </div>
            @if ($stations->isNotEmpty())
                <div class="lp-chips">
                    <span class="lp-muted" style="align-self:center">Pos:</span>
                    @foreach ($stations as $s)
                        <label @class(['lp-chip', 'on' => in_array($s->id, array_map('intval', $this->pos), true)])>
                            <input type="checkbox" value="{{ $s->id }}" wire:model.live="pos"> {{ $s->name }}
                        </label>
                    @endforeach
                    <span class="lp-muted" style="align-self:center;margin-left:.75rem">Status:</span>
                    @foreach ($statusLabel as $k => $lbl)
                        <label @class(['lp-chip', 'on' => in_array($k, $this->status, true)])>
                            <input type="checkbox" value="{{ $k }}" wire:model.live="status"> {{ $lbl }}
                        </label>
                    @endforeach
                </div>
                <div class="lp-muted">Tanpa centang = semua. Export Excel memakai filter yang sama.</div>
            @endif
        </div>
    </x-filament::section>

    @if (! $event)
        <x-filament::section>Belum ada event yang bisa dilaporkan untuk akun ini.</x-filament::section>
    @else
        {{-- Ringkasan --}}
        <div class="lp-stats">
            <div class="lp-stat"><div class="k">Peserta</div><div class="v">{{ $ringkasan['peserta'] }}</div></div>
            <div class="lp-stat"><div class="k">Selesai semua pos</div><div class="v" style="color:rgb(5 150 105)">{{ $ringkasan['selesai'] }}</div></div>
            <div class="lp-stat"><div class="k">Masih aktif</div><div class="v" style="color:rgb(2 132 199)">{{ $ringkasan['aktif'] }}</div></div>
            <div class="lp-stat"><div class="k">Pulang / batal</div><div class="v">{{ $ringkasan['pulang'] }} / {{ $ringkasan['batal'] }}</div></div>
            <div class="lp-stat"><div class="k">Total waktu (median)</div><div class="v">{{ $d($ringkasan['total_median']) }}</div><div class="lp-muted">rata2 {{ $d($ringkasan['total_rata']) }} · P90 {{ $d($ringkasan['total_p90']) }}</div></div>
            <div class="lp-stat"><div class="k">Tunggu per pos (rata2)</div><div class="v">{{ $d($ringkasan['tunggu_rata']) }}</div><div class="lp-muted">{{ $ringkasan['kunjungan'] }} kunjungan · {{ $ringkasan['koreksi'] }} koreksi</div></div>
        </div>

        {{-- Per pos --}}
        <x-filament::section heading="Per pos" description="Lama layanan = selesai − mulai di pos. Waktu tunggu = mulai di pos − selesai pos sebelumnya (pos pertama: sejak check-in).">
            <div class="lp-wrap">
                <table class="lp-tbl">
                    <thead><tr>
                        <th>Pos</th><th class="num">Target</th><th class="num">Kunjungan</th><th class="num">Selesai</th>
                        <th class="num">Layanan rata2</th><th class="num">Median</th><th class="num">P90</th>
                        <th class="num">Tunggu rata2</th><th class="num">Tunggu P90</th><th class="num">Lewat target</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($perPos as $p)
                        <tr wire:key="pp-{{ $p['station_id'] }}">
                            <td><b>{{ $p['pos'] }}</b>{!! $p['opsional'] ? ' <span class="lp-muted">opsional</span>' : '' !!}</td>
                            <td class="num">{{ $p['target_menit'] ? $p['target_menit'].' mnt' : '—' }}</td>
                            <td class="num">{{ $p['kunjungan'] }}</td>
                            <td class="num">{{ $p['selesai'] }}{!! $p['berjalan'] ? ' <span class="lp-muted">+'.$p['berjalan'].' berjalan</span>' : '' !!}</td>
                            <td class="num">{{ $d($p['layanan_rata']) }}</td>
                            <td class="num">{{ $d($p['layanan_median']) }}</td>
                            <td class="num">{{ $d($p['layanan_p90']) }}</td>
                            <td class="num">{{ $d($p['tunggu_rata']) }}</td>
                            <td class="num">{{ $d($p['tunggu_p90']) }}</td>
                            <td class="num">@if ($p['lewat_target'] === null) — @else <span @class(['lp-late' => $p['lewat_target'] > 0])>{{ $p['lewat_target'] }}</span> <span class="lp-muted">({{ $p['lewat_target_persen'] ?? 0 }}%)</span> @endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="lp-muted">Tidak ada pos.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        {{-- Throughput per jam --}}
        @php $maks = max(1, collect($throughput)->max(fn ($t) => max([$t['tuntas'], ...array_values($t['per_pos'] ?: [0])]))); @endphp
        <x-filament::section heading="Throughput per jam" description="Kunjungan yang selesai per pos per jam, dan peserta yang tuntas semua pos.">
            <div class="lp-wrap">
                <table class="lp-tbl">
                    <thead><tr>
                        <th>Jam</th>
                        @foreach ($stations as $s) <th class="num">{{ $s->name }}</th> @endforeach
                        <th class="num">Tuntas</th><th></th>
                    </tr></thead>
                    <tbody>
                    @forelse ($throughput as $t)
                        <tr>
                            <td>{{ \Illuminate\Support\Carbon::parse($t['jam'])->format('H:00') }}–{{ \Illuminate\Support\Carbon::parse($t['jam'])->addHour()->format('H:00') }}</td>
                            @foreach ($stations as $s) <td class="num">{{ $t['per_pos'][$s->id] ?? 0 }}</td> @endforeach
                            <td class="num"><b>{{ $t['tuntas'] }}</b></td>
                            <td style="width:30%"><div class="lp-barbg"><div class="lp-barfill" style="width:{{ round($t['tuntas'] * 100 / $maks) }}%"></div></div></td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $stations->count() + 3 }}" class="lp-muted">Belum ada kunjungan yang selesai.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        {{-- Per customer --}}
        <x-filament::section heading="Per customer" description="Lama layanan per pos; merah = lewat target. NUP-NUP milik satu customer dikelompokkan.">
            <div class="lp-wrap">
                <table class="lp-tbl">
                    <thead><tr>
                        <th>Customer</th><th>No. NUP</th><th>Status</th><th class="num">Total</th><th class="num">Tunggu</th>
                        @foreach ($stations as $s) <th class="num">{{ $s->name }}</th> @endforeach
                    </tr></thead>
                    <tbody>
                    @php $kodeSebelum = '__awal__'; @endphp
                    @forelse ($perCustomer as $c)
                        @php $baru = $c['customer_code'] !== $kodeSebelum; $kodeSebelum = $c['customer_code']; @endphp
                        <tr @class(['grup' => $baru && ! $loop->first]) wire:key="pc-{{ $c['participant_id'] }}">
                            <td>@if ($baru)<b>{{ $c['nama'] }}</b><div class="lp-muted">{{ $c['customer_code'] }}</div>@endif</td>
                            <td>{{ $c['no_nup'] ?? '—' }}</td>
                            <td>{{ $statusLabel[$c['status']] ?? $c['status'] }}</td>
                            <td class="num">{{ $d($c['total_detik']) }}</td>
                            <td class="num">{{ $c['tunggu_total_detik'] ? $d($c['tunggu_total_detik']) : '—' }}</td>
                            @foreach ($stations as $s)
                                @php $x = $c['pos'][$s->id] ?? null; $ada = array_key_exists($s->id, $c['pos']) && ! in_array($s->name, $c['dilewati'], true); @endphp
                                <td class="num">
                                    @if (! $ada) <span class="lp-skip">{{ $c['status'] === 'active' ? '' : 'lewat' }}</span>
                                    @elseif ($x === null) <span class="lp-muted">berjalan</span>
                                    @else <span @class(['lp-late' => $s->target_minutes && $x > $s->target_minutes * 60])>{{ $d($x) }}</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ $stations->count() + 5 }}" class="lp-muted">Belum ada peserta.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</div>
</x-filament-panels::page>
