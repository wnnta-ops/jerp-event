<x-filament-panels::page>
    @php
        $clusters = $this->getClusters();
        $units = $this->getUnits();
        $project = $this->getProject();
        $siteplan = $this->getSiteplan();
        $blocks = $this->getBlocks();
    @endphp

    <div>
    {{-- Leaflet + html5-qrcode (loaded once) — inline supaya selalu siap --}}
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    {{-- window.Echo (Reverb) untuk update SOLD real-time — sama channel dgn /siteplan. --}}
    @vite(['resources/js/app.js'])

    <style>
        .pu-cluster {
            position: relative; overflow: hidden; border-radius: 1rem;
            transition: transform .3s cubic-bezier(.34,1.56,.64,1), box-shadow .3s;
            box-shadow: 0 4px 14px rgba(0,0,0,.10), inset 0 1px 0 rgba(255,255,255,.18);
            cursor: pointer; min-height: 160px;
        }
        .pu-cluster:hover { transform: translateY(-5px) scale(1.02); box-shadow: 0 20px 40px -10px rgba(0,0,0,.3); }
        .pu-stage { position: relative; }
        #pu-map { width: 100%; height: calc(100vh - 200px); min-height: 520px; border-radius: 1rem; overflow: hidden; background: #1e293b; border: 1px solid rgba(255,255,255,.1); }
        /* position:relative WAJIB — lapisan bayangan kotak bidik html5-qrcode berposisi
           absolute; tanpa ini ia menempel ke panel & meluber menutupi seluruh panel
           (bug "panel gelap + kotak putih" sesudah pilih unit). */
        #pu-scanner { position: relative; width: 100%; aspect-ratio: 4 / 3; border-radius: .75rem; overflow: hidden; background: #0f172a; border: 1px solid rgba(255,255,255,.15); }
        #pu-scanner video { object-fit: cover; }
        .pu-panel { background: var(--gray-50, rgb(249 250 251)); border: 1px solid var(--gray-200, rgb(229 231 235)); border-radius: 1rem; padding: 1rem; }
        .dark .pu-panel { background: rgba(30,41,59,.6); border-color: rgba(255,255,255,.1); }
        /* Panel mengambang di pojok kiri bawah, di atas siteplan */
        /* Tanpa backdrop-filter: blur di atas peta Leaflet yang ber-transform membuat
           Chrome HP/tablet salah gambar (kotak putih/abu di panel). Latar dibuat pekat. */
        .pu-float { position: absolute; left: 1rem; bottom: 1rem; z-index: 600; width: 340px; max-width: calc(100% - 2rem); box-shadow: 0 18px 44px -10px rgba(0,0,0,.55); }
        .pu-float.pu-panel { background: rgba(255,255,255,.98); }
        .dark .pu-float.pu-panel { background: rgba(15,23,42,.97); border-color: rgba(255,255,255,.14); }
        .pu-min-btn { font-size: 18px; line-height: 1; padding: 0 6px; color: #94a3b8; }
        .pu-min-btn:hover { color: #334155; }
        /* Panel diperkecil → tombol kecil di pojok kiri bawah. */
        .pu-mini { position: absolute; left: 1rem; bottom: 1rem; z-index: 600; display: flex; align-items: center; gap: 8px;
            padding: 10px 14px; border-radius: 999px; background: #2563eb; color: #fff; font-weight: 700; font-size: 13px;
            box-shadow: 0 10px 28px -6px rgba(0,0,0,.55); border: 0; cursor: pointer; max-width: calc(100% - 2rem); }
        .pu-mini span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pu-scan-msg { margin-top: .5rem; border-radius: .5rem; padding: .4rem .6rem; font-size: 12px; line-height: 1.35; }
        .pu-scan-msg.err { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

        /* HP / tablet kecil: panel bawah selebar layar & tak menutup separuh peta. */
        @media (max-width: 640px) {
            .pu-float.pu-bottom { left: .5rem; right: .5rem; bottom: .5rem; width: auto; max-width: none; max-height: 60vh; overflow-y: auto; padding: .75rem; }
            .pu-bottom #pu-scanner { width: 72%; margin: 0 auto; }
            .pu-mini { left: .5rem; bottom: .5rem; }
            .pu-bottom .pu-timer { font-size: 1.75rem; padding: .6rem; }
        }

        /* Full layar seperti /siteplan: stage menutupi seluruh viewport, map isi penuh.
           Panel scan (kiri-bawah) & Ganti Cluster (kanan-atas) mengambang di atasnya. */
        .pu-stage.pu-fullscreen { position: fixed; inset: 0; z-index: 40; }
        .pu-stage.pu-fullscreen > #pu-map { height: 100%; min-height: 0; border-radius: 0; border: 0; }

        /* Popup info — kartu putih meniru /siteplan (lebih enak dilihat). */
        .leaflet-popup-content-wrapper { background:#fff; color:#0f172a; border-radius:16px; padding:0; box-shadow:0 20px 50px rgba(15,23,42,.25); border:1px solid #e2e8f0; }
        .leaflet-popup-tip { background:#fff; border:1px solid #e2e8f0; box-shadow:none; }
        .leaflet-popup-content { margin:0; min-width:min(330px, 78vw); }
        .leaflet-popup-close-button { color:#94a3b8 !important; font-size:20px !important; width:26px !important; height:26px !important; padding:4px 0 0 0 !important; }
        .leaflet-popup-close-button:hover { color:#334155 !important; }
        .sp-card { padding:16px; font-family:inherit; }
        .sp-head { display:flex; align-items:center; justify-content:space-between; gap:10px; }
        .sp-code { font-size:17px; font-weight:800; color:#0f172a; letter-spacing:.2px; }
        .sp-pill { font-size:10px; font-weight:800; letter-spacing:.6px; padding:4px 10px; border-radius:999px; color:#fff; white-space:nowrap; box-shadow:0 2px 6px rgba(0,0,0,.12); }
        .sp-loc { font-size:11.5px; color:#64748b; margin-top:2px; }
        .sp-sub { font-size:12.5px; color:#475569; margin-top:8px; }
        .sp-sub b { color:#0f172a; font-weight:700; }
        .sp-addr { display:flex; gap:6px; font-size:11.5px; color:#64748b; line-height:1.45; margin-top:6px; }
        .sp-addr .pin { flex:0 0 auto; }
        .sp-prices { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-top:13px; }
        .sp-prices.one { grid-template-columns:1fr; }
        .sp-prices.two { grid-template-columns:repeat(2,1fr); }
        .sp-pc { background:#f8fafc; border:1px solid #e7ebf0; border-radius:11px; padding:9px 5px; text-align:center; transition:transform .12s, box-shadow .12s; }
        .sp-pc:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(15,23,42,.10); }
        .sp-pc .lab { font-size:9.5px; font-weight:800; letter-spacing:.4px; color:#2563eb; margin-bottom:4px; }
        .sp-pc .cur { font-size:8px; font-weight:700; color:#94a3b8; line-height:1; }
        .sp-pc .amt { font-size:12px; font-weight:800; color:#0f172a; line-height:1.15; white-space:nowrap; }
        /* Dropdown kamera: appearance dimatikan + panah digambar sendiri, sebab
           gambar panah bawaan Filament ter-ubin bila background di-override.
           color-scheme dark → daftar pilihan native ikut gelap (kalau tidak,
           teksnya gelap di atas putih & tak terbaca). */
        .pu-cam-select { width:100%; border-radius:8px; padding:6px 28px 6px 10px; font-size:12px;
            border:1px solid rgb(203 213 225); background-color:#fff; color:#0f172a;
            appearance:none; -webkit-appearance:none; -moz-appearance:none;
            background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20' stroke='%236b7280' stroke-width='1.75'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
            background-repeat:no-repeat; background-position:right 8px center; background-size:16px; }
        .dark .pu-cam-select { background-color:rgba(255,255,255,.06); border-color:rgba(255,255,255,.18); color:#e5e7eb; color-scheme:dark; }
        .dark .pu-cam-select option { background-color:#1e293b; color:#e5e7eb; }

        .pu-pilih-btn { margin-top:13px; width:100%; padding:10px 12px; border-radius:10px; background:#2563eb; color:#fff; font-weight:700; font-size:13px; border:0; cursor:pointer; }
        .pu-pilih-btn:hover { background:#1d4ed8; }
        .pu-pilih-btn:disabled { background:#94a3b8; cursor:not-allowed; }

        /* Stempel status unit (SOLD merah / BOOKING oranye) — anchor 0×0 +
           translate(-50%,-50%), ukuran dasar kecil lalu diskalakan ikut zoom. */
        .pu-sold-div { background: transparent !important; border: 0 !important; width: 0 !important; height: 0 !important; overflow: visible; }
        .pu-stamp {
            display: inline-block; color: #fff; font-weight: 800;
            font-size: 4px; letter-spacing: .2px; line-height: 1; padding: .5px 2px; border-radius: 1.5px;
            transform: translate(-50%, -50%) rotate(-12deg); transform-origin: center center;
            border: .5px solid #fff; box-shadow: 0 1px 1.5px rgba(0,0,0,.5);
            white-space: nowrap; text-shadow: 0 1px 1px rgba(0,0,0,.25);
        }
        .pu-stamp.sold { background: #dc2626; }      /* TERJUAL / LUNAS */
        .pu-stamp.booking { background: #d97706; }   /* BOOKING */

        .pu-timer { font-family: ui-monospace, monospace; font-size: 2.5rem; font-weight: 800; text-align: center; padding: 1rem; border-radius: .75rem; background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%); color: white; box-shadow: 0 8px 24px -4px rgba(239,68,68,.5); animation: puTickPulse 1s ease-in-out infinite; }
        @keyframes puTickPulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.02); } }

        .pu-modal { position: fixed; inset: 0; background: rgba(0,0,0,.6); z-index: 99999; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(4px); animation: puFadeIn .25s ease-out; }
        @keyframes puFadeIn { from { opacity: 0; } to { opacity: 1; } }
        .pu-modal-card { background: white; border-radius: 1.25rem; padding: 2.5rem 2rem; max-width: 480px; width: 90%; text-align: center; box-shadow: 0 25px 60px -12px rgba(0,0,0,.4); animation: puPopIn .4s cubic-bezier(.34,1.56,.64,1); }
        .dark .pu-modal-card { background: #1e293b; color: #f1f5f9; }
        @keyframes puPopIn { from { transform: scale(.7) translateY(40px); opacity: 0; } to { transform: scale(1) translateY(0); opacity: 1; } }
        .leaflet-tooltip.pu-block-label { background:transparent; color:#fff; border:0; box-shadow:none; font-weight:800; font-size:15px; letter-spacing:.5px; text-shadow:0 1px 4px rgba(0,0,0,.95); }
    </style>

    {{-- Breadcrumb --}}
    <div class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 flex items-center gap-2 mb-4">
        <a href="{{ url('/fo') }}" class="hover:underline" style="color:#60a5fa;">Dashboard FO</a>
        <span>›</span>
        <a href="{{ url('/fo/launching') }}" class="hover:underline" style="color:#60a5fa;">Launching</a>
        <span>›</span>
        <span>Pilih Unit</span>
        @if ($project)
            <span>›</span>
            <span class="font-semibold" style="color:var(--gray-700);">{{ $this->clusterName ?: $project->name }}</span>
        @endif
    </div>

    @if (! $this->clusterId)
        {{-- ──────────────────────────── STEP 1: Cluster picker ──────────── --}}
        <div class="mb-6">
            <h2 class="text-xl md:text-2xl font-bold">Pilih Cluster</h2>
            <p class="text-sm text-gray-500 mt-1">Tentukan cluster / proyek yang sedang launching.</p>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            @forelse ($clusters as $c)
                <button wire:click="selectCluster({{ $c['lokasi_id'] }})"
                        class="pu-cluster text-left text-white px-5 py-5"
                        style="background: #ff8c00;">
                    <div class="text-xs uppercase tracking-wider opacity-80">{{ $c['project_name'] }}</div>
                    <div class="text-xl font-bold mt-1 leading-tight">{{ $c['label'] }}</div>
                    <div class="mt-4 grid grid-cols-3 gap-2 text-center text-xs">
                        <div class="rounded bg-white/20 px-2 py-1.5"><div class="text-lg font-bold">{{ $c['unit_tersedia'] }}</div><div class="opacity-80">Tersedia</div></div>
                        <div class="rounded bg-white/20 px-2 py-1.5"><div class="text-lg font-bold">{{ $c['unit_booking'] }}</div><div class="opacity-80">Booking</div></div>
                        <div class="rounded bg-white/20 px-2 py-1.5"><div class="text-lg font-bold">{{ $c['unit_terjual'] }}</div><div class="opacity-80">Terjual</div></div>
                    </div>
                </button>
            @empty
                <div class="col-span-full p-8 text-center text-gray-500 border border-dashed rounded-xl">
                    Belum ada cluster aktif.
                </div>
            @endforelse
        </div>
    @else
        {{-- ──────────────── STEP 2+: Siteplan + scanner (FULL LAYAR) ──────────────── --}}
        {{-- x-data WAJIB KONSTAN — tanpa data sama sekali. Dulu payload (units/blocks/
             siteplan) ikut di x-data; begitu isinya berubah antar render (mis. status
             unit berubah oleh operator lain saat launching), string x-data berubah →
             morph Livewire membuat instance Alpine BARU → initLeaflet jalan lagi →
             Leaflet melempar "Map container is already initialized" → instance baru
             pegang map=null → zoom hanya menggerakkan gambar, koordinat/polygon diam
             (bug produksi). Payload pindah ke <script data-pu-payload> (wire:ignore)
             di bawah; data dinamis lain tetap dibaca via $wire. --}}
        <div class="pu-stage pu-fullscreen"
             wire:key="pu-stage"
             x-data="pilihUnitState()"
             x-init="init()"
             x-effect="phase = $wire.phase"
             @pu-scan-gagal.window="onScanGagal()">

            {{-- JANGAN pakai @json di sini: direktifnya memecah argumen pada koma
                 (slot flags/depth) → array literal terpotong → ParseError. JSON_HEX_TAG
                 wajib: literal "<" di inline script memicu false-positive
                 MultipleRootElements Livewire (500). --}}
            <script type="application/json" data-pu-payload wire:ignore>{!! json_encode(['units' => $units, 'blocks' => $blocks, 'siteplan' => $siteplan, 'projectId' => $this->projectId], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>

            {{-- Siteplan full (Leaflet). wire:ignore → Livewire tak men-morph DOM
                 yang disuntik Leaflet (kalau tidak, map hilang tiap scan/aksi). --}}
            <div id="pu-map" wire:ignore></div>

            {{-- Kontrol cluster mengambang (pojok kanan atas), seperti panel kamera --}}
            <div class="pu-float pu-panel" style="left:auto; right:1rem; top:1rem; bottom:auto; width:auto; max-width:calc(100% - 2rem);">
                <div class="flex items-center gap-3">
                    <div>
                        <div class="text-sm font-bold leading-tight">{{ $this->clusterName ?: $project->name }}</div>
                        <div class="text-xs text-gray-500">{{ $project->code }} · {{ count($units) }} unit</div>
                    </div>
                    <button wire:click="backToClusters"
                            class="text-sm rounded-lg border px-3 py-1.5 hover:bg-gray-100 dark:hover:bg-slate-800 transition whitespace-nowrap"
                            style="border-color: var(--gray-300);">
                        ← Ganti Cluster
                    </button>
                </div>
            </div>

            @if (! $siteplan)
                <div class="pu-float pu-panel" style="left:1rem; right:1rem; top:1rem; bottom:auto; width:auto; max-width:none;">
                    <div class="text-sm text-amber-700 dark:text-amber-300">⚠ Site plan untuk project ini belum diupload. Buka <a href="{{ url('/fo/upload-site-plan') }}" class="underline font-medium">Upload Site Plan</a> + <a href="{{ url('/fo/upload-koordinat-editor') }}" class="underline font-medium">Polygon Editor</a> untuk set siteplan + koordinat unit.</div>
                </div>
            @endif

            {{-- ───── Panel mengambang pojok kiri bawah (scanner / customer / timer) ───── --}}
            {{-- Phase: SCAN --}}
            {{-- Panel diperkecil: tombol kecil (isi menyesuaikan fase) --}}
            <button x-show="panelMin && phase !== 'done'" x-cloak @click="setMin(false)" class="pu-mini" title="Tampilkan panel">
                <span x-text="phase === 'scan' ? '📷 Scan Barcode' : ((phase === 'choosing' ? '⏱ ' + timerDisplay + ' · ' : '👤 ') + ($wire.regCustomerName || ''))"></span>
            </button>

            <div x-show="phase === 'scan' && !panelMin" class="pu-float pu-bottom pu-panel" x-cloak>
                <div class="flex items-center justify-between mb-3">
                    <div class="text-sm font-semibold">📷 Scan Barcode NUP</div>
                    <div class="flex items-center gap-2">
                        <button @click="toggleScanner()" class="text-xs hover:underline" style="color:#60a5fa;">
                            <span x-text="scannerOn ? 'Matikan' : 'Hidupkan'"></span>
                        </button>
                        <button @click="setMin(true)" class="pu-min-btn" title="Perkecil">—</button>
                    </div>
                </div>
                <div id="pu-scanner"></div>
                <div x-show="scanError" x-cloak class="pu-scan-msg err" x-text="scanError"></div>

                {{-- Pemilih kamera: mendukung WEBCAM USB tambahan. Baru muncul bila
                     terdeteksi lebih dari satu kamera. Pilihan diingat per komputer. --}}
                <div class="mt-2" x-show="cameras.length > 1" x-cloak>
                    <select x-model="camId" @change="gantiKamera()" class="pu-cam-select">
                        <option value="">Kamera bawaan (belakang)</option>
                        <template x-for="c in cameras" :key="c.id">
                            <option :value="c.id" x-text="c.label"></option>
                        </template>
                    </select>
                </div>

                <p class="text-xs text-gray-500 mt-3">Arahkan barcode customer ke kamera. Atau ketik manual:</p>
                <div class="flex gap-2 mt-2">
                    <input x-model="manualCode" type="text"
                           class="flex-1 rounded-lg border px-3 py-2 text-sm"
                           style="border-color: var(--gray-300);"
                           placeholder="Mis. NUP-00001"
                           @keyup.enter="manualScan()">
                    <button @click="manualScan()" class="rounded-lg px-4 py-2 text-sm font-semibold transition"
                            style="background:#2563eb; color:#fff;"
                            onmouseover="this.style.background='#1d4ed8'" onmouseout="this.style.background='#2563eb'">Cari</button>
                </div>
                @if ($pickError)
                    <div class="mt-3 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $pickError }}</div>
                @endif
            </div>

            {{-- Phase: CUSTOMER --}}
            <div x-show="phase === 'customer' && !panelMin" class="pu-float pu-bottom pu-panel" x-cloak>
                <div class="flex items-center justify-between">
                    <div class="text-xs uppercase tracking-wider text-gray-500">Data Customer</div>
                    <button @click="setMin(true)" class="pu-min-btn" title="Perkecil">—</button>
                </div>
                <div class="text-lg font-bold mt-1" style="color:#0369a1;">{{ $regNoNup }}</div>
                <div class="mt-3 space-y-2 text-sm">
                    <div><div class="text-xs text-gray-500">Nama</div><div class="font-semibold">{{ $regCustomerName }}</div></div>
                    <div><div class="text-xs text-gray-500">Alamat</div><div>{{ $regCustomerAddress }}</div><div class="text-xs text-gray-500">{{ $regCustomerCity }}</div></div>
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div><div class="text-gray-500">Tipe NUP</div><div class="font-semibold">{{ $regTipeNup }}</div></div>
                        @if ($regPilihanUnit1 || $regPilihanUnit2 || $regPilihanUnit3)
                            <div><div class="text-gray-500">Preferensi</div><div class="font-semibold text-xs">{{ collect([$regPilihanUnit1, $regPilihanUnit2, $regPilihanUnit3])->filter()->implode(' · ') }}</div></div>
                        @endif
                    </div>

                    {{-- Referensi dari Verifikasi Data — operator hari-H tak perlu buka menu lain. --}}
                    @if ($regRefSales || $regSumberData || $regEvent || $regCaraBayar)
                        <div class="mt-3 pt-2" style="border-top:1px dashed var(--gray-300);">
                            <div class="text-xs uppercase tracking-wider text-gray-500 mb-1">Referensi</div>
                            <div class="grid grid-cols-2 gap-2 text-xs">
                                @if ($regRefSales)
                                    <div><div class="text-gray-500">Sales / Agen</div><div class="font-semibold">{{ $regRefSales }}{{ $regRefPhone ? ' · '.$regRefPhone : '' }}</div></div>
                                @endif
                                @if ($regSumberData)
                                    <div><div class="text-gray-500">Sumber Info</div><div class="font-semibold">{{ $regSumberData }}</div></div>
                                @endif
                                @if ($regEvent)
                                    <div><div class="text-gray-500">Event</div><div class="font-semibold">{{ $regEvent }}</div></div>
                                @endif
                                @if ($regCaraBayar)
                                    <div><div class="text-gray-500">Cara Bayar</div><div class="font-semibold">{{ $regCaraBayar }}</div></div>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
                {{-- Warna via inline style (bukan bg-emerald-*): kelas Tailwind di blade custom
                     tak ikut ter-compile di CSS panel → tombol jadi putih-di-putih pada mode terang. --}}
                <button wire:click="startChoosing" class="mt-4 w-full rounded-lg px-4 py-3 text-sm font-bold transition shadow-md"
                        style="background:#059669; color:#fff;"
                        onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'">🏠 Pilih Unit (2 menit)</button>
                <button wire:click="resetToScan" class="mt-2 w-full rounded-lg border px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-slate-800 transition" style="border-color: var(--gray-300);">Scan Ulang</button>
            </div>

            {{-- Phase: CHOOSING (timer aktif) --}}
            <div x-show="phase === 'choosing' && !panelMin" class="pu-float pu-bottom pu-panel" x-cloak>
                <div class="flex justify-end -mt-1 -mb-2">
                    <button @click="setMin(true)" class="pu-min-btn" title="Perkecil">—</button>
                </div>
                <div class="text-center mb-3">
                    <div class="text-xs uppercase tracking-wider text-gray-500">{{ $regNoNup }}</div>
                    <div class="text-base font-bold">{{ $regCustomerName }}</div>
                </div>
                <div class="pu-timer" x-text="timerDisplay"></div>
                <p class="text-xs text-center text-gray-500 mt-3">
                    Klik polygon unit di siteplan, lalu klik <strong>Pilih</strong> di info popup.
                </p>
            </div>

            {{-- ────── KONFIRMASI: "Apakah Anda yakin?" ────── --}}
            <div x-show="confirmUnitId !== null" class="pu-modal" x-cloak>
                <div class="pu-modal-card" style="max-width: 440px;">
                    <div class="text-5xl mb-3">🤔</div>
                    <h3 class="text-xl font-bold mb-1">Apakah Anda yakin?</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-300 mb-1">Customer akan mengambil unit:</p>
                    <div class="text-xl font-mono font-bold py-2 px-4 rounded-lg my-2 inline-block" style="background:#dbeafe; color:#1e40af; border: 2px solid #3b82f6;">
                        <span x-text="confirmUnitLabel"></span>
                    </div>
                    <p class="text-xs text-gray-500" x-text="confirmUnitAlamat"></p>
                    <div class="flex gap-2 mt-5">
                        <button @click="confirmUnitId = null" class="flex-1 rounded-lg border px-4 py-2 text-sm hover:bg-gray-100 dark:hover:bg-slate-800 transition" style="border-color: var(--gray-300);">Batal</button>
                        <button @click="doConfirm()" class="flex-1 rounded-lg px-4 py-2 text-sm font-bold transition"
                                style="background:#059669; color:#fff;"
                                onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'">✓ Yakin</button>
                    </div>
                </div>
            </div>
        </div>

        {{-- ────── SELAMAT: setelah unit ter-lock berhasil ────── --}}
        @if ($phase === 'done' && $pickedUnitLabel)
            <div class="pu-modal" wire:key="done-modal">
                <div class="pu-modal-card">
                    <div class="text-6xl mb-3">🎉</div>
                    <h3 class="text-2xl font-bold mb-2">Selamat!</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        Bpk/Ibu <strong>{{ $regCustomerName }}</strong> atas rumah:
                    </p>
                    <div class="text-3xl font-mono font-bold py-3 px-6 rounded-lg my-3 inline-block" style="background:#dcfce7; color:#15803d; border: 2px solid #22c55e;">
                        {{ $pickedUnitLabel }}
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-300 mt-1">
                        Alamat: <strong>{{ $pickedUnitAlamat }}</strong>
                    </p>
                    <button wire:click="resetToScan" class="mt-5 w-full rounded-lg px-6 py-3 text-base font-bold transition shadow-lg"
                            style="background:#2563eb; color:#fff;"
                            onmouseover="this.style.background='#1d4ed8'" onmouseout="this.style.background='#2563eb'">
                        DONE
                    </button>
                </div>
            </div>
        @endif
    @endif

    <script>
        function pilihUnitState() {
            return {
                phase: 'scan',          // disinkron dari server via x-effect="phase = $wire.phase"
                units: [],              // diisi init() dari <script data-pu-payload>
                blocks: [],
                siteplan: null,
                projectId: null,

                scannerOn: false, scannerInstance: null, manualCode: '', scanLocked: false,
                // Pemilihan kamera (mendukung webcam USB tambahan). Pilihan disimpan
                // di localStorage agar operator cukup memilih sekali per komputer.
                cameras: [], camId: '',
                camTersimpan: (() => { try { return localStorage.getItem('pu_cam_id') || ''; } catch (e) { return ''; } })(),
                // Panel bawah diperkecil (diingat per perangkat).
                panelMin: (() => { try { return localStorage.getItem('pu_panel_min') === '1'; } catch (e) { return false; } })(),
                scanError: '',  // kamera gagal menyala (izin/tidak ada/dipakai aplikasi lain)
                timerSec: 120, timerId: null, timerDisplay: '02:00',

                map: null,
                polygons: {}, // unitId → L.Polygon
                soldMarkers: {}, // unitId → L.Marker (stempel SOLD)
                soldStamps: [], // elemen <span> stempel aktif (untuk reskala saat zoom)
                baseZoom: 0,    // zoom saat fit → acuan skala stempel
                frameW: 1500,   // kerangka koordinat peta (px digitasi) — diisi initLeaflet
                frameH: 1000,

                confirmUnitId: null,
                confirmUnitLabel: '',
                confirmUnitAlamat: '',

                init() {
                    // Payload dari <script data-pu-payload> — BUKAN argumen x-data,
                    // supaya string x-data konstan (lihat komentar elemen .pu-stage).
                    try {
                        const p = JSON.parse(this.$el.querySelector('[data-pu-payload]').textContent);
                        this.units = p.units || [];
                        this.blocks = p.blocks || [];
                        this.siteplan = p.siteplan || null;
                        this.projectId = (p.projectId !== undefined) ? p.projectId : null;
                    } catch (e) { console.error('Pilih Unit: payload gagal dibaca', e); }
                    this.camId = this.camTersimpan; // kamera pilihan terakhir operator
                    // Penanda instance TERBARU. Instance lama (mis. sesudah Ganti
                    // Cluster) memakai epoch usang → pekerjaan tertundanya berhenti
                    // sendiri, tak mengganggu instance yang hidup.
                    this._epoch = window.__puEpoch = (window.__puEpoch || 0) + 1;
                    this.$nextTick(() => this.initLeaflet());
                    this.initRealtime();

                    this.$watch('phase', (v) => {
                        if (v === 'scan') { this.scanLocked = false; window.__puLastScan = null; this.nyalakanSaatTampil(); } else this.stopScanner();
                        if (v === 'choosing') this.startTimer(); else this.stopTimer();
                        this.refreshPolygonStyles();
                        // Ganti fase = round-trip Livewire yang mem-MORPH DOM. Sesudah morph,
                        // pane gambar & pane koordinat/SVG Leaflet kadang lepas sinkron →
                        // saat itu zoom seolah hanya menggeser gambar, koordinat diam
                        // (bug "waktu timer jalan"). Paksa re-sinkron penuh: recalc ukuran
                        // lalu 'viewreset' → SEMUA layer (imageOverlay + renderer SVG)
                        // reposisi ulang dari state peta terkini, jadi kembali sejajar.
                        this.$nextTick(() => this.resyncMap());
                    });

                    // Error/timeout kini tampil sebagai notifikasi Filament (toast),
                    // bukan alert() bawaan browser — lihat PilihUnit.php.

                    if (this.phase === 'scan') this.nyalakanSaatTampil();
                    else if (this.phase === 'choosing') this.startTimer();
                },

                /** Perkecil / tampilkan panel bawah. Kamera ikut mati saat diperkecil. */
                setMin(v) {
                    this.panelMin = v;
                    try { localStorage.setItem('pu_panel_min', v ? '1' : '0'); } catch (e) {}
                    if (v) this.stopScanner();
                    else if (this.phase === 'scan') this.nyalakanSaatTampil();
                },

                /**
                 * Nyalakan kamera HANYA setelah kotak scanner benar-benar tampil (lebar > 0).
                 * Dulu kamera dinyalakan saat panel masih tersembunyi (x-show belum
                 * diterapkan sesudah DONE) → html5-qrcode mengukur kotak 0 px → lapisan
                 * bayangan kotak bidik salah ukuran & meluber menutupi panel.
                 */
                nyalakanSaatTampil(attempt = 0) {
                    if (this.phase !== 'scan' || this.panelMin) return;
                    const el = document.getElementById('pu-scanner');
                    if (el && el.offsetWidth > 0 && el.offsetHeight > 0) { this.startScanner(); return; }
                    if (attempt < 30) setTimeout(() => this.nyalakanSaatTampil(attempt + 1), 100);
                },

                /** Server menolak hasil scan → kamera menyala lagi (fase tetap 'scan'). */
                onScanGagal() {
                    this.scanLocked = false;
                    // Kode yang SAMA baru dicoba lagi setelah 4 dtk — QR yang ditolak dan
                    // masih di depan kamera tak memicu banjir notifikasi error.
                    const kode = window.__puLastScan;
                    setTimeout(() => { if (window.__puLastScan === kode) window.__puLastScan = null; }, 4000);
                    this.nyalakanSaatTampil();
                },

                initLeaflet() {
                    if (!window.L || !document.getElementById('pu-map')) return;

                    // Leaflet 1.9.4: tooltip sticky yang sudah tertutup kadang masih
                    // menerima event zoom/move (race saat wheel-zoom sambil hover unit)
                    // → this._map null → THROW di dalam fire() → listener lain pada
                    // event yang sama BATAL dieksekusi → layer tidak reposisi →
                    // gambar/koordinat lepas sinkron (insiden berulang di produksi).
                    // Guard global sekali: abaikan update tooltip yang map-nya nihil.
                    if (!window.__puTooltipPatched) {
                        window.__puTooltipPatched = true;
                        ['_updatePosition', '_animateZoom'].forEach((fn) => {
                            const orig = L.Tooltip.prototype[fn];
                            if (orig) L.Tooltip.prototype[fn] = function (...a) { if (!this._map) return; return orig.apply(this, a); };
                        });
                    }

                    // Benteng anti double-init: kalau komponen sempat dibuat ulang,
                    // map lama masih menempel di #pu-map → L.map() melempar "Map
                    // container is already initialized" dan komponen baru lumpuh
                    // (map=null; tooltip yatim error latLngToLayerPoint). Buang map
                    // lama + ResizeObserver-nya, lalu init bersih dari nol.
                    if (window.__puMapObj) { try { window.__puMapObj.remove(); } catch (e) {} window.__puMapObj = null; }
                    if (window.__puRO) { try { window.__puRO.disconnect(); } catch (e) {} window.__puRO = null; }

                    const sp = this.siteplan;
                    let W = sp ? sp.width : 1500;
                    let H = sp ? sp.height : 1000;
                    if (!sp) {
                        // Cluster TANPA gambar siteplan: kerangka fallback statis (1500×1000)
                        // hampir pasti beda dari kerangka digitasi koordinat → polygon & stempel
                        // tersebar keluar placeholder. Pakai rentang koordinat unit/blok sebagai
                        // kerangka supaya layout tetap koheren.
                        let mx = 0, my = 0;
                        const scan = cs => (cs || []).forEach(p => { mx = Math.max(mx, p[0]); my = Math.max(my, p[1]); });
                        this.units.forEach(u => scan(u.siteplan_coords));
                        (this.blocks || []).forEach(b => scan(b.coords));
                        if (mx > 0 && my > 0) { W = Math.ceil(mx * 1.02); H = Math.ceil(my * 1.02); }
                    }
                    this.frameW = W; this.frameH = H;
                    const bounds = [[0, 0], [H, W]];
                    this._bounds = bounds;
                    this._userMoved = false;

                    // Zoom BERTAHAP & halus: zoomSnap 0 → tak "loncat" ke kelipatan level;
                    // zoomDelta .25 → tombol +/- & keyboard melangkah kecil; wheel butuh
                    // lebih banyak px per level → scroll terasa mulus, bukan meloncat.
                    // zoomAnimation:false → MATIKAN animasi zoom: animasi yang terpotong
                    // (scroll cepat/pan) meninggalkan transform scale basi di elemen gambar
                    // → gambar "geser" dari koordinat (insiden berulang di production).
                    // Tanpa animasi, seluruh kelas bug itu mustahil terjadi.
                    this.map = L.map('pu-map', {
                        crs: L.CRS.Simple, minZoom: -4, maxZoom: 4,
                        zoomSnap: 0, zoomDelta: 0.25, wheelPxPerZoomLevel: 240,
                        zoomAnimation: false,
                        attributionControl: false,
                    });
                    window.__puMapObj = this.map; // ref global utk benteng anti double-init

                    const overlay = sp
                        ? L.imageOverlay(sp.url, bounds).addTo(this.map)
                        : L.imageOverlay('https://placehold.co/'+W+'x'+H+'/1e293b/64748b?text=Siteplan+Belum+Diupload', bounds, { opacity: .85 }).addTo(this.map);
                    this.map.fitBounds(bounds);
                    this.baseZoom = this.map.getZoom();
                    this.map.on('zoomend', () => this.updateSoldScale());

                    // AKAR BUG "koordinat geser saat zoom": animasi zoom yang TERPOTONG
                    // (scroll wheel cepat / pan di tengah animasi) meninggalkan transform
                    // scale BASI di elemen <img> overlay — Leaflet baru membersihkannya
                    // di event zoom lengkap berikutnya, dan pan tidak pernah. Akibatnya
                    // GAMBAR tertinggal dari pane polygon/stempel → terlihat "geser",
                    // kadang pulih sendiri. Paksa sinkron ulang tiap gerakan selesai.
                    this.map.on('zoomend moveend viewreset', () => { if (overlay._reset) overlay._reset(); });

                    // PENTING (beda dgn /siteplan yang full-page statik): di dalam
                    // panel Filament + mode fullscreen, ukuran #pu-map bisa berubah
                    // SETELAH init (layout settle, topbar, dll). Kalau Leaflet pegang
                    // ukuran basi, operasi zoom menghitung center dgn ukuran salah →
                    // gambar/koordinat "loncat". ResizeObserver memanggil invalidateSize
                    // tiap kontainer berubah ukuran → Leaflet selalu tahu ukuran asli.
                    if (window.ResizeObserver) {
                        // Hanya sinkronkan ukuran saat kontainer BENAR-BENAR berubah, dan
                        // pan:false (JANGAN re-center) + skip saat animasi zoom. invalidateSize()
                        // default pan:true → tiap dipanggil menggeser view utk pertahankan center;
                        // di panel Filament (fullscreen + backdrop-filter yang repaint) ini terpicu
                        // berulang saat zoom → koordinat "loncat". /siteplan publik tak punya
                        // ResizeObserver → makanya stabil.
                        let lastW = 0, lastH = 0;
                        this._ro = new ResizeObserver((entries) => {
                            if (!this.map || this.map._animatingZoom) return;
                            const r = entries[0].contentRect;
                            if (Math.abs(r.width - lastW) < 1 && Math.abs(r.height - lastH) < 1) return;
                            lastW = r.width; lastH = r.height;
                            this.map.invalidateSize({ animate: false, pan: false });
                            // HP/tablet: ukuran layout baru mantap SETELAH init (address bar,
                            // rotasi layar) → peta terpotong/tergeser (screenshot JRS5). Selama
                            // operator belum menyentuh peta, paskan ulang ke ukuran terkini.
                            if (!this._userMoved && r.width > 0 && r.height > 0) this.map.fitBounds(bounds, { animate: false });
                        });
                        this._ro.observe(document.getElementById('pu-map'));
                        window.__puRO = this._ro; // ref global — diputus saat re-init
                    }

                    // Operator mulai menggeser/zoom → berhenti paskan ulang otomatis.
                    const mapEl = document.getElementById('pu-map');
                    ['mousedown', 'touchstart', 'wheel'].forEach(ev =>
                        mapEl.addEventListener(ev, () => { this._userMoved = true; }, { passive: true }));

                    this.renderPolygons();

                    // Map full-width → recalc ukuran setelah layout, lalu re-fit + reskala stempel.
                    setTimeout(() => {
                        if (!this.map) return;
                        this.map.invalidateSize();
                        if (!this._userMoved) this.map.fitBounds(bounds);
                        this.baseZoom = this.map.getZoom();
                        this.updateSoldScale();
                    }, 250);
                },

                toLatLng(x, y) {
                    // frameH di-set initLeaflet — WAJIB sama dgn H bounds peta.
                    return [this.frameH - y, x];
                },

                renderPolygons() {
                    this.units.forEach(u => this.addPolygon(u));

                    // Blok area (penanda tahap, tidak terikat unit) — menutup area belum dibuka.
                    this.blockPolys = [];
                    (this.blocks || []).forEach(b => {
                        if (!Array.isArray(b.coords) || b.coords.length < 3) return;
                        const bp = L.polygon(b.coords.map(p => this.toLatLng(p[0], p[1])), {
                            color: '#000', weight: 1, fillColor: b.color || '#0a0a0a',
                            fillOpacity: (b.opacity != null ? b.opacity : 0.85), interactive: false,
                        }).addTo(this.map);
                        if (b.label) { bp.bindTooltip(b.label, { permanent: true, direction: 'center', className: 'pu-block-label' }); this.blockPolys.push(bp); }
                    });
                    // Label blok stabil saat zoom (sekali bind handler).
                    if (! this._blockZoomBound) {
                        this._blockZoomBound = true;
                        const self = this;
                        this.map.on('zoomend', () => (self.blockPolys || []).forEach(bp => { const tt = bp.getTooltip(); if (tt) tt.setLatLng(bp.getBounds().getCenter()); }));
                    }
                },

                /** Unit HOLD = ditahan sales, TIDAK boleh dipilih siapa pun hari-H. */
                isHold(u) {
                    return String(u?.status || '').toUpperCase() === 'HOLD';
                },

                /**
                 * Gambar SATU polygon unit. Unit HOLD ditutup BLOK HITAM pekat dan
                 * dibuat non-interaktif (interactive:false) — tak bisa diklik, tak
                 * memunculkan tooltip/popup, jadi mustahil terpilih. Unit lain tetap
                 * transparan (hanya highlight tipis saat hover).
                 */
                addPolygon(u) {
                    if (!Array.isArray(u.siteplan_coords) || u.siteplan_coords.length < 3) return;
                    const latlngs = u.siteplan_coords.map(p => this.toLatLng(p[0], p[1]));
                    const hold = this.isHold(u);
                    const color = this.colorForStatus(u.status);

                    const poly = L.polygon(latlngs, hold
                        ? { color: '#000000', weight: 1, fillColor: '#000000', fillOpacity: 0.92, interactive: false }
                        : { color: color.stroke, weight: 0, fillColor: color.fill, fillOpacity: 0 }
                    ).addTo(this.map);

                    if (!hold) {
                        poly.bindTooltip(u.label, { direction: 'center', className: '', permanent: false, sticky: true });
                        poly.on('mouseover', () => poly.setStyle({ weight: 2, fillOpacity: 0.18 }));
                        poly.on('mouseout', () => poly.setStyle({ weight: 0, fillOpacity: 0 }));
                        poly.on('click', () => this.showUnitPopup(u, poly));
                    }
                    this.polygons[u.id] = poly;
                    poly._puHold = hold;

                    // Stempel status tidak dipasang di unit HOLD — tertutup blok hitam.
                    if (!hold) this.applySoldStamp(u, poly);
                    return poly;
                },

                /** Teks + kelas stempel per status (null = tanpa stempel). */
                stampFor(status) {
                    if (status === 'TERJUAL' || status === 'LUNAS') return { text: 'SOLD', cls: 'sold' };
                    if (status === 'BOOKING') return { text: 'BOOKED', cls: 'booking' };
                    return null;
                },

                /** Tempel/lepas stempel status (SOLD/BOOKING) di tengah polygon; ikut skala zoom. */
                applySoldStamp(unit, poly) {
                    // bersihkan stempel lama kalau ada
                    if (this.soldMarkers[unit.id]) {
                        const old = this.soldMarkers[unit.id];
                        const i = this.soldStamps.indexOf(old._stampEl);
                        if (i !== -1) this.soldStamps.splice(i, 1);
                        this.map.removeLayer(old);
                        delete this.soldMarkers[unit.id];
                    }
                    const info = this.stampFor(unit.status);
                    if (!info) return;

                    const stamp = this.el('span', { class: 'pu-stamp ' + info.cls, text: info.text });
                    this.soldStamps.push(stamp);
                    // Center horizontal + dinaikkan ~14% tinggi unit (ubah 0.14 utk geser).
                    const _b = poly.getBounds();
                    const _pos = L.latLng(_b.getCenter().lat + (_b.getNorth() - _b.getSouth()) * 0.14, _b.getCenter().lng);
                    const marker = L.marker(_pos, {
                        interactive: false, keyboard: false, zIndexOffset: 1000,
                        icon: L.divIcon({ className: 'pu-sold-div', html: stamp, iconSize: [0, 0], iconAnchor: [0, 0] }),
                    }).addTo(this.map);
                    marker._stampEl = stamp;
                    this.soldMarkers[unit.id] = marker;
                    this.updateSoldScale();
                },

                /** Paksa Leaflet menyejajarkan ulang gambar & koordinat (dipakai sesudah morph fase). */
                resyncMap() {
                    if (!this.map) return;
                    this.map.invalidateSize({ animate: false, pan: false });
                    this.map.fire('viewreset'); // reposisi SEMUA layer dari state peta kini
                    this.updateSoldScale();
                },

                /**
                 * Skala stempel SOLD SEBANDING lebar gambar siteplan di layar
                 * (frameW × 2^zoom px), acuan 1280 px ≈ tampilan desktop saat fit.
                 * Dulu k = 2^(zoom − baseZoom) → saat fit stempel selalu berukuran sama
                 * berapa pun lebar layar: di HP gambar mengecil tapi stempel tidak, jadi
                 * saling tumpuk menutup unit. baseZoom juga bisa basi bila ukuran layout
                 * berubah sesudah init → stempel membesar tak wajar.
                 */
                updateSoldScale() {
                    if (!this.map) return;
                    const k = (this.frameW * Math.pow(2, this.map.getZoom())) / 1280;
                    this.soldStamps.forEach(s => {
                        s.style.transform = 'translate(-50%, -50%) rotate(-12deg) scale(' + k.toFixed(3) + ')';
                    });
                },

                colorForStatus(status) {
                    switch (status) {
                        case 'TERSEDIA': return { stroke: '#10b981', fill: '#34d399', badge: '#16a34a', label: 'TERSEDIA' };
                        case 'BOOKING':  return { stroke: '#f59e0b', fill: '#fbbf24', badge: '#d97706', label: 'BOOKING' };
                        case 'HOLD':     return { stroke: '#f97316', fill: '#fb923c', badge: '#ea580c', label: 'HOLD' };
                        case 'TERJUAL':
                        case 'LUNAS':    return { stroke: '#64748b', fill: '#94a3b8', badge: '#dc2626', label: 'SOLD' };
                        default:         return { stroke: '#94a3b8', fill: '#cbd5e1', badge: '#64748b', label: (status || '-') };
                    }
                },

                refreshPolygonStyles() {
                    Object.entries(this.polygons).forEach(([id, p]) => {
                        const u = this.units.find(x => x.id == id);
                        if (!u) return;
                        // Unit HOLD: blok hitamnya JANGAN ditimpa warna status & jangan
                        // diberi stempel — biar tetap tertutup rapat.
                        if (this.isHold(u)) {
                            p.setStyle({ color: '#000000', weight: 1, fillColor: '#000000', fillOpacity: 0.92 });
                            return;
                        }
                        const c = this.colorForStatus(u.status);
                        p.setStyle({ color: c.stroke, fillColor: c.fill });
                        this.applySoldStamp(u, p);
                    });
                },

                // Real-time: subscribe channel publik siteplan-projectId (sama dgn
                // /siteplan). window.Echo dimuat dari Vite app.js (script di atas) — bisa
                // belum siap saat init (module deferred), jadi poll sampai tersedia.
                initRealtime(attempt = 0) {
                    if (!this.projectId) return;
                    // Instance MATI dilarang menyentuh Echo. Poll di bawah memakai
                    // setTimeout yang tak bisa dibatalkan saat komponen dihancurkan
                    // (Ganti Cluster); tanpa penjaga ini retry milik cluster lama bisa
                    // (a) membajak __puOnUnitStatus ke instance mati → peta tak pernah
                    // update, atau (b) meng-leave channel cluster yang SEDANG aktif →
                    // real-time mati senyap sampai reload.
                    if (window.__puEpoch !== this._epoch) return;
                    // Handler lewat window → kalau komponen sempat dibuat ulang cukup
                    // ganti target; langganan channel TIDAK digandakan (callback dobel).
                    window.__puOnUnitStatus = (id, st) => this.applyStatusChange(id, st);
                    if (window.__puEchoProject === this.projectId) return;
                    if (window.Echo) {
                        if (window.__puEchoProject != null) window.Echo.leave('siteplan.' + window.__puEchoProject);
                        window.Echo.channel('siteplan.' + this.projectId)
                            .listen('.unit.status.changed', (e) => {
                                if (e && e.unit_id && window.__puOnUnitStatus) window.__puOnUnitStatus(e.unit_id, e.new_status);
                            });
                        window.__puEchoProject = this.projectId;
                        return;
                    }
                    if (attempt < 40) setTimeout(() => this.initRealtime(attempt + 1), 250);
                },

                applyStatusChange(unitId, newStatus) {
                    const u = this.units.find(x => x.id == unitId);
                    if (u) u.status = newStatus;
                    const poly = this.polygons[unitId];
                    if (!poly) return;

                    // Masuk/keluar HOLD mengubah INTERAKTIVITAS layer, dan itu tak bisa
                    // diubah setelah layer dibuat → gambar ulang polygonnya. Tanpa ini,
                    // unit yang baru di-HOLD tetap bisa diklik (atau sebaliknya, unit
                    // yang hold-nya dibuka tetap mati).
                    const holdSekarang = String(newStatus || '').toUpperCase() === 'HOLD';
                    if (!!poly._puHold !== holdSekarang) {
                        const unit = u || { id: unitId, status: newStatus, siteplan_coords: null };
                        if (unit.siteplan_coords) {
                            this.removeSoldStamp?.(unitId);
                            const old = this.soldMarkers[unitId];
                            if (old) { this.map.removeLayer(old); delete this.soldMarkers[unitId]; }
                            this.map.removeLayer(poly);
                            delete this.polygons[unitId];
                            this.addPolygon(unit);
                            this.updateSoldScale();
                            return;
                        }
                    }

                    const c = this.colorForStatus(newStatus);
                    poly.setStyle({ color: c.stroke, fillColor: c.fill });
                    this.applySoldStamp({ id: unitId, status: newStatus }, poly);
                },

                fmtRp(n) { return 'Rp ' + (n || 0).toLocaleString('id-ID'); },

                // PENTING: popup dibangun via createElement (BUKAN string HTML).
                // Parser HTML4 di DOMDocument (dipakai Livewire multi-root detection)
                // mengakhiri <script> begitu melihat literal "<div" di dalam string JS,
                // lalu mem-parsing sisanya sebagai HTML — itu memecah struktur halaman.
                // createElement menghindari literal tag sepenuhnya.
                el(tag, opts) {
                    const e = document.createElement(tag);
                    if (opts && opts.class) e.className = opts.class;
                    if (opts && opts.text != null) e.textContent = opts.text;
                    return e;
                },

                infoRow(label, value) {
                    const row = this.el('div', { class: 'row' });
                    row.appendChild(this.el('div', { class: 'lbl', text: label }));
                    row.appendChild(this.el('div', { class: 'val', text: ': ' + (value != null && value !== '' ? value : '—') }));
                    return row;
                },

                showUnitPopup(unit, polygon) {
                    // Kartu putih ala /siteplan (createElement → hindari literal tag).
                    const cur = this.colorForStatus(unit.status);
                    const card = this.el('div', { class: 'sp-card' });

                    // Header: kode unit + pill status
                    const head = this.el('div', { class: 'sp-head' });
                    head.appendChild(this.el('div', { class: 'sp-code', text: unit.kode || unit.label }));
                    const pill = this.el('span', { class: 'sp-pill', text: cur.label });
                    pill.style.background = cur.badge;
                    head.appendChild(pill);
                    card.appendChild(head);

                    if (unit.nama_lokasi) card.appendChild(this.el('div', { class: 'sp-loc', text: unit.nama_lokasi }));

                    // Tipe · LT/LB
                    const sub = this.el('div', { class: 'sp-sub' });
                    sub.appendChild(this.el('b', { text: unit.tipe || '—' }));
                    sub.appendChild(document.createTextNode('  ·  LT ' + (unit.luas_tanah || '—') + ' / LB ' + (unit.luas_bangunan || '—')));
                    card.appendChild(sub);

                    if (unit.alamat) {
                        const addr = this.el('div', { class: 'sp-addr' });
                        addr.appendChild(this.el('span', { class: 'pin', text: '📍' }));
                        addr.appendChild(this.el('span', { text: unit.alamat }));
                        card.appendChild(addr);
                    }

                    // Harga per skema → kartu sejajar (angka penuh)
                    let rows = [];
                    if (Array.isArray(unit.pricelist) && unit.pricelist.length) {
                        rows = unit.pricelist.map(p => ({
                            // Tampilkan NAMA skema (CASH/KPR/INHOUSE), bukan kode angka (1/2/3).
                            label: (p.skema_name || p.cara_bayar || p.skema_code || '').toString().toUpperCase(),
                            price: Number(p.price) || 0,
                        }));
                    } else if (unit.harga > 0) {
                        rows = [{ label: 'HARGA', price: Number(unit.harga) || 0 }];
                    }
                    if (rows.length) {
                        const cls = rows.length === 1 ? ' one' : (rows.length === 2 ? ' two' : '');
                        const wrap = this.el('div', { class: 'sp-prices' + cls });
                        rows.forEach(r => {
                            const pc = this.el('div', { class: 'sp-pc' });
                            pc.appendChild(this.el('div', { class: 'lab', text: r.label }));
                            pc.appendChild(this.el('div', { class: 'cur', text: 'Rp' }));
                            pc.appendChild(this.el('div', { class: 'amt', text: (Number(r.price) || 0).toLocaleString('id-ID') }));
                            wrap.appendChild(pc);
                        });
                        card.appendChild(wrap);
                    }

                    // Tombol Pilih Unit (khusus Pilih Unit — tak ada di /siteplan).
                    // Fase dibaca dari $wire (sumber kebenaran server). Pilih hanya saat 'choosing'.
                    const ph = this.$wire.phase;
                    const canPick = ph === 'choosing' && unit.status === 'TERSEDIA';
                    const btnLabel = unit.status !== 'TERSEDIA'
                        ? 'Tidak Tersedia (' + unit.status + ')'
                        : (ph === 'choosing' ? 'Pilih Unit Ini'
                            : ph === 'customer' ? 'Klik "Pilih Unit (2 menit)" dulu'
                            : 'Scan barcode customer dulu');

                    const btn = this.el('button', { class: 'pu-pilih-btn', text: btnLabel });
                    btn.disabled = ! canPick;
                    if (canPick) {
                        btn.onclick = () => {
                            this.confirmUnitId = unit.id;
                            this.confirmUnitLabel = unit.label;
                            this.confirmUnitAlamat = unit.alamat || '';
                            polygon.closePopup();
                        };
                    }
                    card.appendChild(btn);

                    polygon.bindPopup(card, { maxWidth: Math.min(340, window.innerWidth - 40), autoPan: true }).openPopup();
                },

                doConfirm() {
                    if (!this.confirmUnitId) return;
                    const id = this.confirmUnitId;
                    this.confirmUnitId = null;
                    this.$wire.call('pickUnit', id);
                },

                /**
                 * Daftar kamera yang terpasang (termasuk WEBCAM USB tambahan).
                 * Dipanggil sesudah kamera menyala — getCameras() baru mengembalikan
                 * label perangkat setelah izin kamera diberikan.
                 */
                async muatKamera() {
                    if (!window.Html5Qrcode) return;
                    try {
                        const list = await Html5Qrcode.getCameras();
                        this.cameras = (list || []).map((c, i) => ({ id: c.id, label: c.label || ('Kamera ' + (i + 1)) }));
                        if (!this.camId && this.cameras.length) {
                            this.camId = this.cameras.some(c => c.id === this.camTersimpan) ? this.camTersimpan : '';
                        }
                    } catch (e) { /* izin belum diberikan — daftar diisi setelah start */ }
                },

                /** Ganti kamera: simpan pilihan lalu nyalakan ulang scanner. */
                async gantiKamera() {
                    try { localStorage.setItem('pu_cam_id', this.camId || ''); } catch (e) {}
                    await this.stopScanner();
                    await this.startScanner();
                },

                /**
                 * Start/stop kamera DIANTREKAN (satu per satu). Dulu stop tak ditunggu lalu
                 * start langsung jalan → dua tampilan kamera bertumpuk / kamera "sibuk".
                 */
                _antreKamera(fn) {
                    window.__puCamQueue = (window.__puCamQueue || Promise.resolve()).then(fn).catch((e) => console.warn('Kamera:', e));
                    return window.__puCamQueue;
                },

                /** Pesan error kamera yang dimengerti operator. */
                pesanKamera(e) {
                    const s = String((e && (e.name || e.message)) || e || '');
                    if (!window.isSecureContext) return 'Kamera hanya bisa dipakai lewat HTTPS. Buka alamat https:// atau ketik kode manual.';
                    if (/NotAllowed|Permission/i.test(s)) return 'Izin kamera ditolak. Izinkan kamera untuk situs ini di pengaturan browser, lalu klik Hidupkan.';
                    if (/NotFound|DevicesNotFound|Requested device not found/i.test(s)) return 'Kamera tidak ditemukan. Pasang kamera / pilih kamera lain, atau ketik kode manual.';
                    if (/NotReadable|TrackStart|Could not start/i.test(s)) return 'Kamera sedang dipakai aplikasi lain. Tutup aplikasi itu, lalu klik Hidupkan.';
                    return 'Kamera gagal menyala (' + s.slice(0, 80) + '). Klik Hidupkan untuk mencoba lagi, atau ketik kode manual.';
                },

                startScanner() {
                    return this._antreKamera(async () => {
                        if (this.scannerOn || !window.Html5Qrcode || this.phase !== 'scan' || this.panelMin) return;
                        const el = document.getElementById('pu-scanner');
                        if (!el || el.offsetWidth === 0) return;
                        this.scanError = '';

                        // Hanya QR (barcode customer memang QR) + pembaca BAWAAN browser
                        // (BarcodeDetector — Chrome Android/desktop) yang jauh lebih cepat &
                        // tahan silau layar HP. Browser tanpa dukungan (Safari iOS) otomatis
                        // memakai pembaca JavaScript seperti sebelumnya.
                        // Instance lama (mis. milik komponen sebelum Ganti Cluster) masih
                        // memegang kamera → lepas dulu.
                        if (window.__puQr) { try { await window.__puQr.stop(); } catch (e) {} try { window.__puQr.clear(); } catch (e) {} window.__puQr = null; }

                        const fmt = window.Html5QrcodeSupportedFormats;
                        // Tanpa qrbox → seluruh gambar kamera dibaca, tanpa kotak bidik.
                        const config = { fps: 15 };
                        const onOk = (decoded) => this.onScanned(decoded);
                        // Satu percobaan = instance baru; gagal → dibersihkan.
                        const coba = async (src, cfg) => {
                            const inst = new Html5Qrcode('pu-scanner', {
                                verbose: false,
                                formatsToSupport: fmt ? [fmt.QR_CODE] : undefined,
                                experimentalFeatures: { useBarCodeDetectorIfSupported: true },
                            });
                            try { await inst.start(src, cfg, onOk, () => {}); return inst; }
                            catch (e) { try { inst.clear(); } catch (x) {} throw e; }
                        };
                        // Pakai kamera pilihan operator bila ada (mis. webcam USB);
                        // bila belum memilih, jatuh ke kamera belakang seperti semula.
                        const sumber = this.camId ? { deviceId: { exact: this.camId } } : { facingMode: 'environment' };
                        let inst = null;
                        try {
                            // Resolusi lebih tinggi → QR kecil/jauh tetap tajam.
                            inst = await coba(sumber, { ...config, videoConstraints: { ...sumber, width: { ideal: 1280 }, height: { ideal: 720 } } });
                        } catch (e1) {
                            try {
                                // Constraint resolusi ditolak kamera → pengaturan bawaan.
                                inst = await coba(sumber, config);
                            } catch (e2) {
                                // Webcam pilihan tersimpan sudah dicabut → kembali ke kamera bawaan.
                                if (this.camId && /NotFound|Overconstrained/i.test(String(e2 && (e2.name || e2)))) {
                                    this.camId = ''; try { localStorage.setItem('pu_cam_id', ''); } catch (e) {}
                                    try { inst = await coba({ facingMode: 'environment' }, config); }
                                    catch (e3) { this.scanError = this.pesanKamera(e3); return; }
                                } else { this.scanError = this.pesanKamera(e2); return; }
                            }
                        }
                        this.scannerInstance = inst;
                        window.__puQr = inst;
                        this.scannerOn = true;
                        // Fokus otomatis terus-menerus bila kamera mendukung (diabaikan bila tidak).
                        try { await this.scannerInstance.applyVideoConstraints({ advanced: [{ focusMode: 'continuous' }] }); } catch (e) {}
                        this.muatKamera();
                    });
                },
                stopScanner() {
                    return this._antreKamera(async () => {
                        if (!this.scannerOn || !this.scannerInstance) return;
                        try { await this.scannerInstance.stop(); } catch (e) {}
                        try { this.scannerInstance.clear(); } catch (e) {}
                        if (window.__puQr === this.scannerInstance) window.__puQr = null;
                        this.scannerOn = false;
                    });
                },
                async toggleScanner() { this.scannerOn ? await this.stopScanner() : await this.startScanner(); },
                onScanned(code) {
                    // Kunci: html5-qrcode memanggil callback tiap frame selama QR terlihat.
                    // Tanpa lock → puluhan $wire.call('scanBarcode') (banjir request).
                    if (!code || this.scanLocked) return;
                    // Hanya kirim saat fase 'scan' (server). Setelah customer ter-load,
                    // scanner kadang masih aktif & membaca QR yg sama → matikan & abaikan,
                    // supaya tak banjir scanBarcode + fase bolak-balik.
                    if (this.$wire.phase !== 'scan') { this.stopScanner(); return; }
                    const c = code.trim();
                    // Dedupe per-KODE (global): QR yg SAMA diabaikan SEPENUHNYA sampai
                    // fase kembali ke 'scan' (Scan Ulang/DONE → __puLastScan di-reset)
                    // atau kode QR berganti. Cegah re-scan QR yg masih nyangkut di
                    // kamera (mis. customer sudah pilih unit → error berulang).
                    // Diabaikan saja — kamera TIDAK dimatikan (dulu dimatikan → setelah scan
                    // gagal kamera mati & operator harus klik Hidupkan sendiri).
                    if (window.__puLastScan === c) return;
                    window.__puLastScan = c;
                    this.scanLocked = true;
                    this.stopScanner();
                    this.$wire.call('scanBarcode', c);
                    setTimeout(() => { this.scanLocked = false; }, 1500);
                },
                manualScan() { const c = (this.manualCode || '').trim(); if (c === '') return; this.$wire.call('scanBarcode', c); this.manualCode = ''; },

                startTimer() {
                    // Deadline + interval GLOBAL (window) — kalau Livewire sempat
                    // membuat ulang komponen ini, TANPA guard tiap instans menambah
                    // setInterval sendiri (instans lama tak dibersihkan) → puluhan
                    // interval → banjir $wire.call('timeout') → fase oscillasi.
                    // Dengan guard: hanya SATU interval & SATU deadline yang pernah ada.
                    if (! window.__puDeadline) window.__puDeadline = Date.now() + 120000;
                    if (window.__puTimer) clearInterval(window.__puTimer);
                    window.__puTimer = setInterval(() => {
                        const remain = Math.max(0, Math.round((window.__puDeadline - Date.now()) / 1000));
                        this.timerSec = remain; this.updateTimerDisplay();
                        if (remain <= 0) {
                            clearInterval(window.__puTimer); window.__puTimer = null; window.__puDeadline = null;
                            this.$wire.call('timeout');
                        }
                    }, 250);
                    this.timerId = window.__puTimer;
                },
                stopTimer() {
                    if (window.__puTimer) { clearInterval(window.__puTimer); window.__puTimer = null; }
                    window.__puDeadline = null;
                    this.timerId = null;
                },
                updateTimerDisplay() {
                    const m = Math.floor(this.timerSec / 60), s = this.timerSec % 60;
                    this.timerDisplay = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
                },
            };
        }
    </script>
    </div>
</x-filament-panels::page>
