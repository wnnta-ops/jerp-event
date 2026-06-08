<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="robots" content="index,follow">
    <title>Site Plan — {{ $project->name }}{{ $cluster ? ' · '.$cluster : '' }}</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    {{-- Laravel Echo (window.Echo) untuk update real-time unit terjual via Reverb. --}}
    @vite(['resources/js/app.js'])
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; height: 100%; font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: #1e293b; }
        #map { position: absolute; inset: 0; background: #1e293b; }

        /* ── Info panel (Show/Hide Information) — top right, like legacy ── */
        .info-wrap { position: fixed; top: 12px; right: 12px; z-index: 1000; width: min(320px, 86vw); }
        .info-toggle {
            width: 100%; text-align: center; font-weight: 700; font-size: 14px;
            background: rgba(255,255,255,.95); border: 1px solid #cbd5e1; border-radius: 8px;
            padding: 8px 12px; cursor: pointer; box-shadow: 0 4px 14px rgba(0,0,0,.25);
        }
        .info-toggle:hover { background: #fff; }
        .info-body { margin-top: 8px; max-height: calc(100vh - 80px); overflow-y: auto; display: flex; flex-direction: column; gap: 8px; }
        .info-body.hidden { display: none; }
        .info-card {
            background: rgba(255,255,255,.94); border: 1px solid #cbd5e1; border-radius: 8px;
            padding: 10px 14px; box-shadow: 0 6px 18px rgba(0,0,0,.22); font-size: 13px;
        }
        .info-card .title { text-align: right; font-weight: 800; color: #0f172a; margin-bottom: 4px; }
        .info-card .line { display: grid; grid-template-columns: 1fr auto auto; gap: 4px 10px; align-items: baseline; }
        .info-card .line .k { color: #334155; }
        .info-card .line .v { font-weight: 700; font-variant-numeric: tabular-nums; text-align: right; }
        .info-card .line .p { color: #64748b; font-variant-numeric: tabular-nums; text-align: right; min-width: 64px; }
        .info-card .ready .v { color: #16a34a; }
        .info-card .sold  .v { color: #dc2626; }
        .info-card .hold  .v { color: #d97706; }

        /* ── Status legend — bottom left ── */
        .legend {
            position: fixed; bottom: 14px; left: 14px; z-index: 1000;
            background: rgba(255,255,255,.92); border: 1px solid #cbd5e1; border-radius: 10px;
            padding: 10px 12px; box-shadow: 0 6px 18px rgba(0,0,0,.22); font-size: 12px;
        }
        .legend .row { display: flex; align-items: center; gap: 8px; margin: 3px 0; color: #334155; }
        .legend .sw { width: 14px; height: 14px; border-radius: 4px; border: 1px solid rgba(0,0,0,.15); }

        /* Stempel status (SOLD merah / BOOKING oranye) — banner diagonal */
        .sold-div { background: transparent !important; border: 0 !important; }
        .sold-div { width: 0 !important; height: 0 !important; overflow: visible; }
        .sold-stamp {
            display: inline-block; background: #dc2626; color: #fff; font-weight: 800;
            font-size: 6px; letter-spacing: .3px; line-height: 1; padding: 1px 3.5px; border-radius: 2px;
            transform: translate(-50%, -50%) rotate(-12deg); transform-origin: center center;
            border: .75px solid #fff; box-shadow: 0 1px 2px rgba(0,0,0,.5);
            white-space: nowrap; text-shadow: 0 1px 1px rgba(0,0,0,.25);
        }
        .sold-stamp.booking { background: #d97706; }   /* BOOKING */

        /* Popup — Design C: kartu terang + harga sejajar (modern) */
        .leaflet-popup-content-wrapper { background:#fff; color:#0f172a; border-radius:16px; padding:0; box-shadow:0 20px 50px rgba(15,23,42,.25); border:1px solid #e2e8f0; }
        .leaflet-popup-tip { background:#fff; border:1px solid #e2e8f0; box-shadow:none; }
        .leaflet-popup-content { margin:0; min-width: 330px; }
        .leaflet-popup-close-button { color:#94a3b8 !important; font-size:20px !important; width:26px !important; height:26px !important; padding:4px 0 0 0 !important; }
        .leaflet-popup-close-button:hover { color:#334155 !important; }

        .sp-card { padding: 16px; font-family: inherit; }
        .sp-head { display:flex; align-items:center; justify-content:space-between; gap:10px; }
        .sp-code { font-size:17px; font-weight:800; color:#0f172a; letter-spacing:.2px; }
        .sp-pill { font-size:10px; font-weight:800; letter-spacing:.6px; padding:4px 10px; border-radius:999px; color:#fff; white-space:nowrap; box-shadow:0 2px 6px rgba(0,0,0,.12); }
        .sp-loc { font-size:11.5px; color:#64748b; margin-top:2px; }
        .sp-sub { font-size:12.5px; color:#475569; margin-top:8px; }
        .sp-sub b { color:#0f172a; font-weight:700; }
        .sp-addr { display:flex; gap:6px; font-size:11.5px; color:#64748b; line-height:1.45; margin-top:6px; }
        .sp-addr .pin { flex:0 0 auto; }

        .sp-prices { display:grid; grid-template-columns: repeat(3, 1fr); gap:8px; margin-top:13px; }
        .sp-prices.one { grid-template-columns: 1fr; }
        .sp-pc { background:#f8fafc; border:1px solid #e7ebf0; border-radius:11px; padding:9px 5px; text-align:center; transition:transform .12s, box-shadow .12s; }
        .sp-pc:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(15,23,42,.10); }
        .sp-pc .lab { font-size:9.5px; font-weight:800; letter-spacing:.4px; color:#2563eb; margin-bottom:4px; }
        .sp-pc .cur { font-size:8px; font-weight:700; color:#94a3b8; line-height:1; }
        .sp-pc .amt { font-size:12px; font-weight:800; color:#0f172a; line-height:1.15; white-space:nowrap; }

        .sp-empty { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#94a3b8; text-align:center; padding:24px; }
    </style>
</head>
<body>
    @if (! $siteplan)
        <div class="sp-empty">
            Site plan untuk proyek <strong>&nbsp;{{ $project->name }}&nbsp;</strong> belum diupload.<br>
            <small>Admin: upload via menu FO → Upload Site Plan + Polygon Editor.</small>
        </div>
    @else
        <div id="map"></div>
    @endif

    {{-- ── Show / Hide Information ── --}}
    <div class="info-wrap">
        <button class="info-toggle" id="info-toggle"
                onclick="(function(b){var x=document.getElementById('info-body');var h=x.classList.toggle('hidden');b.textContent=h?'Show Information':'Hide Information';})(this)">Hide Information</button>
        <div class="info-body" id="info-body">
            @forelse ($tahapStats as $t)
                <div class="info-card" data-key="{{ $t['lokasi'] }}|{{ $t['tahap'] }}">
                    <div class="title">{{ $t['lokasi'] }}{{ $t['tahap'] !== '-' ? ' Tahap '.$t['tahap'] : '' }}</div>
                    <div class="line ready"><span class="k">Unit Ready</span><span class="v" data-v="ready">{{ $t['ready'] }}</span><span class="p" data-p="ready">({{ number_format($t['ready_pct'], 2) }}%)</span></div>
                    <div class="line sold"><span class="k">Unit Sold</span><span class="v" data-v="sold">{{ $t['sold'] }}</span><span class="p" data-p="sold">({{ number_format($t['sold_pct'], 2) }}%)</span></div>
                    <div class="line hold"><span class="k">Unit Hold</span><span class="v" data-v="hold">{{ $t['hold'] }}</span><span class="p" data-p="hold">({{ number_format($t['hold_pct'], 2) }}%)</span></div>
                </div>
            @empty
                <div class="info-card"><div class="title">{{ $project->name }}</div><div class="line"><span class="k">Belum ada data unit.</span></div></div>
            @endforelse
        </div>
    </div>

    {{-- ── Legend (clean) ── --}}
    <div class="legend">
        <div class="row"><span class="sold-stamp" style="transform:none">SOLD</span> Unit terjual</div>
        <div class="row" style="margin-top:6px; color:#475569;">Klik kavling untuk detail &amp; harga.</div>
    </div>

    <script>
        const SITEPLAN = @json($siteplan);
        const UNITS = @json($units);
        const PROJECT_ID = {{ $project->id }};

        function fmtRp(n) { return 'Rp ' + (Number(n) || 0).toLocaleString('id-ID'); }

        // Harga ringkas untuk kartu: 619000000 → {num:'619', unit:'Jt'}, 1200000000 → {num:'1,2', unit:'M'}
        function fmtShort(n) {
            n = Number(n) || 0;
            if (n >= 1e9) {
                const m = Math.round((n / 1e9) * 10) / 10;
                return { num: m.toLocaleString('id-ID'), unit: 'M' };
            }
            return { num: Math.round(n / 1e6).toLocaleString('id-ID'), unit: 'Jt' };
        }

        function statusStyle(status) {
            switch (status) {
                case 'TERSEDIA': return { stroke:'#10b981', fill:'#34d399', badge:'#16a34a', label:'TERSEDIA' };
                case 'BOOKING':  return { stroke:'#f59e0b', fill:'#fbbf24', badge:'#d97706', label:'BOOKING' };
                case 'HOLD':     return { stroke:'#f97316', fill:'#fb923c', badge:'#ea580c', label:'HOLD' };
                case 'TERJUAL':
                case 'LUNAS':    return { stroke:'#475569', fill:'#94a3b8', badge:'#dc2626', label:'SOLD' };
                default:         return { stroke:'#94a3b8', fill:'#cbd5e1', badge:'#64748b', label:(status||'-') };
            }
        }

        function el(tag, opts) {
            const e = document.createElement(tag);
            if (opts && opts.class) e.className = opts.class;
            if (opts && opts.text != null) e.textContent = opts.text;
            return e;
        }
        function infoRow(label, value) {
            const row = el('div', { class: 'row' });
            row.appendChild(el('div', { class: 'lbl', text: label }));
            row.appendChild(el('div', { class: 'val', text: (value != null && value !== '' ? value : '—') }));
            return row;
        }

        if (SITEPLAN) {
            const W = SITEPLAN.width, H = SITEPLAN.height;
            const bounds = [[0, 0], [H, W]];
            const map = L.map('map', { crs: L.CRS.Simple, minZoom: -4, maxZoom: 4, zoomSnap: 0.25, attributionControl: true });
            map.attributionControl.setPrefix(false);
            map.attributionControl.addAttribution('{{ $project->name }}');

            L.imageOverlay(SITEPLAN.url, bounds).addTo(map);
            map.fitBounds(bounds);

            const toLatLng = (x, y) => [H - y, x];

            // Stempel per status: SOLD (terjual/lunas) atau BOOKING. null = tanpa stempel.
            const stampFor = (s) => (s === 'TERJUAL' || s === 'LUNAS')
                ? { text: 'SOLD', cls: '' }
                : (s === 'BOOKING' ? { text: 'BOOKING', cls: ' booking' } : null);

            // Stempel SOLD ikut skala zoom: di zoom paling jauh (fit) ukurannya kecil,
            // membesar proporsional saat zoom-in supaya selalu pas dengan kavling.
            const soldStamps = [];
            const baseZoom = map.getZoom();
            const updateSoldScale = () => {
                const k = Math.pow(2, map.getZoom() - baseZoom);
                soldStamps.forEach(s => { s.style.transform = 'translate(-50%, -50%) rotate(-12deg) scale(' + k.toFixed(3) + ')'; });
            };
            map.on('zoomend', updateSoldScale);

            // Lookup per unit id supaya update real-time bisa cari poly/stamp/unit.
            const byId = {};

            function addSoldStamp(entry) {
                if (entry.marker) return; // sudah ada
                const info = stampFor(entry.u.status);
                if (!info) return;
                const stamp = el('span', { class: 'sold-stamp' + info.cls, text: info.text });
                soldStamps.push(stamp);
                entry.stampEl = stamp;
                entry.marker = L.marker(entry.poly.getBounds().getCenter(), {
                    interactive: false,
                    keyboard: false,
                    icon: L.divIcon({ className: 'sold-div', html: stamp, iconSize: [0, 0], iconAnchor: [0, 0] }),
                }).addTo(map);
                updateSoldScale();
            }

            function removeSoldStamp(entry) {
                if (!entry.marker) return;
                map.removeLayer(entry.marker);
                const i = soldStamps.indexOf(entry.stampEl);
                if (i !== -1) soldStamps.splice(i, 1);
                entry.marker = null; entry.stampEl = null;
            }

            UNITS.forEach(u => {
                if (!Array.isArray(u.siteplan_coords) || u.siteplan_coords.length < 3) return;
                const st = statusStyle(u.status);
                const latlngs = u.siteplan_coords.map(p => toLatLng(p[0], p[1]));

                // Transparent by default → siteplan stays clean. Faint highlight on hover.
                const poly = L.polygon(latlngs, { color: st.stroke, weight: 0, fill: true, fillColor: st.fill, fillOpacity: 0 }).addTo(map);
                poly.bindTooltip(u.label, { direction: 'center', sticky: true });
                poly.on('mouseover', () => poly.setStyle({ weight: 2, color: statusStyle(u.status).stroke, fillOpacity: 0.18 }));
                poly.on('mouseout', () => poly.setStyle({ weight: 0, fillOpacity: 0 }));

                const entry = { u, poly, marker: null, stampEl: null };
                byId[u.id] = entry;

                if (stampFor(u.status)) addSoldStamp(entry);

                poly.on('click', () => {
                    const cur = statusStyle(u.status); // status terkini (bisa berubah real-time)
                    const card = el('div', { class: 'sp-card' });

                    // Header: No. Unit (hero) + status pill
                    const head = el('div', { class: 'sp-head' });
                    head.appendChild(el('div', { class: 'sp-code', text: u.kode || u.label }));
                    const pill = el('span', { class: 'sp-pill', text: cur.label });
                    pill.style.background = cur.badge;
                    head.appendChild(pill);
                    card.appendChild(head);

                    // Lokasi (deskriptif)
                    if (u.nama_lokasi) card.appendChild(el('div', { class: 'sp-loc', text: u.nama_lokasi }));

                    // Tipe · LT/LB
                    const sub = el('div', { class: 'sp-sub' });
                    sub.appendChild(el('b', { text: u.tipe || '—' }));
                    sub.appendChild(document.createTextNode('  ·  LT ' + (u.luas_tanah || '—') + ' / LB ' + (u.luas_bangunan || '—')));
                    card.appendChild(sub);

                    // Alamat
                    if (u.alamat) {
                        const addr = el('div', { class: 'sp-addr' });
                        addr.appendChild(el('span', { class: 'pin', text: '📍' }));
                        addr.appendChild(el('span', { text: u.alamat }));
                        card.appendChild(addr);
                    }

                    // Harga per skema → kartu sejajar
                    let rows = [];
                    if (Array.isArray(u.pricelist) && u.pricelist.length) {
                        rows = u.pricelist.map(p => ({
                            label: (p.skema_code || p.skema_name || p.cara_bayar || '').toString().toUpperCase(),
                            price: Number(p.price) || 0,
                        }));
                    } else if (u.harga > 0) {
                        rows = [{ label: 'HARGA', price: Number(u.harga) || 0 }];
                    }
                    if (rows.length) {
                        const wrap = el('div', { class: 'sp-prices' + (rows.length === 1 ? ' one' : '') });
                        rows.forEach(r => {
                            const pc = el('div', { class: 'sp-pc' });
                            pc.appendChild(el('div', { class: 'lab', text: r.label }));
                            pc.appendChild(el('div', { class: 'cur', text: 'Rp' }));
                            // Angka PENUH (mis. 619.000.000) — hindari salah info dari singkatan.
                            pc.appendChild(el('div', { class: 'amt', text: (Number(r.price) || 0).toLocaleString('id-ID') }));
                            wrap.appendChild(pc);
                        });
                        card.appendChild(wrap);
                    }

                    poly.bindPopup(card, { maxWidth: 340, autoPan: true }).openPopup();
                });
            });

            updateSoldScale(); // skala awal sesuai zoom fit

            // ── Statistik live: hitung ulang Ready/Sold/Hold dari UNITS, tulis ke panel ──
            function recomputeStats() {
                const groups = {};
                UNITS.forEach(u => {
                    const lok = u.lokasi_kode || '-';
                    const tahap = (u.tahap !== null && u.tahap !== undefined && u.tahap !== '') ? u.tahap : '-';
                    const key = lok + '|' + tahap;
                    if (!groups[key]) groups[key] = { ready: 0, sold: 0, hold: 0 };
                    if (u.status === 'TERSEDIA') groups[key].ready++;
                    else if (u.status === 'HOLD') groups[key].hold++;
                    else if (u.status === 'TERJUAL' || u.status === 'LUNAS' || u.status === 'BOOKING') groups[key].sold++;
                });
                Object.entries(groups).forEach(([key, g]) => {
                    const card = document.querySelector('.info-card[data-key="' + key + '"]');
                    if (!card) return;
                    const total = g.ready + g.sold + g.hold;
                    const pct = (n) => total > 0 ? (Math.round(n / total * 10000) / 100).toFixed(2) : '0.00';
                    const set = (bucket, val) => {
                        const v = card.querySelector('[data-v="' + bucket + '"]');
                        const p = card.querySelector('[data-p="' + bucket + '"]');
                        if (v) v.textContent = val;
                        if (p) p.textContent = '(' + pct(val) + '%)';
                    };
                    set('ready', g.ready); set('sold', g.sold); set('hold', g.hold);
                });
            }

            // ── Terapkan perubahan status (dari broadcast) ke peta + panel ──
            function applyStatusChange(unitId, newStatus) {
                const entry = byId[unitId];
                if (!entry) return;
                entry.u.status = newStatus;
                const st = statusStyle(newStatus);
                entry.poly.setStyle({ color: st.stroke, fillColor: st.fill });
                // Remove dulu lalu pasang ulang → teks stempel ikut berubah saat
                // transisi (mis. BOOKING → SOLD).
                removeSoldStamp(entry);
                if (stampFor(newStatus)) addSoldStamp(entry);
                recomputeStats();
            }

            // ── Subscribe Reverb (public channel) — update unit terjual real-time ──
            if (window.Echo) {
                window.Echo.channel('siteplan.' + PROJECT_ID)
                    .listen('.unit.status.changed', (e) => {
                        if (e && e.unit_id) applyStatusChange(e.unit_id, e.new_status);
                    });
            }
        }
    </script>
</body>
</html>
