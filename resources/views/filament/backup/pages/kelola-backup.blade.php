<x-filament-panels::page>
    @php
        $count = count($backupRows);
        $totalBytes = array_sum(array_column($backupRows, 'bytes'));
        $fmt = function ($b) {
            if ($b <= 0) return '0 B';
            $u = ['B', 'KB', 'MB', 'GB', 'TB'];
            $i = min((int) floor(log($b, 1024)), 4);
            return number_format($b / (1024 ** $i), 2).' '.$u[$i];
        };
        $totalSize = $fmt($totalBytes);
        $latest = $count ? $backupRows[0]['date'] : '—';
        $disks = collect($backupRows)->pluck('disk')->unique()->filter()->implode(', ')
            ?: implode(', ', config('backup.backup.destination.disks', ['local']));
        $cat = function ($path) {
            $low = strtolower($path);
            $top = str_contains($path, '/') ? explode('/', $path)[0] : '';
            if (str_contains($low, 'gladi')) return ['Gladi', 'gladi'];
            if (str_contains($low, 'mingguan')) return ['Mingguan', 'mingguan'];
            return [$top !== '' ? $top : 'Rutin', $top !== '' ? 'proyek' : 'rutin'];
        };
    @endphp

    {{-- Panel kemajuan restore. Restore berjalan sebagai proses terpisah supaya tidak
         diputus reverse proxy pada 100 detik; blok ini yang memberi tahu operator
         bahwa prosesnya masih hidup — tanpanya ia akan menekan Restore berulang kali. --}}
    @php $bkLog = $this->restoreLog(); @endphp
    @if ($this->restoreBerjalan() || $bkLog !== '')
        <div wire:poll.5s
             style="border:1px solid rgba(120,120,120,.35); border-radius:.6rem; padding:.9rem 1rem; margin-bottom:1rem;
                    background:{{ $this->restoreBerjalan() ? 'rgba(234,122,29,.08)' : 'rgba(34,197,94,.08)' }}">
            <div style="font-weight:700; margin-bottom:.35rem;">
                @if ($this->restoreBerjalan())
                    Restore sedang berjalan — jangan tutup halaman, jangan ulangi perintah restore.
                @else
                    Restore selesai. Muat ulang halaman untuk melihat data terbaru.
                @endif
            </div>
            <pre style="margin:0; font-size:.75rem; line-height:1.35; white-space:pre-wrap; opacity:.85;">{{ $bkLog }}</pre>
        </div>
    @endif

    <style>
        .bk-stats { display:grid; grid-template-columns:repeat(3,1fr); gap:.9rem; }
        @media(max-width:820px){ .bk-stats{ grid-template-columns:repeat(2,1fr); } }
        .bk-card { display:flex; align-items:center; gap:.85rem; padding:1rem 1.1rem; border-radius:14px;
            border:1px solid rgba(148,163,184,.22); background:rgba(148,163,184,.06); }
        .dark .bk-card { background:rgba(148,163,184,.08); border-color:rgba(148,163,184,.16); }
        .bk-ic { flex:none; width:2.6rem; height:2.6rem; border-radius:12px; display:grid; place-items:center;
            color:#fff; background:linear-gradient(135deg, color-mix(in oklab, var(--c) 78%, #fff 0%), var(--c)); }
        .bk-val { font-size:1.35rem; font-weight:800; line-height:1.1; font-variant-numeric:tabular-nums; }
        .bk-lbl { font-size:.75rem; letter-spacing:.03em; text-transform:uppercase; opacity:.6; margin-top:.15rem; }

        .bk-toolbar { display:flex; align-items:center; gap:.75rem; margin:1.4rem 0 .7rem; flex-wrap:wrap; }
        .bk-search { flex:1 1 280px; display:flex; align-items:center; gap:.5rem; min-width:220px;
            border:1px solid rgba(148,163,184,.3); border-radius:11px; padding:.5rem .8rem;
            background:rgba(148,163,184,.06); transition:border-color .15s, box-shadow .15s; }
        .bk-search:focus-within { border-color:#06b6d4; box-shadow:0 0 0 3px rgba(6,182,212,.18); }
        .bk-search input { flex:1; border:0; outline:0; background:transparent; color:inherit; font:inherit; }
        .bk-search svg { flex:none; opacity:.5; }
        .bk-count { font-size:.82rem; opacity:.65; font-variant-numeric:tabular-nums; white-space:nowrap; }

        .bk-wrap { border:1px solid rgba(148,163,184,.22); border-radius:16px; overflow:hidden;
            background:rgba(148,163,184,.03); }
        table.bk-tbl { width:100%; border-collapse:separate; border-spacing:0; font-size:.86rem; }
        table.bk-tbl thead th { text-align:left; font-size:.7rem; letter-spacing:.06em; text-transform:uppercase;
            opacity:.6; font-weight:700; padding:.7rem 1rem; background:rgba(148,163,184,.08);
            position:sticky; top:0; }
        table.bk-tbl tbody td { padding:.7rem 1rem; border-top:1px solid rgba(148,163,184,.14); vertical-align:middle; }
        table.bk-tbl tbody tr { transition:background .12s; }
        table.bk-tbl tbody tr:hover { background:rgba(6,182,212,.07); }
        .bk-fname { display:flex; align-items:center; gap:.6rem; min-width:0; }
        .bk-fic { flex:none; width:2rem; height:2rem; border-radius:9px; display:grid; place-items:center;
            background:rgba(6,182,212,.14); color:#06b6d4; }
        .bk-dir { opacity:.55; font-family:ui-monospace,monospace; font-size:.8rem; }
        .bk-file { font-family:ui-monospace,monospace; font-weight:600; word-break:break-all; }
        .bk-badge { display:inline-block; font-size:.68rem; font-weight:700; letter-spacing:.03em;
            padding:.2rem .55rem; border-radius:999px; white-space:nowrap; }
        .bk-badge.gladi { background:rgba(168,85,247,.16); color:#c084fc; }
        .bk-badge.mingguan { background:rgba(14,165,233,.16); color:#38bdf8; }
        .bk-badge.proyek { background:rgba(16,185,129,.16); color:#34d399; }
        .bk-badge.rutin { background:rgba(148,163,184,.18); color:#94a3b8; }
        .bk-size { font-variant-numeric:tabular-nums; opacity:.9; white-space:nowrap; }
        .bk-date { opacity:.75; white-space:nowrap; }

        .bk-banner { display:flex; gap:.7rem; align-items:flex-start; padding:.8rem 1rem; border-radius:12px;
            border:1px solid rgba(6,182,212,.25); background:rgba(6,182,212,.08); margin-bottom:1.1rem; font-size:.88rem; }
        .bk-banner-ic { flex:none; color:#06b6d4; margin-top:.1rem; }
        .bk-banner .sub { display:block; opacity:.65; margin-top:.2rem; font-size:.82rem; }
        .bk-banner code, .bk-loc code { font-family:ui-monospace,monospace; background:rgba(148,163,184,.16);
            padding:.05rem .35rem; border-radius:5px; }
        .bk-loc { display:inline-block; max-width:380px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
            font-family:ui-monospace,monospace; font-size:.76rem; opacity:.62; vertical-align:middle; }

        .bk-empty { display:flex; flex-direction:column; align-items:center; gap:.6rem; text-align:center;
            padding:3rem 1rem; }
        .bk-empty .ic { width:3.2rem; height:3.2rem; border-radius:16px; display:grid; place-items:center;
            background:rgba(6,182,212,.14); color:#06b6d4; }
    </style>

    {{-- Banner info (pengganti subheading yang sesak di header) --}}
    <div class="bk-banner">
        <span class="bk-banner-ic">
            <svg style="width:1.15rem;height:1.15rem" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </span>
        <div>
            <strong>Alur aman:</strong> Backup → Gladi → Restore — untuk mereset data setelah gladi kotor.
            <span class="sub">Lokasi: <code>{{ $backupLocation ?: $disks }}</code></span>
        </div>
    </div>

    @if ($count === 0)
        {{-- Empty state --}}
        <div class="bk-wrap"><div class="bk-empty">
            <div class="ic">
                <svg style="width:1.7rem;height:1.7rem" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M21 8H3V6a2 2 0 012-2h14a2 2 0 012 2v2zM4 8v11a2 2 0 002 2h12a2 2 0 002-2V8M9.5 12h5"/></svg>
            </div>
            <div style="font-size:1.05rem;font-weight:700">Belum ada backup</div>
            <div style="opacity:.6;max-width:32ch">Klik <strong>Jalankan backup sekarang</strong> di kanan atas untuk membuat backup pertama.</div>
        </div></div>
    @else
        {{-- Kartu ringkasan --}}
        <div class="bk-stats">
            <div class="bk-card"><div class="bk-ic" style="--c:#06b6d4">
                <svg style="width:1.3rem;height:1.3rem" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M21 8H3V6a2 2 0 012-2h14a2 2 0 012 2v2zM4 8v11a2 2 0 002 2h12a2 2 0 002-2V8M9.5 12h5"/></svg>
            </div><div><div class="bk-val">{{ $count }}</div><div class="bk-lbl">Total backup</div></div></div>

            <div class="bk-card"><div class="bk-ic" style="--c:#8b5cf6">
                <svg style="width:1.3rem;height:1.3rem" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M4 7c0-1.66 3.58-3 8-3s8 1.34 8 3-3.58 3-8 3-8-1.34-8-3zM4 7v10c0 1.66 3.58 3 8 3s8-1.34 8-3V7M4 12c0 1.66 3.58 3 8 3s8-1.34 8-3"/></svg>
            </div><div><div class="bk-val">{{ $totalSize }}</div><div class="bk-lbl">Total ukuran</div></div></div>

            <div class="bk-card"><div class="bk-ic" style="--c:#10b981">
                <svg style="width:1.3rem;height:1.3rem" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div><div><div class="bk-val" style="font-size:1rem">{{ $latest }}</div><div class="bk-lbl">Backup terakhir</div></div></div>
        </div>

        <div x-data="{
                q: '',
                all: {{ \Illuminate\Support\Js::from(collect($backupRows)->pluck('filename')->map(fn ($n) => strtolower($n))->values()) }},
                get shown() { return this.q ? this.all.filter(n => n.includes(this.q.toLowerCase())).length : this.all.length; }
             }">
            {{-- Toolbar cari --}}
            <div class="bk-toolbar">
                <div class="bk-search">
                    <svg style="width:1.1rem;height:1.1rem" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 21l-4.3-4.3M11 19a8 8 0 100-16 8 8 0 000 16z"/></svg>
                    <input type="text" x-model="q" placeholder="Cari nama / folder backup…">
                </div>
                <span class="bk-count"><span x-text="shown"></span> dari {{ $count }} file</span>
            </div>

            {{-- Daftar --}}
            <div class="bk-wrap" style="overflow-x:auto">
                <table class="bk-tbl">
                    <thead><tr>
                        <th>File</th><th>Kategori</th><th>Lokasi</th><th style="text-align:right">Ukuran</th><th>Tanggal</th><th style="text-align:right">Aksi</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($backupRows as $row)
                            @php [$catLabel, $catKind] = $cat($row['path']); $dir = dirname($row['path']); $dir = $dir === '.' ? '' : $dir; $name = basename($row['path']); @endphp
                            <tr data-name="{{ strtolower($row['filename']) }}" x-show="!q || $el.dataset.name.includes(q.toLowerCase())">
                                <td><div class="bk-fname">
                                    <span class="bk-fic"><svg style="width:1.05rem;height:1.05rem" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg></span>
                                    <span style="min-width:0">@if($dir)<span class="bk-dir">{{ $dir }}/</span>@endif<span class="bk-file">{{ $name }}</span></span>
                                </div></td>
                                <td><span class="bk-badge {{ $catKind }}">{{ $catLabel }}</span></td>
                                <td><span class="bk-loc" title="{{ $row['location'] }}">{{ $row['location'] }}</span></td>
                                <td style="text-align:right"><span class="bk-size">{{ $row['size'] }}</span></td>
                                <td><span class="bk-date">{{ $row['date'] }}</span></td>
                                <td style="text-align:right">
                                    <x-filament::button size="sm" color="success" icon="heroicon-o-arrow-down-tray"
                                        {{-- Jalur di-base64: nama berkas backup memuat spasi & tanda kutip yang
                                             memutus atribut HTML sehingga Alpine gagal mem-parse ekspresinya. --}}
                                        wire:click="download('{{ $row['disk'] }}', '{{ base64_encode($row['path']) }}')">
                                        Download
                                    </x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                        <tr x-show="shown === 0"><td colspan="6" style="text-align:center;padding:2rem;opacity:.6">
                            Tak ada backup yang cocok dengan pencarian.
                        </td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-filament-panels::page>
