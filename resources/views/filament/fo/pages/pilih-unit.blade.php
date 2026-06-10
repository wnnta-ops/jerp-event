<x-filament-panels::page>
    @php
        $clusters = $this->getClusters();
        $units = $this->getUnits();
        $project = $this->getProject();
        $siteplan = $this->getSiteplan();
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
        #pu-scanner { width: 100%; aspect-ratio: 4 / 3; border-radius: .75rem; overflow: hidden; background: #0f172a; border: 1px solid rgba(255,255,255,.15); }
        .pu-panel { background: var(--gray-50, rgb(249 250 251)); border: 1px solid var(--gray-200, rgb(229 231 235)); border-radius: 1rem; padding: 1rem; }
        .dark .pu-panel { background: rgba(30,41,59,.6); border-color: rgba(255,255,255,.1); }
        /* Panel mengambang di pojok kiri bawah, di atas siteplan */
        .pu-float { position: absolute; left: 1rem; bottom: 1rem; z-index: 600; width: 340px; max-width: calc(100% - 2rem); box-shadow: 0 18px 44px -10px rgba(0,0,0,.55); backdrop-filter: blur(10px); }
        .pu-float.pu-panel { background: rgba(255,255,255,.94); }
        .dark .pu-float.pu-panel { background: rgba(15,23,42,.88); border-color: rgba(255,255,255,.14); }

        /* Full layar seperti /siteplan: stage menutupi seluruh viewport, map isi penuh.
           Panel scan (kiri-bawah) & Ganti Cluster (kanan-atas) mengambang di atasnya. */
        .pu-stage.pu-fullscreen { position: fixed; inset: 0; z-index: 40; }
        .pu-stage.pu-fullscreen > #pu-map { height: 100%; min-height: 0; border-radius: 0; border: 0; }

        /* Popup info — kartu putih meniru /siteplan (lebih enak dilihat). */
        .leaflet-popup-content-wrapper { background:#fff; color:#0f172a; border-radius:16px; padding:0; box-shadow:0 20px 50px rgba(15,23,42,.25); border:1px solid #e2e8f0; }
        .leaflet-popup-tip { background:#fff; border:1px solid #e2e8f0; box-shadow:none; }
        .leaflet-popup-content { margin:0; min-width:330px; }
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
                        style="background: linear-gradient(135deg, #06b6d4 0%, #0e7490 100%);">
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
        {{-- x-data WAJIB statik: kalau memuat data yang berubah (phase, regNoNup, …)
             string x-data berubah tiap update → Alpine bikin instance baru → polygon
             & timer beda instance. Data dinamis dibaca via $wire. --}}
        <div class="pu-stage pu-fullscreen"
             wire:key="pu-stage"
             x-data="pilihUnitState({
                 units: @js($units),
                 siteplan: @js($siteplan),
                 projectId: @js($this->projectId),
             })"
             x-init="init()"
             x-effect="phase = $wire.phase">

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
            <div x-show="phase === 'scan'" class="pu-float pu-panel" x-cloak>
                <div class="flex items-center justify-between mb-3">
                    <div class="text-sm font-semibold">📷 Scan Barcode NUP</div>
                    <button @click="toggleScanner()" class="text-xs hover:underline" style="color:#60a5fa;">
                        <span x-text="scannerOn ? 'Matikan' : 'Hidupkan'"></span>
                    </button>
                </div>
                <div id="pu-scanner"></div>
                <p class="text-xs text-gray-500 mt-3">Arahkan barcode customer ke kamera. Atau ketik manual:</p>
                <div class="flex gap-2 mt-2">
                    <input x-model="manualCode" type="text"
                           class="flex-1 rounded-lg border px-3 py-2 text-sm"
                           style="border-color: var(--gray-300);"
                           placeholder="Mis. NUP-00001"
                           @keyup.enter="manualScan()">
                    <button @click="manualScan()" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 transition">Cari</button>
                </div>
                @if ($pickError)
                    <div class="mt-3 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $pickError }}</div>
                @endif
            </div>

            {{-- Phase: CUSTOMER --}}
            <div x-show="phase === 'customer'" class="pu-float pu-panel" x-cloak>
                <div class="text-xs uppercase tracking-wider text-gray-500">Data Customer</div>
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
                </div>
                <button wire:click="startChoosing" class="mt-4 w-full rounded-lg bg-emerald-600 px-4 py-3 text-sm font-bold text-white hover:bg-emerald-700 transition shadow-md">🏠 Pilih Unit (2 menit)</button>
                <button wire:click="resetToScan" class="mt-2 w-full rounded-lg border px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-slate-800 transition" style="border-color: var(--gray-300);">Scan Ulang</button>
            </div>

            {{-- Phase: CHOOSING (timer aktif) --}}
            <div x-show="phase === 'choosing'" class="pu-float pu-panel" x-cloak>
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
                        <button @click="doConfirm()" class="flex-1 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-700 transition">✓ Yakin</button>
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
                    <button wire:click="resetToScan" class="mt-5 w-full rounded-lg bg-blue-600 px-6 py-3 text-base font-bold text-white hover:bg-blue-700 transition shadow-lg">
                        DONE
                    </button>
                </div>
            </div>
        @endif
    @endif

    <script>
        function pilihUnitState(initial) {
            return {
                phase: 'scan',          // disinkron dari server via x-effect="phase = $wire.phase"
                units: initial.units,
                siteplan: initial.siteplan,
                projectId: initial.projectId,

                scannerOn: false, scannerInstance: null, manualCode: '', scanLocked: false,
                timerSec: 120, timerId: null, timerDisplay: '02:00',

                map: null,
                polygons: {}, // unitId → L.Polygon
                soldMarkers: {}, // unitId → L.Marker (stempel SOLD)
                soldStamps: [], // elemen <span> stempel aktif (untuk reskala saat zoom)
                baseZoom: 0,    // zoom saat fit → acuan skala stempel

                confirmUnitId: null,
                confirmUnitLabel: '',
                confirmUnitAlamat: '',

                init() {
                    this.$nextTick(() => this.initLeaflet());
                    this.initRealtime();

                    this.$watch('phase', (v) => {
                        if (v === 'scan') { this.scanLocked = false; window.__puLastScan = null; this.startScanner(); } else this.stopScanner();
                        if (v === 'choosing') this.startTimer(); else this.stopTimer();
                        this.refreshPolygonStyles();
                    });

                    // Error/timeout kini tampil sebagai notifikasi Filament (toast),
                    // bukan alert() bawaan browser — lihat PilihUnit.php.

                    if (this.phase === 'scan') this.$nextTick(() => this.startScanner());
                    else if (this.phase === 'choosing') this.startTimer();
                },

                initLeaflet() {
                    if (!window.L || !document.getElementById('pu-map')) return;

                    const sp = this.siteplan;
                    const W = sp ? sp.width : 1500;
                    const H = sp ? sp.height : 1000;
                    const bounds = [[0, 0], [H, W]];

                    this.map = L.map('pu-map', { crs: L.CRS.Simple, minZoom: -4, maxZoom: 4, zoomSnap: 0.25, attributionControl: false });

                    if (sp) {
                        L.imageOverlay(sp.url, bounds).addTo(this.map);
                    } else {
                        L.imageOverlay('https://placehold.co/'+W+'x'+H+'/1e293b/64748b?text=Siteplan+Belum+Diupload', bounds, { opacity: .85 }).addTo(this.map);
                    }
                    this.map.fitBounds(bounds);
                    this.baseZoom = this.map.getZoom();
                    this.map.on('zoomend', () => this.updateSoldScale());

                    // PENTING (beda dgn /siteplan yang full-page statik): di dalam
                    // panel Filament + mode fullscreen, ukuran #pu-map bisa berubah
                    // SETELAH init (layout settle, topbar, dll). Kalau Leaflet pegang
                    // ukuran basi, operasi zoom menghitung center dgn ukuran salah →
                    // gambar/koordinat "loncat". ResizeObserver memanggil invalidateSize
                    // tiap kontainer berubah ukuran → Leaflet selalu tahu ukuran asli.
                    if (window.ResizeObserver) {
                        this._ro = new ResizeObserver(() => {
                            if (this.map) this.map.invalidateSize({ animate: false });
                        });
                        this._ro.observe(document.getElementById('pu-map'));
                    }

                    this.renderPolygons();

                    // Map full-width → recalc ukuran setelah layout, lalu re-fit + reskala stempel.
                    setTimeout(() => {
                        this.map.invalidateSize();
                        this.map.fitBounds(bounds);
                        this.baseZoom = this.map.getZoom();
                        this.updateSoldScale();
                    }, 250);
                },

                toLatLng(x, y) {
                    const sp = this.siteplan;
                    const H = sp ? sp.height : 1000;
                    return [H - y, x];
                },

                renderPolygons() {
                    this.units.forEach(u => {
                        if (!Array.isArray(u.siteplan_coords) || u.siteplan_coords.length < 3) return;
                        const latlngs = u.siteplan_coords.map(p => this.toLatLng(p[0], p[1]));
                        const color = this.colorForStatus(u.status);
                        // Transparan (meniru /siteplan): polygon tak menutupi gambar,
                        // hanya highlight tipis saat hover + popup saat klik.
                        const poly = L.polygon(latlngs, {
                            color: color.stroke, weight: 0, fillColor: color.fill, fillOpacity: 0,
                        }).addTo(this.map);
                        poly.bindTooltip(u.label, { direction: 'center', className: '', permanent: false, sticky: true });
                        poly.on('mouseover', () => poly.setStyle({ weight: 2, fillOpacity: 0.18 }));
                        poly.on('mouseout', () => poly.setStyle({ weight: 0, fillOpacity: 0 }));
                        poly.on('click', () => this.showUnitPopup(u, poly));
                        this.polygons[u.id] = poly;

                        this.applySoldStamp(u, poly);
                    });
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

                /** Skala stempel SOLD mengikuti zoom (k = 2^(zoom − baseZoom)) — identik /siteplan. */
                updateSoldScale() {
                    if (!this.map) return;
                    const k = Math.pow(2, this.map.getZoom() - this.baseZoom);
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
                    if (window.Echo) {
                        window.Echo.channel('siteplan.' + this.projectId)
                            .listen('.unit.status.changed', (e) => {
                                if (e && e.unit_id) this.applyStatusChange(e.unit_id, e.new_status);
                            });
                        return;
                    }
                    if (attempt < 40) setTimeout(() => this.initRealtime(attempt + 1), 250);
                },

                applyStatusChange(unitId, newStatus) {
                    const u = this.units.find(x => x.id == unitId);
                    if (u) u.status = newStatus;
                    const poly = this.polygons[unitId];
                    if (!poly) return;
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
                            label: (p.skema_code || p.skema_name || p.cara_bayar || '').toString().toUpperCase(),
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

                    polygon.bindPopup(card, { maxWidth: 340, autoPan: true }).openPopup();
                },

                doConfirm() {
                    if (!this.confirmUnitId) return;
                    const id = this.confirmUnitId;
                    this.confirmUnitId = null;
                    this.$wire.call('pickUnit', id);
                },

                async startScanner() {
                    if (this.scannerOn || !window.Html5Qrcode) return;
                    try {
                        this.scannerInstance = new Html5Qrcode('pu-scanner');
                        await this.scannerInstance.start(
                            { facingMode: 'environment' },
                            { fps: 10, qrbox: 250 },
                            (decoded) => this.onScanned(decoded),
                            () => {},
                        );
                        this.scannerOn = true;
                    } catch (e) { console.warn('Scanner gagal start:', e); }
                },
                async stopScanner() {
                    if (!this.scannerOn || !this.scannerInstance) return;
                    try { await this.scannerInstance.stop(); this.scannerInstance.clear(); } catch (e) {}
                    this.scannerOn = false;
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
                    if (window.__puLastScan === c) { this.stopScanner(); return; }
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
