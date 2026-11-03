<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="robots" content="index,follow">
    <title>Site Plan — {{ $project->name }}{{ isset($lokasi) && $lokasi ? ' · '.$lokasi->nama : '' }}</title>
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

        /* Sembunyikan "status" (setelan admin): stempel SOLD/BOOKED di peta + pill status di popup */
        body.hide-status .sold-stamp { display: none !important; }
        body.hide-status .sp-pill { display: none !important; }

        /* Pilih cluster (Lokasi) — tombol pojok kiri atas + daftar dengan pencarian */
        .pick { position: fixed; top: 12px; left: 12px; z-index: 1001; }
        .pick-btn {
            display: flex; align-items: center; gap: 12px; max-width: calc(100vw - 24px);
            border: 1px solid #cbd5e1; background: rgba(255,255,255,.95); color: #1e293b; font: inherit;
            border-radius: 12px; padding: 8px 14px 8px 10px; cursor: pointer; text-align: left;
            box-shadow: 0 6px 20px rgba(0,0,0,.3);
        }
        .pick-btn:hover { background: #fff; }
        .pick-ikon { flex: 0 0 auto; width: 32px; height: 32px; border-radius: 8px; background: #2563eb; color: #fff; display: grid; place-items: center; font-weight: 800; font-size: 13px; }
        .pick-teks { min-width: 0; }
        .pick-teks small { display: block; font-size: 10.5px; font-weight: 700; letter-spacing: .07em; color: #64748b; text-transform: uppercase; }
        .pick-teks strong { display: block; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .pick-chev { color: #64748b; transition: transform .2s; }
        .pick.open .pick-chev { transform: rotate(180deg); }
        .pick-pop {
            position: absolute; top: calc(100% + 8px); left: 0; width: min(330px, calc(100vw - 24px));
            background: #fff; color: #1e293b; border-radius: 14px; padding: 8px;
            box-shadow: 0 18px 50px rgba(0,0,0,.45); animation: pick-muncul .18s ease;
        }
        @keyframes pick-muncul { from { opacity: 0; transform: translateY(-6px); } }
        .pick-pop input { width: 100%; font: inherit; font-size: 14px; border: 1px solid #e2e8f0; border-radius: 9px; padding: 8px 11px; outline: none; background: #f8fafc; }
        .pick-pop input:focus { border-color: #2563eb; background: #fff; }
        .pick-list { list-style: none; margin: 6px 0 0; padding: 0; max-height: min(320px, 60vh); overflow-y: auto; }
        .pick-list a { display: grid; grid-template-columns: 1fr auto; gap: 1px 10px; align-items: center; padding: 8px 10px; border-radius: 9px; color: inherit; text-decoration: none; }
        .pick-list a:hover, .pick-list a:focus-visible { background: #eff6ff; outline: none; }
        .pick-list a[aria-selected="true"] { background: #dbeafe; }
        .pick-list .n { font-weight: 700; font-size: 13.5px; }
        .pick-list .k { font-size: 11.5px; color: #64748b; grid-column: 1; }
        .pick-list .sisa { grid-row: 1 / span 2; grid-column: 2; font-size: 11.5px; font-weight: 800; padding: 3px 9px; border-radius: 999px; background: #dcfce7; color: #15803d; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .pick-list .sisa.habis { background: #fee2e2; color: #b91c1c; }
        .pick-kosong { padding: 12px 10px; font-size: 13px; color: #64748b; }
        .pick-btn:focus-visible { outline: 2px solid #93c5fd; outline-offset: 2px; }
        @media (max-width: 768px) { .info-wrap { top: 72px; } }
        @media (prefers-reduced-motion: reduce) { .pick-pop { animation: none; } .pick-chev { transition: none; } }
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
        .info-card .hold  .v { color: #7c3aed; }

        .leaflet-tooltip.sp-block-label { background:transparent; color:#fff; border:0; box-shadow:none; font-weight:800; font-size:15px; letter-spacing:.5px; text-shadow:0 1px 4px rgba(0,0,0,.95); }

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
        .sold-stamp.booking { background: #d97706; }   /* BOOKING (amber) */
        .sold-stamp.hold { background: #7c3aed; }      /* HOLD (ungu — dibedakan dari SOLD/BOOKED) */

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
<body class="{{ ($showStatus ?? true) ? '' : 'hide-status' }}">
    {{-- Switcher cluster (Lokasi). Tiap cluster pakai siteplan-nya sendiri. --}}
    @if (isset($clusters) && $clusters->count() > 1)
        <div class="pick" id="pick">
            <button type="button" class="pick-btn" id="pick-btn" aria-haspopup="listbox" aria-expanded="false" aria-controls="pick-pop">
                <span class="pick-ikon">{{ $lokasi ? mb_substr(preg_replace('/^JR/i', '', $lokasi->kode), 0, 3) : '?' }}</span>
                <span class="pick-teks"><small>Cluster</small><strong>{{ $lokasi?->nama ?? 'Pilih cluster' }}</strong></span>
                <span class="pick-chev" aria-hidden="true">▾</span>
            </button>
            <div class="pick-pop" id="pick-pop" hidden>
                <input type="search" id="pick-cari" placeholder="Cari cluster…" autocomplete="off" aria-label="Cari cluster">
                <ul class="pick-list" role="listbox" aria-label="Daftar cluster">
                    @foreach ($clusters as $cl)
                        @php($stok = $clusterStok[$cl->id] ?? null)
                        @php($aktif = $lokasi && $lokasi->id === $cl->id)
                        <li data-cari="{{ mb_strtolower($cl->nama.' '.$cl->kode) }}">
                            <a href="?cluster={{ $cl->kode }}" role="option" aria-selected="{{ $aktif ? 'true' : 'false' }}">
                                <span class="n">{{ $cl->nama }}</span>
                                <span class="k">{{ $cl->kode }} · {{ (int) ($stok->total ?? 0) }} unit</span>
                                <span class="sisa {{ (int) ($stok->ready ?? 0) > 0 ? '' : 'habis' }}" @if ($aktif) id="pick-sisa-aktif" @endif>{{ (int) ($stok->ready ?? 0) > 0 ? (int) $stok->ready.' siap' : 'habis' }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
                <div class="pick-kosong" id="pick-kosong" hidden>Tidak ada cluster dengan nama itu.</div>
            </div>
        </div>
    @endif
    @if (! $siteplan)
        <div class="sp-empty">
            Site plan untuk proyek <strong>&nbsp;{{ $project->name }}&nbsp;</strong> belum diupload.<br>
            <small>Admin: upload via menu FO → Upload Site Plan + Polygon Editor.</small>
        </div>
    @else
        <div id="map"></div>
    @endif

    {{-- ── Panel Informasi (statistik). Tampil sesuai setelan admin di Upload
         Site Plan (siteplan_show_info). Pengunjung tak melihat checklist. ── --}}
    @if ($showInfo ?? true)
    <div class="info-wrap">
        <button type="button" class="info-toggle" id="info-toggle"
                aria-expanded="true" aria-controls="info-body">Sembunyikan Informasi</button>
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
    @endif

    <script>
        const SITEPLAN = @json($siteplan);
        const UNITS = @json($units);
        const PROJECT_ID = {{ $project->id }};
        const FOCUS_LOKASI = {{ isset($lokasi) && $lokasi ? $lokasi->id : 'null' }};  // cluster ditampilkan (null = semua)

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
                case 'HOLD':     return { stroke:'#7c3aed', fill:'#a78bfa', badge:'#7c3aed', label:'HOLD' };
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
            // Tombol zoom di kanan bawah: pojok kiri atas dipakai tombol Pilih cluster.
            const map = L.map('map', { crs: L.CRS.Simple, minZoom: -4, maxZoom: 4, zoomSnap: 0.25, attributionControl: true, zoomControl: false });
            L.control.zoom({ position: 'bottomright' }).addTo(map);
            map.attributionControl.setPrefix(false);
            map.attributionControl.addAttribution('{{ $project->name }}');

            L.imageOverlay(SITEPLAN.url, bounds).addTo(map);
            map.fitBounds(bounds);

            const toLatLng = (x, y) => [H - y, x];

            const isHold = (s) => String(s || '').toUpperCase() === 'HOLD';

            // Stempel per status: SOLD (terjual/lunas), BOOKING, atau HOLD. null = tanpa stempel.
            const stampFor = (s) => (s === 'TERJUAL' || s === 'LUNAS')
                ? { text: 'SOLD', cls: '' }
                : (s === 'BOOKING' ? { text: 'BOOKED', cls: ' booking' }
                : (s === 'HOLD' ? { text: 'HOLD', cls: ' hold' } : null));

            // Stempel diskalakan terhadap LEBAR KAVLING dalam piksel gambar, bukan
            // terhadap zoom awal. Zoom awal berasal dari fitBounds sehingga berbeda
            // menurut lebar layar: di HP nilainya jauh lebih kecil, kavlingnya ikut
            // mengecil, tetapi stempel tetap dipatok 6px pada zoom itu — hasilnya
            // stempel lebih besar daripada kavlingnya dan siteplan tertutup total.
            // Diikat ke ukuran kavling, proporsinya sama di layar mana pun.
            const soldStamps = [];
            const lebarKavling = (() => {
                const w = UNITS
                    .filter(u => Array.isArray(u.siteplan_coords) && u.siteplan_coords.length >= 3)
                    .map(u => { const xs = u.siteplan_coords.map(p => p[0]); return Math.max(...xs) - Math.min(...xs); })
                    .filter(v => v > 0).sort((a, b) => a - b);
                return w.length ? w[Math.floor(w.length / 2)] : 40;   // median, tahan kavling ganjil
            })();
            const updateSoldScale = () => {
                const lebarLayar = lebarKavling * Math.pow(2, map.getZoom());
                soldStamps.forEach(s => {
                    // Lebar asli diukur sekali; teks stempel berbeda panjang
                    // (SOLD/BOOKED/HOLD) sehingga tiap stempel butuh acuannya sendiri.
                    if (!s._asli && s.offsetWidth) s._asli = s.offsetWidth;
                    const k = (lebarLayar * 0.78) / (s._asli || 24);
                    s.style.transform = 'translate(-50%, -50%) rotate(-12deg) scale(' + k.toFixed(3) + ')';
                });
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
                // Center horizontal + dinaikkan ~14% tinggi unit (ubah 0.14 utk geser).
                const _b = entry.poly.getBounds();
                const _pos = L.latLng(_b.getCenter().lat + (_b.getNorth() - _b.getSouth()) * 0.14, _b.getCenter().lng);
                entry.marker = L.marker(_pos, {
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

            function renderUnit(u) {
                if (!Array.isArray(u.siteplan_coords) || u.siteplan_coords.length < 3) return;
                if (byId[u.id]) return; // sudah tampil
                const st = statusStyle(u.status);
                const latlngs = u.siteplan_coords.map(p => toLatLng(p[0], p[1]));

                // Unit HOLD (ditahan sales) ditutup BLOK HITAM pekat & non-interaktif —
                // tak bisa diklik, tanpa tooltip/popup, dan tanpa stempel (tertutup blok).
                const hold = isHold(u.status);

                // Transparent by default → siteplan stays clean. Faint highlight on hover.
                const poly = L.polygon(latlngs, hold
                    ? { color: '#000000', weight: 1, fill: true, fillColor: '#000000', fillOpacity: 0.92, interactive: false }
                    : { color: st.stroke, weight: 0, fill: true, fillColor: st.fill, fillOpacity: 0 }
                ).addTo(map);

                if (!hold) {
                    poly.bindTooltip(u.label, { direction: 'center', sticky: true });
                    poly.on('mouseover', () => poly.setStyle({ weight: 2, color: statusStyle(u.status).stroke, fillOpacity: 0.18 }));
                    poly.on('mouseout', () => poly.setStyle({ weight: 0, fillOpacity: 0 }));
                }

                const entry = { u, poly, marker: null, stampEl: null, hold };
                byId[u.id] = entry;

                if (!hold && stampFor(u.status)) addSoldStamp(entry);
                if (hold) return; // tanpa handler klik

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
                            // Tampilkan NAMA skema (CASH/KPR/INHOUSE), bukan kode angka (1/2/3).
                            label: (p.skema_name || p.cara_bayar || p.skema_code || '').toString().toUpperCase(),
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
            }
            UNITS.forEach(renderUnit);

            updateSoldScale(); // skala awal sesuai zoom fit

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
                // Sisa unit cluster yang sedang dibuka di daftar Pilih cluster.
                const sisa = document.getElementById('pick-sisa-aktif');
                if (sisa) {
                    const ready = Object.values(groups).reduce((n, g) => n + g.ready, 0);
                    sisa.textContent = ready > 0 ? ready + ' siap' : 'habis';
                    sisa.classList.toggle('habis', ready === 0);
                }
            }

            // ── Terapkan perubahan status (dari broadcast) ke peta + panel ──
            function applyStatusChange(unitId, newStatus) {
                const entry = byId[unitId];
                if (!entry) return;
                entry.u.status = newStatus;

                // Masuk/keluar HOLD mengubah INTERAKTIVITAS layer, dan itu tak bisa diubah
                // setelah layer dibuat → gambar ulang. Tanpa ini, unit yang baru di-HOLD
                // tetap bisa diklik (atau sebaliknya, yang hold-nya dibuka tetap mati).
                if (!!entry.hold !== isHold(newStatus)) {
                    const u = entry.u;
                    removeSoldStamp(entry);
                    map.removeLayer(entry.poly);
                    delete byId[unitId];
                    renderUnit(u);
                    updateSoldScale();
                    recomputeStats();

                    return;
                }

                const st = statusStyle(newStatus);
                entry.poly.setStyle({ color: st.stroke, fillColor: st.fill });
                // Remove dulu lalu pasang ulang → teks stempel ikut berubah saat
                // transisi (mis. BOOKING → SOLD).
                removeSoldStamp(entry);
                if (stampFor(newStatus)) addSoldStamp(entry);
                recomputeStats();
            }

            // Disable (hold internal) → hilangkan unit + update stats real-time.
            function removeUnitLive(unitId) {
                const entry = byId[unitId];
                if (entry) {
                    if (entry.poly) map.removeLayer(entry.poly);
                    removeSoldStamp(entry);
                    delete byId[unitId];
                }
                const i = UNITS.findIndex(x => x.id === unitId);
                if (i >= 0) UNITS.splice(i, 1);
                recomputeStats();
            }

            // Enable → munculkan unit kembali (payload event) bila di cluster ini.
            function addUnitLive(e) {
                if (FOCUS_LOKASI !== null && e.lokasi_id !== FOCUS_LOKASI) return;
                const u = {
                    id: e.unit_id, label: e.label || ('#' + e.unit_id),
                    status: e.status || 'TERSEDIA', tahap: e.tahap, siteplan_coords: e.coords,
                };
                const existing = UNITS.find(x => x.id === u.id);
                if (existing) Object.assign(existing, u); else UNITS.push(u);
                renderUnit(u);
                updateSoldScale();
                recomputeStats();
            }

            // ── Subscribe Reverb (public channel) — update unit terjual real-time ──
            // window.Echo dimuat dari Vite app.js (module, deferred) → sering BELUM
            // siap saat baris ini jalan. Tanpa retry, langganan terlewat → tak pernah
            // real-time. Poll sampai Echo tersedia (maks ~10 dtk), spt di Pilih Unit.
            (function subscribeRealtime(attempt) {
                if (window.Echo) {
                    window.Echo.channel('siteplan.' + PROJECT_ID)
                        .listen('.unit.status.changed', (e) => {
                            if (e && e.unit_id) applyStatusChange(e.unit_id, e.new_status);
                        })
                        .listen('.unit.visibility.changed', (e) => {
                            if (!e || !e.unit_id) return;
                            if (e.is_active) addUnitLive(e); else removeUnitLive(e.unit_id);
                        });
                    return;
                }
                if (attempt < 40) setTimeout(() => subscribeRealtime(attempt + 1), 250);
            })(0);
        }

        // ── Pilih cluster: buka/tutup daftar, cari, Esc menutup, Enter membuka hasil pertama ──
        (function () {
            const pick = document.getElementById('pick');
            if (!pick) return;
            const tombol = document.getElementById('pick-btn');
            const pop = document.getElementById('pick-pop');
            const cari = document.getElementById('pick-cari');
            const kosong = document.getElementById('pick-kosong');
            const items = Array.from(pop.querySelectorAll('.pick-list li'));
            const saring = () => {
                const q = cari.value.trim().toLowerCase();
                let ada = 0;
                items.forEach(li => { const ok = !q || li.dataset.cari.includes(q); li.hidden = !ok; ada += ok ? 1 : 0; });
                kosong.hidden = ada > 0;
            };
            const setel = (buka) => {
                pop.hidden = !buka;
                pick.classList.toggle('open', buka);
                tombol.setAttribute('aria-expanded', buka ? 'true' : 'false');
                if (buka) { cari.value = ''; saring(); cari.focus(); }
            };
            tombol.addEventListener('click', () => setel(pop.hidden));
            cari.addEventListener('input', saring);
            cari.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') { setel(false); tombol.focus(); }
                if (e.key === 'Enter') { const li = items.find(x => !x.hidden); if (li) li.querySelector('a').click(); }
            });
            pop.addEventListener('keydown', (e) => { if (e.key === 'Escape') { setel(false); tombol.focus(); } });
            document.addEventListener('click', (e) => { if (!pick.contains(e.target)) setel(false); });
        })();

        // ── Buka/tutup panel informasi ──
        // Di layar sempit panel ini menutupi hampir seluruh siteplan, jadi bawaannya
        // tertutup di HP dan terbuka di layar lebar. Di luar blok SITEPLAN supaya
        // tetap berfungsi walau siteplan belum diupload.
        (function () {
            const tombol = document.getElementById('info-toggle');
            const isi = document.getElementById('info-body');
            if (!tombol || !isi) return;
            const setel = (tampil) => {
                isi.classList.toggle('hidden', !tampil);
                tombol.textContent = tampil ? 'Sembunyikan Informasi' : 'Tampilkan Informasi';
                tombol.setAttribute('aria-expanded', tampil ? 'true' : 'false');
            };
            setel(window.matchMedia('(min-width: 769px)').matches);
            tombol.addEventListener('click', () => setel(isi.classList.contains('hidden')));
        })();
    </script>
</body>
</html>
