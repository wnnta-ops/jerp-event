{{-- Tabel stok visual per blok. Var: $grid (dari stockGridData). Klik sel TERSEDIA → selectGridUnit($id). --}}
<div>
    @if (empty($grid['blok']) && empty($grid['lainnya']))
        <div class="rounded-lg border p-6 text-center text-sm text-gray-500" style="border-color: rgb(148 163 184 / .3);">
            Pilih <strong>cluster</strong> (dan tahap) untuk menampilkan tabel stok.
        </div>
    @else
        @php
            $warna = [
                'TERSEDIA' => 'sg-tersedia', 'BOOKING' => 'sg-booking', 'TERJUAL' => 'sg-terjual', 'INI' => 'sg-ini',
            ];
            $aktif = fn ($b) => $b['tersedia'] > 0 || $b['booking'] > 0
                || collect($b['baris'])->flatten(2)->contains(fn ($s) => is_array($s) && $s['status'] === 'INI');
            $blokAktif = array_values(array_filter($grid['blok'], $aktif));
            $blokHabis = array_values(array_filter($grid['blok'], fn ($b) => ! $aktif($b)));
        @endphp

        <style>
            .sg-wrap { max-height: 70vh; overflow: auto; border-radius: .75rem; border: 1px solid rgb(148 163 184 / .25); }
            .sg-tbl { border-collapse: separate; border-spacing: 0; width: 100%; font-size: 11px; }
            .sg-tbl th { position: sticky; top: 0; z-index: 2; background: rgb(241 245 249); color: rgb(71 85 105); font-weight: 600; padding: 6px 2px; text-align: center; border-bottom: 1px solid rgb(148 163 184 / .3); }
            .sg-tbl th.sg-blok, .sg-tbl td.sg-blok { position: sticky; left: 0; z-index: 1; text-align: left; padding: 4px 10px; white-space: nowrap; font-weight: 700; background: rgb(248 250 252); color: rgb(51 65 85); border-right: 1px solid rgb(148 163 184 / .3); }
            .sg-tbl th.sg-blok { z-index: 3; }
            .sg-tbl td { padding: 2px 1px; border-bottom: 1px solid rgb(148 163 184 / .12); }
            .sg-tbl td.sg-sisa { padding: 4px 8px; white-space: nowrap; text-align: right; color: rgb(100 116 139); }
            .sg-tbl tr:hover td { background: rgb(148 163 184 / .06); }
            .sg-isi { display: flex; gap: 2px; }
            .sg-sel { flex: 1; min-width: 25px; height: 26px; padding: 0 2px; border-radius: 6px; border: 1px solid transparent; font-weight: 700; font-size: 10.5px; line-height: 1; transition: transform .1s, box-shadow .1s, opacity .15s; }
            .sg-tersedia { background: #16a34a; color: #fff; cursor: pointer; box-shadow: 0 1px 2px rgb(22 163 74 / .35); }
            .sg-tersedia:hover { transform: translateY(-1px) scale(1.06); box-shadow: 0 4px 10px rgb(22 163 74 / .45); }
            .sg-booking { background: rgb(234 179 8 / .22); color: #a16207; border-color: rgb(234 179 8 / .55); cursor: not-allowed; }
            .sg-terjual { background: rgb(220 38 38 / .12); color: #b91c1c; border-color: rgb(220 38 38 / .25); cursor: not-allowed; }
            .sg-ini { background: #2563eb; color: #fff; border: 2px solid #1e3a8a; cursor: default; }
            .sg-lain { background: rgb(148 163 184 / .2); color: rgb(71 85 105); cursor: not-allowed; }
            .sg-redup { opacity: .18; }
            .sg-cocok { outline: 3px solid #f59e0b; outline-offset: 1px; position: relative; z-index: 1; }
            .sg-chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; border: 1px solid rgb(148 163 184 / .35); font-size: 12px; }
            .sg-chip.on { border-color: #16a34a; background: rgb(22 163 74 / .1); color: #15803d; }
            .sg-dot { width: 10px; height: 10px; border-radius: 3px; display: inline-block; }
            .sg-habis-bar { cursor: pointer; padding: 8px 10px; font-size: 12px; color: rgb(100 116 139); background: rgb(148 163 184 / .08); }
            .dark .sg-tbl th, .dark .sg-tbl td.sg-blok, .dark .sg-tbl th.sg-blok { background: rgb(30 41 59); color: rgb(203 213 225); }
            .dark .sg-terjual { color: #fca5a5; }
            .dark .sg-booking { color: #fde047; }
        </style>

        <div x-data="{ q: '', tersediaSaja: false, tampilHabis: false,
                cocok(c) { const t = this.q.trim().toLowerCase(); return t !== '' && c.includes(t); } }">
            {{-- Ringkasan + kontrol --}}
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <span class="sg-chip"><span class="sg-dot" style="background:#16a34a"></span>Tersedia <strong>{{ $grid['stat']['TERSEDIA'] ?? 0 }}</strong></span>
                <span class="sg-chip"><span class="sg-dot" style="background:#eab308"></span>Booking <strong>{{ $grid['stat']['BOOKING'] ?? 0 }}</strong></span>
                <span class="sg-chip"><span class="sg-dot" style="background:#dc2626"></span>Terjual <strong>{{ $grid['stat']['TERJUAL'] ?? 0 }}</strong></span>
                <span class="sg-chip"><span class="sg-dot" style="background:#2563eb"></span>Unit ini</span>
                <span class="text-xs text-gray-500">Total {{ $grid['total'] }} unit</span>

                <div class="ms-auto flex flex-wrap items-center gap-2">
                    <input type="search" x-model="q" placeholder="Cari unit, mis. XV-20"
                        class="rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:border-gray-700" style="padding:.35rem .7rem;width:190px;">
                    <button type="button" class="sg-chip" :class="tersediaSaja && 'on'" @click="tersediaSaja = !tersediaSaja">
                        <span x-text="tersediaSaja ? '✓' : '○'"></span> Tersedia saja
                    </button>
                </div>
            </div>

            <div class="sg-wrap">
                <table class="sg-tbl">
                    <thead>
                        <tr>
                            <th class="sg-blok">Blok</th>
                            @foreach ($grid['cols'] as $c)
                                <th>{{ $c }}</th>
                            @endforeach
                            <th style="text-align:right;padding-right:8px;">Sisa</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ([[$blokAktif, false], [$blokHabis, true]] as [$daftar, $habis])
                            @if ($habis && count($blokHabis))
                                <tr>
                                    <td colspan="{{ count($grid['cols']) + 2 }}" class="sg-habis-bar" @click="tampilHabis = !tampilHabis">
                                        <span x-text="tampilHabis ? '▾' : '▸'"></span>
                                        {{ count($blokHabis) }} blok sudah habis
                                        ({{ \Illuminate\Support\Str::limit(collect($blokHabis)->pluck('nama')->implode(', '), 80) }})
                                        — <span x-text="tampilHabis ? 'sembunyikan' : 'klik untuk tampilkan'"></span>
                                    </td>
                                </tr>
                            @endif
                            @foreach ($daftar as $b)
                                @foreach ($b['baris'] as $k => $baris)
                                    @php $cariBaris = collect($baris)->flatten(1)->pluck('cari')->implode('|'); @endphp
                                    <tr @if ($habis) x-show="tampilHabis || cocok(@js($cariBaris))" x-cloak @endif>
                                        <td class="sg-blok">
                                            @if ($k === array_key_first($b['baris']))
                                                {{ $b['nama'] }}
                                            @else
                                                <span class="text-gray-400">└ {{ $k * count($grid['cols']) + 1 }}–{{ ($k + 1) * count($grid['cols']) }}</span>
                                            @endif
                                        </td>
                                        @foreach ($grid['cols'] as $c)
                                            <td>
                                                @if (! empty($baris[$c]))
                                                    <div class="sg-isi">
                                                        @foreach ($baris[$c] as $sel)
                                                            @php $bisa = $sel['status'] === 'TERSEDIA'; @endphp
                                                            <button type="button"
                                                                class="sg-sel {{ $warna[$sel['status']] ?? 'sg-lain' }}"
                                                                :class="{ 'sg-redup': tersediaSaja && {{ $bisa ? 'false' : 'true' }} && ! cocok(@js($sel['cari'])), 'sg-cocok': cocok(@js($sel['cari'])) }"
                                                                @if ($bisa) wire:click="selectGridUnit({{ $sel['id'] }})" @else disabled @endif
                                                                title="{{ $sel['title'] }}">{{ $sel['no'] }}</button>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </td>
                                        @endforeach
                                        <td class="sg-sisa">
                                            @if ($k === array_key_first($b['baris']))
                                                @if ($habis)
                                                    habis
                                                @else
                                                    <strong style="color:#16a34a">{{ $b['tersedia'] }}</strong>/{{ $b['total'] }}
                                                @endif
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if (! empty($grid['lainnya']))
                <div class="mt-3">
                    <div class="mb-1 text-xs font-semibold text-gray-500">Lainnya — kode unit tidak berformat Cluster-Tahap-Blok-Nomor ({{ count($grid['lainnya']) }})</div>
                    <div class="flex flex-wrap gap-1">
                        @foreach ($grid['lainnya'] as $sel)
                            @php $bisa = $sel['status'] === 'TERSEDIA'; @endphp
                            <button type="button" style="flex:none;padding:0 8px;"
                                class="sg-sel {{ $warna[$sel['status']] ?? 'sg-lain' }}"
                                :class="{ 'sg-redup': tersediaSaja && {{ $bisa ? 'false' : 'true' }} && ! cocok(@js($sel['cari'])), 'sg-cocok': cocok(@js($sel['cari'])) }"
                                @if ($bisa) wire:click="selectGridUnit({{ $sel['id'] }})" @else disabled @endif
                                title="{{ $sel['title'] }}">{{ $sel['kode'] }}</button>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
