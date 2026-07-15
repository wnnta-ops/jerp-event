<x-filament-panels::page>
    @php
        $dual = $showPreview && ! empty($lastRows);
        $u = auth()->user();
        $inisial = strtoupper(mb_substr($u?->full_name ?? $u?->name ?? 'U', 0, 1));
        $saran = ['Customer booking bulan ini', 'Daftar tunggakan', 'Kontrak terbaru', 'Pembayaran bulan ini'];
    @endphp

    <style>
        .ai-split { display:flex; gap:1rem; align-items:stretch; height:calc(100vh - 9.5rem); min-height:460px; }
        .ai-split.ai-solo { justify-content:center; }
        .ai-solo .ai-chat { flex:0 1 780px; max-width:840px; width:100%; }
        .ai-dual .ai-chat { flex:1 1 42%; min-width:300px; }
        .ai-chat { display:flex; flex-direction:column; }

        /* Pesan */
        .ai-msgs { flex:1; overflow-y:auto; display:flex; flex-direction:column; gap:1rem; padding:.5rem .35rem 1rem; scroll-behavior:smooth; }
        .ai-row { display:flex; gap:.6rem; align-items:flex-end; animation:aiIn .28s cubic-bezier(.2,.7,.3,1); }
        .ai-row-u { justify-content:flex-end; }
        @keyframes aiIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:none; } }
        .ai-av { flex:none; width:2rem; height:2rem; border-radius:50%; display:grid; place-items:center;
            font-size:.78rem; font-weight:800; box-shadow:0 1px 3px rgba(0,0,0,.18); }
        .ai-av-a { background:linear-gradient(135deg,#ffbf69 0%,#ff8c00 55%,#ff6a00 100%); color:#fff; }
        .ai-av-u { background:rgba(148,163,184,.28); color:#e2e8f0; }
        .ai-bubble-a { max-width:82%; background:rgba(148,163,184,.14); border:1px solid rgba(148,163,184,.18);
            padding:.6rem .9rem; border-radius:.25rem 1rem 1rem 1rem; line-height:1.55; }
        .dark .ai-bubble-a { background:rgba(148,163,184,.12); }
        .ai-bubble-u { max-width:80%; background:linear-gradient(135deg,#ff9e3d,#ff7a00); color:#fff;
            padding:.6rem .9rem; border-radius:1rem 1rem .25rem 1rem; box-shadow:0 2px 8px rgba(255,122,0,.22); }

        /* Typing dots */
        .ai-typing { display:inline-flex; gap:5px; padding:.7rem .95rem; background:rgba(148,163,184,.14);
            border-radius:.25rem 1rem 1rem 1rem; align-items:center; }
        .ai-typing span { width:7px; height:7px; border-radius:50%; background:#ff8c00; animation:aiBounce 1s infinite; }
        .ai-typing span:nth-child(2) { animation-delay:.15s; } .ai-typing span:nth-child(3) { animation-delay:.3s; }
        @keyframes aiBounce { 0%,60%,100% { transform:translateY(0); opacity:.45; } 30% { transform:translateY(-6px); opacity:1; } }

        /* Chip saran */
        .ai-suggest { display:flex; flex-wrap:wrap; gap:.5rem; padding:.2rem .35rem .6rem 3rem; }
        .ai-sug { border:1px solid rgba(255,140,0,.4); background:rgba(255,140,0,.06); color:inherit;
            padding:.45rem .8rem; border-radius:999px; font-size:.83rem; cursor:pointer; transition:.15s; }
        .ai-sug:hover { background:rgba(255,140,0,.16); border-color:rgba(255,140,0,.7); transform:translateY(-1px); }

        /* Input */
        .ai-inputbar { display:flex; align-items:flex-end; gap:.5rem; margin-top:.35rem;
            border:1px solid rgba(148,163,184,.35); border-radius:1.25rem; padding:.4rem .5rem .4rem .9rem;
            background:var(--ai-input-bg,rgba(148,163,184,.06)); transition:border-color .15s, box-shadow .15s; }
        .ai-inputbar:focus-within { border-color:#ff8c00; box-shadow:0 0 0 3px rgba(255,140,0,.18); }
        .ai-inputbar textarea { flex:1; resize:none; border:0; background:transparent; outline:none; color:inherit;
            font:inherit; line-height:1.5; max-height:140px; padding:.35rem 0; }
        .ai-send { flex:none; width:2.4rem; height:2.4rem; border-radius:50%; border:0; cursor:pointer;
            display:grid; place-items:center; color:#fff; background:linear-gradient(135deg,#ff9e3d,#ff7a00);
            box-shadow:0 2px 8px rgba(255,122,0,.3); transition:.15s; }
        .ai-send:hover { filter:brightness(1.06); transform:translateY(-1px); }
        .ai-send:disabled { opacity:.5; cursor:not-allowed; transform:none; }

        /* Preview (artifact) */
        .ai-preview { flex:1 1 58%; display:flex; flex-direction:column; border:1px solid rgba(148,163,184,.35);
            border-radius:.9rem; overflow:hidden; background:#fff; box-shadow:0 4px 20px rgba(0,0,0,.06); }
        .dark .ai-preview { background:#18181b; border-color:rgba(148,163,184,.22); }
        .ai-chip { align-self:flex-start; display:inline-flex; align-items:center; gap:.5rem; cursor:pointer; margin-left:2.6rem;
            border:1px solid rgba(148,163,184,.4); border-radius:.7rem; padding:.5rem .75rem; background:rgba(148,163,184,.08); font-size:.82rem; }
        .ai-chip:hover { background:rgba(148,163,184,.16); }
        .ai-prev-head { display:flex; align-items:center; justify-content:space-between; gap:.5rem;
            padding:.6rem .85rem; border-bottom:1px solid rgba(148,163,184,.3); background:rgba(148,163,184,.08); }
        .ai-prev-title { display:flex; align-items:center; gap:.5rem; font-weight:600; font-size:.9rem; min-width:0; }
        .ai-prev-title span { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .ai-prev-actions { display:flex; align-items:center; gap:.4rem; flex:none; }
        .ai-x { display:inline-grid; place-items:center; width:1.9rem; height:1.9rem; border-radius:.45rem; cursor:pointer; opacity:.7; }
        .ai-x:hover { background:rgba(148,163,184,.2); opacity:1; }
        .ai-prev-body { flex:1; overflow:auto; }
        table.ai-sheet { border-collapse:separate; border-spacing:0; width:100%; font-size:.78rem; }
        table.ai-sheet th, table.ai-sheet td { border-right:1px solid rgba(148,163,184,.25);
            border-bottom:1px solid rgba(148,163,184,.25); padding:.35rem .6rem; white-space:nowrap; text-align:left; }
        table.ai-sheet thead th { position:sticky; top:0; z-index:2; background:#f1f5f9; font-weight:600; }
        .dark table.ai-sheet thead th { background:#27272a; }
        table.ai-sheet .ai-idx { position:sticky; left:0; z-index:1; background:#f8fafc; color:#94a3b8; text-align:center; font-weight:500; width:2.4rem; }
        .dark table.ai-sheet .ai-idx { background:#1f1f23; }
        table.ai-sheet thead th.ai-idx { z-index:3; }

        @media (max-width:1024px) {
            .ai-split.ai-dual { flex-direction:column; height:auto; }
            .ai-dual .ai-chat { height:52vh; } .ai-dual .ai-preview { height:58vh; }
        }
        @media (prefers-reduced-motion: reduce) {
            .ai-row, .ai-typing span, .ai-sug, .ai-send { animation:none !important; transition:none !important; }
        }
    </style>

    <div class="ai-split {{ $dual ? 'ai-dual' : 'ai-solo' }}"
         x-data="{
            down() { if (this.$refs.msgs) this.$refs.msgs.scrollTo({ top: this.$refs.msgs.scrollHeight, behavior: 'smooth' }); },
            reset() { if (this.$refs.ta) this.$refs.ta.style.height = 'auto'; }
         }"
         x-init="$nextTick(() => down())"
         @chat-updated.window="$nextTick(() => { down(); reset(); })">

        {{-- ============ CHAT ============ --}}
        <section class="ai-chat">
            <div style="margin-bottom:.5rem;">
                @if ($aiReal)
                    <x-filament::badge color="success">AI aktif</x-filament::badge>
                @else
                    <x-filament::badge color="danger">AI belum dikonfigurasi</x-filament::badge>
                @endif
            </div>

            <div class="ai-msgs" x-ref="msgs">
                @foreach ($chat as $m)
                    @if ($m['role'] === 'user')
                        <div class="ai-row ai-row-u">
                            <div class="ai-bubble-u">{!! $m['html'] !!}</div>
                            <div class="ai-av ai-av-u">{{ $inisial }}</div>
                        </div>
                    @else
                        <div class="ai-row">
                            <div class="ai-av ai-av-a">
                                <svg style="width:1.05rem;height:1.05rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M5 3v4M3 5h4M6 17v4m-2-2h4M13 3l2.5 6.5L22 12l-6.5 2.5L13 21l-2.5-6.5L4 12l6.5-2.5L13 3z"/>
                                </svg>
                            </div>
                            <div class="ai-bubble-a">
                                <div class="prose prose-sm dark:prose-invert" style="max-width:none;">{!! $m['html'] !!}</div>
                            </div>
                        </div>
                    @endif
                @endforeach

                {{-- Chip artifact bila preview ditutup --}}
                @if (! empty($lastRows) && ! $showPreview)
                    <div class="ai-chip" wire:click="bukaPreview" title="Buka preview">
                        <svg style="width:1.1rem;height:1.1rem;color:#16a34a;flex:none;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-6h6v6M4 4h16v16H4zM4 9h16"/>
                        </svg>
                        <span>hasil-pencarian.xlsx · {{ count($lastRows) }} baris — klik untuk buka</span>
                    </div>
                @endif

                {{-- Indikator mengetik --}}
                <div class="ai-row" wire:loading wire:target="kirim,kirimCepat">
                    <div class="ai-av ai-av-a">
                        <svg style="width:1.05rem;height:1.05rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M5 3v4M3 5h4M13 3l2.5 6.5L22 12l-6.5 2.5L13 21l-2.5-6.5L4 12l6.5-2.5L13 3z"/>
                        </svg>
                    </div>
                    <div class="ai-typing"><span></span><span></span><span></span></div>
                </div>
            </div>

            {{-- Chip saran (hanya di awal) --}}
            @if (count($chat) <= 1 && empty($lastRows))
                <div class="ai-suggest">
                    @foreach ($saran as $s)
                        <button type="button" class="ai-sug" wire:click="kirimCepat('{{ $s }}')"
                            wire:loading.attr="disabled" wire:target="kirim,kirimCepat">{{ $s }}</button>
                    @endforeach
                </div>
            @endif

            {{-- Input --}}
            <div class="ai-inputbar">
                <textarea x-ref="ta" wire:model="prompt" rows="1"
                    placeholder="Tulis pesan… mis. cari customer booking Juni 2026"
                    x-on:input="$el.style.height='auto'; $el.style.height=Math.min($el.scrollHeight,140)+'px'"
                    x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $wire.kirim(); }"></textarea>
                <button type="button" class="ai-send" wire:click="kirim"
                    wire:loading.attr="disabled" wire:target="kirim,kirimCepat" title="Kirim">
                    <svg style="width:1.15rem;height:1.15rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M13 6l6 6-6 6"/>
                    </svg>
                </button>
            </div>
        </section>

        {{-- ============ PREVIEW ============ --}}
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
