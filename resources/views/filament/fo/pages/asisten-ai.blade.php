<x-filament-panels::page>
    @php $dual = $showPreview && ! empty($lastRows); @endphp

    <style>
        .ai-wrap { display:flex; gap:1rem; align-items:stretch; height:calc(100vh - 9.5rem); min-height:460px; }
        .ai-wrap.ai-solo { justify-content:center; }
        .ai-solo .ai-chat { flex:0 1 760px; max-width:820px; width:100%; }
        .ai-dual .ai-chat { flex:1 1 42%; min-width:300px; }
        .ai-chat { display:flex; flex-direction:column; }

        .ai-preview { flex:1 1 58%; display:flex; flex-direction:column; border:1px solid rgba(148,163,184,.35);
            border-radius:.75rem; overflow:hidden; background:#fff; }
        .dark .ai-preview { background:#18181b; border-color:rgba(148,163,184,.22); }

        .ai-msgs { flex:1; overflow-y:auto; display:flex; flex-direction:column; gap:.7rem; padding:.4rem .25rem; }
        .ai-bubble-u { align-self:flex-end; max-width:82%; background:#2563eb; color:#fff;
            padding:.55rem .8rem; border-radius:.8rem .8rem .15rem .8rem; }
        .ai-bubble-a { align-self:flex-start; max-width:88%; background:rgba(148,163,184,.16);
            padding:.55rem .8rem; border-radius:.8rem .8rem .8rem .15rem; }
        .dark .ai-bubble-a { background:rgba(148,163,184,.14); }

        .ai-chip { align-self:flex-start; display:inline-flex; align-items:center; gap:.5rem; cursor:pointer;
            border:1px solid rgba(148,163,184,.4); border-radius:.6rem; padding:.5rem .7rem; background:rgba(148,163,184,.08);
            font-size:.82rem; }
        .ai-chip:hover { background:rgba(148,163,184,.16); }

        .ai-input { display:flex; gap:.5rem; align-items:flex-end; padding-top:.7rem; }
        .ai-input textarea { flex:1; resize:none; border-radius:.6rem; border:1px solid rgba(148,163,184,.4);
            background:transparent; padding:.55rem .7rem; font:inherit; color:inherit; }

        .ai-prev-head { display:flex; align-items:center; justify-content:space-between; gap:.5rem;
            padding:.55rem .8rem; border-bottom:1px solid rgba(148,163,184,.3); background:rgba(148,163,184,.08); }
        .ai-prev-title { display:flex; align-items:center; gap:.5rem; font-weight:600; font-size:.9rem; min-width:0; }
        .ai-prev-title span { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .ai-prev-actions { display:flex; align-items:center; gap:.4rem; flex:none; }
        .ai-x { display:inline-grid; place-items:center; width:1.9rem; height:1.9rem; border-radius:.45rem; cursor:pointer;
            color:inherit; opacity:.7; }
        .ai-x:hover { background:rgba(148,163,184,.2); opacity:1; }
        .ai-prev-body { flex:1; overflow:auto; }

        table.ai-sheet { border-collapse:separate; border-spacing:0; width:100%; font-size:.78rem; }
        table.ai-sheet th, table.ai-sheet td { border-right:1px solid rgba(148,163,184,.25);
            border-bottom:1px solid rgba(148,163,184,.25); padding:.35rem .6rem; white-space:nowrap; text-align:left; }
        table.ai-sheet thead th { position:sticky; top:0; z-index:2; background:#f1f5f9; font-weight:600; }
        .dark table.ai-sheet thead th { background:#27272a; }
        table.ai-sheet .ai-idx { position:sticky; left:0; z-index:1; background:#f8fafc; color:#94a3b8;
            text-align:center; font-weight:500; width:2.4rem; }
        .dark table.ai-sheet .ai-idx { background:#1f1f23; }
        table.ai-sheet thead th.ai-idx { z-index:3; }

        @media (max-width:1024px) {
            .ai-wrap.ai-dual { flex-direction:column; height:auto; }
            .ai-dual .ai-chat { height:52vh; } .ai-dual .ai-preview { height:58vh; }
        }
    </style>

    <div class="ai-wrap {{ $dual ? 'ai-dual' : 'ai-solo' }}">
        {{-- ============ CHAT ============ --}}
        <section class="ai-chat">
            <div style="margin-bottom:.5rem;">
                @if ($aiReal)
                    <x-filament::badge color="success">Mode AI penuh</x-filament::badge>
                @else
                    <x-filament::badge color="warning">Mode dasar — isi ANTHROPIC_API_KEY untuk AI penuh</x-filament::badge>
                @endif
            </div>

            <div class="ai-msgs">
                @foreach ($chat as $m)
                    @if ($m['role'] === 'user')
                        <div class="ai-bubble-u">{!! $m['html'] !!}</div>
                    @else
                        <div class="ai-bubble-a">
                            <div class="prose prose-sm dark:prose-invert" style="max-width:none;">{!! $m['html'] !!}</div>
                        </div>
                    @endif
                @endforeach

                {{-- Chip artifact: muncul bila ada hasil tapi panel preview sedang ditutup --}}
                @if (! empty($lastRows) && ! $showPreview)
                    <div class="ai-chip" wire:click="bukaPreview" title="Buka preview">
                        <svg style="width:1.1rem;height:1.1rem;color:#16a34a;flex:none;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-6h6v6M4 4h16v16H4zM4 9h16"/>
                        </svg>
                        <span>hasil-pencarian.xlsx · {{ count($lastRows) }} baris — klik untuk buka preview</span>
                    </div>
                @endif

                <div wire:loading wire:target="kirim" style="align-self:flex-start;font-size:.85rem;opacity:.7;">
                    Asisten sedang mengetik…
                </div>
            </div>

            <div class="ai-input">
                <textarea wire:model="prompt" wire:keydown.enter.prevent="kirim" rows="2"
                    placeholder="Tulis pesan… mis. cari customer booking Juni 2026"></textarea>
                <x-filament::button wire:click="kirim" wire:loading.attr="disabled" wire:target="kirim"
                    icon="heroicon-o-paper-airplane">Kirim</x-filament::button>
            </div>
        </section>

        {{-- ============ PREVIEW (artifact) — hanya saat ada dokumen ============ --}}
        @if ($dual)
            <section class="ai-preview">
                <div class="ai-prev-head">
                    <div class="ai-prev-title">
                        <svg style="width:1.1rem;height:1.1rem;color:#16a34a;flex:none;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-6h6v6M4 4h16v16H4zM4 9h16"/>
                        </svg>
                        <span>hasil-pencarian.xlsx · {{ count($lastRows) }} baris</span>
                    </div>
                    <div class="ai-prev-actions">
                        <x-filament::button wire:click="unduhExcel" color="success" size="sm" icon="heroicon-o-arrow-down-tray">
                            Download XLSX
                        </x-filament::button>
                        <span class="ai-x" wire:click="tutupPreview" title="Tutup preview">
                            <svg style="width:1.1rem;height:1.1rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </span>
                    </div>
                </div>

                <div class="ai-prev-body">
                    <table class="ai-sheet">
                        <thead>
                            <tr>
                                <th class="ai-idx"></th>
                                @foreach ($lastCols as $c)
                                    <th>{{ $c }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($lastRows as $i => $row)
                                <tr>
                                    <th class="ai-idx">{{ $i + 1 }}</th>
                                    @foreach ($lastCols as $c)
                                        <td>{{ $row[$c] ?? '—' }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
</x-filament-panels::page>
