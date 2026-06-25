<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="robots" content="noindex,nofollow">
    <title>Site Plan LIVE — {{ $project->name }}</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    {{-- Laravel Echo (window.Echo) untuk update real-time via Reverb. --}}
    @vite(['resources/js/app.js'])
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; height: 100%; font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: #1e293b; }
        #map { position: absolute; inset: 0; background: #1e293b; }

        /* ── Stock Status (kanan atas) — gaya legacy live ── */
        .stock { position: fixed; top: 14px; right: 14px; z-index: 1200; width: min(280px, 80vw);
            background: rgba(255,255,255,.96); border: 1px solid #cbd5e1; border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0,0,0,.3); overflow: hidden; }
        .stock .hd { text-align: center; font-weight: 800; font-size: 13px; letter-spacing: .4px; color: #0f172a; padding: 7px; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
        .stock .row { display: grid; grid-template-columns: 1fr 1fr; }
        .stock .cell { padding: 10px 8px 12px; text-align: center; color: #fff; }
        .stock .cell.ready { background: #16a34a; }
        .stock .cell.sold  { background: #dc2626; }
        .stock .cell .num { font-size: 40px; font-weight: 900; line-height: 1; font-variant-numeric: tabular-nums; }
        .stock .cell .lab { font-size: 11px; font-weight: 700; margin-top: 6px; opacity: .95; }

        /* Tombol layar penuh (Leaflet control) */
        .leaflet-control-fs { display:flex !important; align-items:center; justify-content:center;
            width:34px; height:34px; font-size:18px; line-height:1; color:#374151; text-decoration:none; }
        .leaflet-control-fs:hover { background:#f4f4f5; }

        /* Stempel SOLD (merah) — banner diagonal */
        .sold-div { background: transparent !important; border: 0 !important; width: 0 !important; height: 0 !important; overflow: visible; }
        .sold-stamp { display: inline-block; background: #dc2626; color: #fff; font-weight: 800;
            font-size: 6px; letter-spacing: .3px; line-height: 1; padding: 1px 3.5px; border-radius: 2px;
            transform: translate(-50%, -50%) rotate(-12deg); transform-origin: center center;
            border: .75px solid #fff; box-shadow: 0 1px 2px rgba(0,0,0,.5); white-space: nowrap; }

        .sp-empty { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#94a3b8; text-align:center; padding:24px; }
        .leaflet-tooltip.sp-block-label { background:transparent; color:#fff; border:0; box-shadow:none; font-weight:800; font-size:15px; letter-spacing:.5px; text-shadow:0 1px 4px rgba(0,0,0,.95); }

        /* ── Animasi SOLD OUT ── */
        #soldout { position: fixed; inset: 0; z-index: 2000; display: none; align-items: center; justify-content: center;
            background: rgba(15,23,42,.78); backdrop-filter: blur(2px); overflow: hidden; }
        #soldout.show { display: flex; animation: soFade .4s ease; }
        @keyframes soFade { from { opacity: 0; } to { opacity: 1; } }
        #soldout .badge { text-align: center; transform: rotate(-6deg); }
        #soldout .so-main { font-size: clamp(48px, 14vw, 200px); font-weight: 900; letter-spacing: 4px; color: #fff;
            text-shadow: 0 0 24px rgba(220,38,38,.9), 0 8px 0 #b91c1c; animation: soPulse 1s ease-in-out infinite; }
        #soldout .so-sub { margin-top: 10px; font-size: clamp(16px, 3vw, 30px); font-weight: 800; color: #fecaca; letter-spacing: 6px; }
        @keyframes soPulse { 0%,100% { transform: scale(1); } 50% { transform: scale(1.06); } }
        .confetti { position: absolute; top: -20px; width: 10px; height: 16px; opacity: .95; will-change: transform; animation: soDrop linear forwards; }
        @keyframes soDrop { to { transform: translateY(105vh) rotate(720deg); } }
    </style>
</head>
<body>
    @if (! $siteplan)
        <div class="sp-empty">Site plan <strong>&nbsp;{{ $project->name }}&nbsp;</strong> belum diupload.</div>
    @else
        <div id="map"></div>
    @endif

    @if ($showStock ?? true)
    <div class="stock">
        <div class="hd">Stock Status</div>
        <div class="row">
            <div class="cell ready"><div class="num" id="st-ready">{{ $stock['ready'] }}</div><div class="lab">Unit Ready (<span id="st-ready-pct">{{ $stock['ready_pct'] }}</span>%)</div></div>
            <div class="cell sold"><div class="num" id="st-sold">{{ $stock['sold'] }}</div><div class="lab">Unit Sold (<span id="st-sold-pct">{{ $stock['sold_pct'] }}</span>%)</div></div>
        </div>
    </div>
    @endif

    <div id="soldout"><div class="badge"><div class="so-main">SOLD OUT</div><div class="so-sub">{{ $project->name }}</div></div></div>

    <script>
        const SITEPLAN = @json($siteplan);
        const UNITS = @json($units);
        const PROJECT_ID = {{ $project->id }};
        const FOCUS_LOKASI = @json($focusLokasiId ?? null);  // cluster yg ditampilkan (null = seluruh project)
        const SCOPE_TAHAP = @json($focusTahap);             // '' = semua tahap (dlm scope unit)
        const SHOW_STOCK = {{ ($showStock ?? true) ? 'true' : 'false' }};
        const SOLD_ANIM = {{ ($soldAnimation ?? true) ? 'true' : 'false' }};

        // LIVE: BOOKING dihitung & diwarnai SOLD (merah).
        function statusStyle(status) {
            switch (status) {
                case 'TERSEDIA': return { stroke:'#10b981', fill:'#34d399', badge:'#16a34a', label:'TERSEDIA' };
                default:         return { stroke:'#475569', fill:'#dc2626', badge:'#dc2626', label:'SOLD' };
            }
        }
        const isSold = (s) => s !== 'TERSEDIA';
        const inScope = (u) => SCOPE_TAHAP === '' || String(u.tahap) === String(SCOPE_TAHAP);

        function el(tag, cls) { const e = document.createElement(tag); if (cls) e.className = cls; return e; }

        if (SITEPLAN) {
            const W = SITEPLAN.width, H = SITEPLAN.height;
            const bounds = [[0, 0], [H, W]];
            const map = L.map('map', { crs: L.CRS.Simple, minZoom: -4, maxZoom: 4, zoomSnap: 0.25, attributionControl: false });
            L.imageOverlay(SITEPLAN.url, bounds).addTo(map);
            map.fitBounds(bounds);
            const toLatLng = (x, y) => [H - y, x];

            // ── Tombol layar penuh ──
            function toggleFullscreen() {
                const el = document.documentElement;
                if (!document.fullscreenElement) (el.requestFullscreen || el.webkitRequestFullscreen || (()=>{})).call(el);
                else (document.exitFullscreen || document.webkitExitFullscreen || (()=>{})).call(document);
            }
            const FsControl = L.Control.extend({ onAdd: function () {
                const div = L.DomUtil.create('div', 'leaflet-bar');
                const a = L.DomUtil.create('a', 'leaflet-control-fs', div);
                a.href = '#'; a.title = 'Layar penuh'; a.setAttribute('role', 'button'); a.innerHTML = '⛶';
                L.DomEvent.disableClickPropagation(div);
                L.DomEvent.on(a, 'click', (e) => { L.DomEvent.preventDefault(e); toggleFullscreen(); });
                return div;
            } });
            new FsControl({ position: 'topleft' }).addTo(map);
            document.addEventListener('fullscreenchange', () => setTimeout(() => map.invalidateSize(), 150));

            const soldStamps = [];
            const baseZoom = map.getZoom();
            const updateSoldScale = () => { const k = Math.pow(2, map.getZoom() - baseZoom);
                soldStamps.forEach(s => { s.style.transform = 'translate(-50%, -50%) rotate(-12deg) scale(' + k.toFixed(3) + ')'; }); };
            map.on('zoomend', updateSoldScale);

            const byId = {};
            function addStamp(entry) {
                if (entry.marker || !isSold(entry.u.status)) return;
                const stamp = el('span', 'sold-stamp'); stamp.textContent = 'SOLD';
                soldStamps.push(stamp); entry.stampEl = stamp;
                const b = entry.poly.getBounds();
                const pos = L.latLng(b.getCenter().lat + (b.getNorth() - b.getSouth()) * 0.14, b.getCenter().lng);
                entry.marker = L.marker(pos, { interactive: false, keyboard: false,
                    icon: L.divIcon({ className: 'sold-div', html: stamp, iconSize: [0,0], iconAnchor: [0,0] }) }).addTo(map);
                updateSoldScale();
            }
            function removeStamp(entry) {
                if (!entry.marker) return; map.removeLayer(entry.marker);
                const i = soldStamps.indexOf(entry.stampEl); if (i !== -1) soldStamps.splice(i, 1);
                entry.marker = null; entry.stampEl = null;
            }

            function renderUnit(u) {
                if (!Array.isArray(u.siteplan_coords) || u.siteplan_coords.length < 3) return;
                if (byId[u.id]) return; // sudah tampil
                const st = statusStyle(u.status);
                const latlngs = u.siteplan_coords.map(p => toLatLng(p[0], p[1]));
                // Transparan spt /siteplan → warna desain di gambar tetap tampil; status
                // ditunjukkan via stempel SOLD. Highlight tipis saat hover.
                const poly = L.polygon(latlngs, { color: st.stroke, weight: 0, fill: true, fillColor: st.fill, fillOpacity: 0 }).addTo(map);
                poly.bindTooltip(u.label, { direction: 'center', sticky: true });
                poly.on('mouseover', () => poly.setStyle({ weight: 2, color: statusStyle(byId[u.id].u.status).stroke, fillOpacity: 0.18 }));
                poly.on('mouseout', () => poly.setStyle({ weight: 0, fillOpacity: 0 }));
                const entry = { u, poly, marker: null, stampEl: null };
                byId[u.id] = entry;
                if (isSold(u.status)) addStamp(entry);
            }
            UNITS.forEach(renderUnit);
            updateSoldScale();

            // ── Blok area (penanda tahap, tidak terikat unit) — menutup area belum dibuka ──
            const blockPolys = [];
            (@json($blocks ?? [])).forEach(b => {
                if (!Array.isArray(b.coords) || b.coords.length < 3) return;
                const bp = L.polygon(b.coords.map(p => toLatLng(p[0], p[1])), {
                    color: '#000', weight: 1, fillColor: b.color || '#0a0a0a',
                    fillOpacity: (b.opacity != null ? b.opacity : 0.85), interactive: false,
                }).addTo(map);
                if (b.label) { bp.bindTooltip(b.label, { permanent: true, direction: 'center', className: 'sp-block-label' }); blockPolys.push(bp); }
            });
            // Label blok stabil saat zoom (tooltip center bisa melayang di zoom fraksional).
            map.on('zoomend', () => blockPolys.forEach(bp => { const tt = bp.getTooltip(); if (tt) tt.setLatLng(bp.getBounds().getCenter()); }));

            // ── Stock live (scope = unit dlm SCOPE_TAHAP) ──
            let soldOutFired = false;
            function recomputeStock() {
                let total = 0, ready = 0;
                UNITS.forEach(u => { if (!inScope(u)) return; total++; if (u.status === 'TERSEDIA') ready++; });
                const sold = total - ready;
                const rp = total ? Math.round(ready / total * 100) : 0;
                const sp = total ? Math.round(sold / total * 100) : 0;
                if (SHOW_STOCK) {
                    const set = (id, v) => { const e = document.getElementById(id); if (e) e.textContent = v; };
                    set('st-ready', ready); set('st-sold', sold); set('st-ready-pct', rp); set('st-sold-pct', sp);
                }
                if (SOLD_ANIM && total > 0 && ready === 0 && !soldOutFired) { soldOutFired = true; fireSoldOut(); }
                if (ready > 0) soldOutFired = false; // reset bila stok kembali ada (mis. batal)
            }

            function applyStatusChange(unitId, newStatus) {
                const entry = byId[unitId];
                if (entry) {
                    entry.u.status = newStatus;
                    const st = statusStyle(newStatus);
                    entry.poly.setStyle({ color: st.stroke, fillColor: st.fill, fillOpacity: 0 });
                    removeStamp(entry); if (isSold(newStatus)) addStamp(entry);
                } else {
                    // unit di luar peta (mungkin scope lain) — tetap pengaruhi stok bila ada di UNITS
                    const u = UNITS.find(x => x.id === unitId); if (u) u.status = newStatus;
                }
                recomputeStock();
            }

            // Disable (hold internal) → hilangkan unit + kurangi stok real-time.
            function removeUnitLive(unitId) {
                const entry = byId[unitId];
                if (entry) {
                    if (entry.poly) map.removeLayer(entry.poly);
                    removeStamp(entry);
                    delete byId[unitId];
                }
                const i = UNITS.findIndex(x => x.id === unitId);
                if (i >= 0) UNITS.splice(i, 1);
                recomputeStock();
            }

            // Enable → munculkan unit kembali (dari payload event) bila di cluster ini.
            function addUnitLive(e) {
                if (FOCUS_LOKASI !== null && e.lokasi_id !== FOCUS_LOKASI) return; // beda cluster
                const u = {
                    id: e.unit_id, label: e.label || ('#' + e.unit_id),
                    status: e.status || 'TERSEDIA', tahap: e.tahap, siteplan_coords: e.coords,
                };
                const existing = UNITS.find(x => x.id === u.id);
                if (existing) Object.assign(existing, u); else UNITS.push(u);
                renderUnit(u);
                updateSoldScale();
                recomputeStock();
            }

            // ── Subscribe Reverb — real-time ──
            (function sub(attempt) {
                if (window.Echo) {
                    window.Echo.channel('siteplan.' + PROJECT_ID)
                        .listen('.unit.status.changed', (e) => { if (e && e.unit_id) applyStatusChange(e.unit_id, e.new_status); })
                        .listen('.unit.visibility.changed', (e) => {
                            if (!e || !e.unit_id) return;
                            if (e.is_active) addUnitLive(e); else removeUnitLive(e.unit_id);
                        });
                    return;
                }
                if (attempt < 40) setTimeout(() => sub(attempt + 1), 250);
            })(0);

            recomputeStock(); // sinkron awal + cek sudah sold-out
        }

        // ── Animasi SOLD OUT ──
        function fireSoldOut() {
            const ov = document.getElementById('soldout');
            ov.classList.add('show');
            const colors = ['#dc2626','#16a34a','#f59e0b','#3b82f6','#ec4899','#fde047','#fff'];
            for (let i = 0; i < 140; i++) {
                const c = el('div', 'confetti');
                c.style.left = Math.random() * 100 + 'vw';
                c.style.background = colors[i % colors.length];
                c.style.animationDuration = (2.2 + Math.random() * 2.2) + 's';
                c.style.animationDelay = (Math.random() * 0.8) + 's';
                ov.appendChild(c);
            }
            // klik untuk tutup overlay (peta tetap update)
            ov.addEventListener('click', () => ov.classList.remove('show'), { once: true });
        }
    </script>
</body>
</html>
