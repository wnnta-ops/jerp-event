<x-filament-panels::page>
    {{ $this->form }}

    @php($payload = $this->getEditorPayload())

    @if (empty($payload))
        <div class="mt-4 p-4 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm">
            Pilih project untuk muat siteplan. Pastikan site plan sudah diupload di
            <a href="{{ url('/fo/upload-site-plan') }}" class="underline font-medium">Upload Site Plan</a>.
        </div>
    @else
        <style>
            .pe-grid {
                display: grid;
                grid-template-columns: 320px 1fr;
                gap: 12px;
                height: calc(100vh - 320px);
                min-height: 540px;
            }
            .pe-sidebar { overflow-y: auto; padding-right: 4px; }
            .pe-unit-row {
                display: flex; align-items: center; justify-content: space-between;
                padding: 8px 10px; border-radius: 6px; cursor: pointer; font-size: 13px;
                border: 1px solid transparent;
            }
            .pe-unit-row:hover { background: rgba(59,130,246,.08); }
            .pe-unit-row.active { background: rgba(59,130,246,.15); border-color: #3b82f6; }
            .pe-dot { width: 8px; height: 8px; border-radius: 99px; display: inline-block; margin-right: 6px; }
            .pe-dot-on  { background: #10b981; }
            .pe-dot-off { background: #cbd5e1; }
            #pe-map { width: 100%; height: 100%; background: #1e293b; border-radius: 8px; }
            .pe-toolbar { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; align-items: center; }
            .pe-pill { font-size: 11px; padding: 3px 8px; border-radius: 99px; background: #e2e8f0; color: #475569; }
            .pe-pill.green { background: #dcfce7; color: #15803d; }
            .pe-pill.amber { background: #fef3c7; color: #92400e; }
            .pe-pill.blue  { background: #dbeafe; color: #1e40af; }
            .pe-search { width: 100%; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; margin-bottom: 8px; }
            .leaflet-tooltip.pe-label { background: rgba(15,23,42,.85); color: #fff; border: 0; font-weight: 600; font-size: 11px; padding: 2px 6px; border-radius: 4px; }
            .leaflet-tooltip.pe-block-label { background: transparent; color: #fff; border: 0; box-shadow: none; font-weight: 800; font-size: 13px; letter-spacing: .5px; text-shadow: 0 1px 3px rgba(0,0,0,.95); }
        </style>

        {{-- Leaflet/Geoman loader + factory komponen Alpine. Dibungkus @assets agar
             Livewire menjalankannya SEKALI & lebih awal — termasuk saat navigasi SPA
             (script biasa TIDAK dieksekusi ulang saat navigate → koordinatEditor undefined
             → "units/activeUnitId is not defined" → peta hitam). --}}
        @assets
        <script>
            (function () {
                if (window.__leafletLoaderStarted) return;
                window.__leafletLoaderStarted = true;

                function addCss(href) {
                    var l = document.createElement('link');
                    l.rel = 'stylesheet'; l.href = href;
                    document.head.appendChild(l);
                }
                function addJs(src) {
                    return new Promise(function (resolve, reject) {
                        var s = document.createElement('script');
                        s.src = src; s.onload = resolve; s.onerror = reject;
                        document.head.appendChild(s);
                    });
                }

                function loadFrom(base) {
                    return addJs(base + 'leaflet@1.9.4/dist/leaflet.js')
                        .then(function () { return addJs(base + '@geoman-io/leaflet-geoman-free@2.16.0/dist/leaflet-geoman.min.js'); });
                }

                addCss('https://unpkg.com/leaflet@1.9.4/dist/leaflet.css');
                addCss('https://unpkg.com/@geoman-io/leaflet-geoman-free@2.16.0/dist/leaflet-geoman.css');

                // Coba unpkg → fallback jsdelivr bila gagal (mis. CDN diblokir di server/jaringan).
                loadFrom('https://unpkg.com/')
                    .catch(function () { return loadFrom('https://cdn.jsdelivr.net/npm/'); })
                    .then(function () {
                        window.__leafletReady = true;
                        document.dispatchEvent(new CustomEvent('leaflet-ready'));
                    })
                    .catch(function (err) {
                        console.error('Gagal load Leaflet/Geoman:', err);
                        var el = document.getElementById('pe-map');
                        if (el) {
                            el.innerHTML = '<div style="padding:24px;color:#fca5a5;font-size:13px;">Gagal memuat library peta (Leaflet) dari CDN. '
                                + 'Periksa koneksi internet / pemblokir CDN (unpkg & jsdelivr), lalu refresh.</div>';
                        }
                    });
            })();

            // Factory komponen sebagai GLOBAL: x-data="koordinatEditor(...)" selalu bisa
            // me-resolve-nya kapan pun Alpine memproses elemen — TIDAK bergantung timing
            // Alpine.data (penyebab "units is not defined" / peta hitam, terutama di
            // halaman Filament & navigasi SPA). Tetap didaftarkan sebagai Alpine.data juga.
            window.koordinatEditor = function (payload) {
                        return {
                            project: payload.project,
                            units: payload.units || [],
                            activeUnitId: null,
                            blocks: payload.blocks || [],
                            blockMode: false,
                            activeBlockId: null,
                            blockLayers: {},
                            search: '',
                            map: null,
                            polygons: {},
                            copySource: null,   // latlng "salinan siap" (untuk ditempel ke unit)
                            pendingLayer: null, // ghost polygon "salinan siap" di peta

                            get filteredUnits() {
                                if (! this.search) return this.units;
                                var q = this.search.toLowerCase();
                                return this.units.filter(function (u) {
                                    return ((u.kode || '').toLowerCase().indexOf(q) !== -1)
                                        || ((u.label || '').toLowerCase().indexOf(q) !== -1);
                                });
                            },

                            initMap() {
                                var self = this;
                                var go = function () {
                                    if (typeof L === 'undefined' || ! L.Map) return setTimeout(go, 50);
                                    self.buildMap();
                                };
                                if (window.__leafletReady) go();
                                else document.addEventListener('leaflet-ready', go, { once: true });
                            },

                            buildMap() {
                                var W = this.project.width, H = this.project.height;
                                var bounds = [[0, 0], [H, W]];
                                var el = document.getElementById('pe-map');
                                if (! el) return;

                                this.map = L.map(el, {
                                    crs: L.CRS.Simple,
                                    minZoom: -4, maxZoom: 4,
                                    zoomSnap: 0.25,
                                    attributionControl: false,
                                });
                                L.imageOverlay(this.project.imageUrl, bounds).addTo(this.map);
                                this.map.fitBounds(bounds);

                                // Banner bila gambar siteplan gagal dimuat (404 / storage tak terakses).
                                var imgUrl = this.project.imageUrl;
                                var probe = new Image();
                                probe.onerror = function () {
                                    var box = document.getElementById('pe-map');
                                    if (! box) return;
                                    box.style.position = 'relative';
                                    var w = document.createElement('div');
                                    w.style.cssText = 'position:absolute;top:8px;left:50px;z-index:1000;background:rgba(127,29,29,.92);color:#fff;padding:6px 10px;border-radius:6px;font-size:12px;max-width:80%;';
                                    w.textContent = 'Gambar siteplan tidak dapat dimuat. Periksa upload Site Plan / akses storage (URL: ' + imgUrl + ').';
                                    box.appendChild(w);
                                };
                                probe.src = imgUrl;

                                if (this.map.pm) {
                                    this.map.pm.addControls({
                                        position: 'topleft',
                                        drawMarker: false, drawCircle: false, drawCircleMarker: false,
                                        drawPolyline: false, drawRectangle: true, drawText: false,
                                        cutPolygon: false, rotateMode: false,
                                        drawPolygon: true,
                                    });
                                    var self = this;
                                    this.map.on('pm:create', function (e) { self.onPolygonCreated(e); });
                                }

                                this.renderExistingPolygons();
                                this.renderExistingBlocks();

                                // Label permanen bisa "melayang" saat zoom fraksional (zoomSnap) →
                                // pasang ulang ke pusat bounds (stabil) tiap selesai zoom.
                                var self2 = this;
                                this.map.on('zoomend', function () { self2.repositionLabels(); });
                            },

                            repositionLabels() {
                                var fix = function (p) {
                                    if (! p || ! p.getTooltip) return;
                                    var tt = p.getTooltip();
                                    if (tt && p.getBounds) { try { tt.setLatLng(p.getBounds().getCenter()); } catch (e) {} }
                                };
                                var self = this;
                                Object.keys(this.polygons).forEach(function (id) { fix(self.polygons[id]); });
                                Object.keys(this.blockLayers).forEach(function (id) { fix(self.blockLayers[id]); });
                            },

                            // Simpan ulang SEMUA posisi (unit + blok) dalam satu permintaan — tombol Simpan.
                            saveAll() {
                                var self = this;
                                var units = Object.keys(this.polygons).map(function (id) {
                                    return { id: parseInt(id), coords: self.polygons[id].getLatLngs()[0].map(function (ll) { return self.toImageXY(ll); }) };
                                });
                                var blocks = Object.keys(this.blockLayers).map(function (id) {
                                    var p = self.blockLayers[id];
                                    return { id: parseInt(id), coords: p.getLatLngs()[0].map(function (ll) { return self.toImageXY(ll); }), label: p._blockLabel || '' };
                                });
                                this.$wire.saveAll(units, blocks);
                            },

                            fitImage() {
                                if (this.map) this.map.fitBounds([[0,0], [this.project.height, this.project.width]]);
                            },

                            toLatLng(x, y)    { return [this.project.height - y, x]; },
                            toImageXY(latlng) { return [Math.round(latlng.lng), Math.round(this.project.height - latlng.lat)]; },

                            renderExistingPolygons() {
                                var self = this;
                                this.units.forEach(function (u) {
                                    if (Array.isArray(u.coords) && u.coords.length >= 3) {
                                        self.attachPolygon(u.id, u.coords.map(function (p) { return self.toLatLng(p[0], p[1]); }));
                                    }
                                });
                            },

                            attachPolygon(unitId, latlngs) {
                                var self = this;
                                var unit = this.units.find(function (u) { return u.id === unitId; });
                                var poly = L.polygon(latlngs, { color: '#3b82f6', weight: 2, fillOpacity: 0.35 }).addTo(this.map);
                                poly.bindTooltip((unit && unit.kode) || ('#' + unitId), {
                                    permanent: true, direction: 'center', className: 'pe-label',
                                });
                                poly.on('click', function (e) {
                                    if (e.originalEvent && e.originalEvent.altKey) return; // Alt = salin-seret, jangan ganti unit aktif
                                    self.selectUnit(unitId);
                                });
                                poly.on('mousedown', function (e) {
                                    if (! e.originalEvent || ! e.originalEvent.altKey) return;
                                    L.DomEvent.stop(e);
                                    self.startAltCopyDrag(poly, e.latlng);
                                });
                                if (poly.pm) {
                                    var syncTip = function () { var tt = poly.getTooltip(); if (tt) tt.setLatLng(poly.getBounds().getCenter()); };
                                    poly.on('pm:edit',    function () { self.persistFromLayer(unitId, poly); syncTip(); });
                                    poly.on('pm:drag',    syncTip);            // label unit ikut saat polygon digeser
                                    poly.on('pm:dragend', function () { self.persistFromLayer(unitId, poly); syncTip(); });
                                }
                                this.polygons[unitId] = poly;
                            },

                            persistFromLayer(unitId, layer) {
                                var self = this;
                                var ring = layer.getLatLngs()[0];
                                var coords = ring.map(function (ll) { return self.toImageXY(ll); });
                                var u = this.units.find(function (x) { return x.id === unitId; });
                                if (u) u.coords = coords;
                                this.$wire.saveCoords(unitId, coords);
                            },

                            onPolygonCreated(e) {
                                if (this.blockMode) { this.createBlockFromLayer(e.layer); return; }
                                var target = this.activeUnitId;
                                if (! target) {
                                    alert('Pilih unit dulu di sidebar sebelum gambar polygon (atau aktifkan Mode Blok).');
                                    this.map.removeLayer(e.layer);
                                    return;
                                }
                                if (this.polygons[target]) {
                                    this.map.removeLayer(this.polygons[target]);
                                    delete this.polygons[target];
                                }
                                var latlngs = e.layer.getLatLngs()[0];
                                this.map.removeLayer(e.layer);
                                this.attachPolygon(target, latlngs);
                                this.persistFromLayer(target, this.polygons[target]);
                                this.copySource = this.cloneRing(latlngs); // bentuk terakhir → siap ditempel
                            },

                            selectUnit(unitId) {
                                this.deselectBlock();
                                this.activeUnitId = unitId;
                                var poly = this.polygons[unitId];
                                if (poly && this.map) this.map.fitBounds(poly.getBounds().pad(0.5));
                            },

                            clearActive() {
                                if (! this.activeUnitId) return;
                                var id = this.activeUnitId;
                                if (this.polygons[id]) {
                                    this.map.removeLayer(this.polygons[id]);
                                    delete this.polygons[id];
                                }
                                var u = this.units.find(function (x) { return x.id === id; });
                                if (u) u.coords = null;
                                this.$wire.clearCoords(id);
                            },

                            cloneRing(latlngs) {
                                return latlngs.map(function (ll) { return { lat: ll.lat, lng: ll.lng }; });
                            },

                            // SALIN bentuk unit aktif → jadi "salinan siap" (ghost), digeser ke kanan
                            // agar tak menumpuk. Lalu klik unit tujuan di sidebar untuk menempel.
                            copyShape() {
                                var poly = this.activeUnitId ? this.polygons[this.activeUnitId] : null;
                                if (! poly) { alert('Pilih unit yang sudah punya polygon, lalu klik Salin Bentuk.'); return; }
                                var ring = poly.getLatLngs()[0];
                                var lngs = ring.map(function (ll) { return ll.lng; });
                                var dx = (Math.max.apply(null, lngs) - Math.min.apply(null, lngs)) * 1.1 || 20;
                                this.setPending(ring.map(function (ll) { return [ll.lat, ll.lng + dx]; }));
                            },

                            // Tampilkan "salinan siap" (ghost dashed amber) + simpan latlng-nya.
                            setPending(latlngs) {
                                if (this.pendingLayer) { this.map.removeLayer(this.pendingLayer); }
                                this.pendingLayer = L.polygon(latlngs, { color: '#f59e0b', weight: 2, dashArray: '6,4', fillOpacity: 0.25 }).addTo(this.map);
                                this.copySource = this.cloneRing(this.pendingLayer.getLatLngs()[0]);
                            },

                            cancelPaste() {
                                if (this.pendingLayer) { this.map.removeLayer(this.pendingLayer); this.pendingLayer = null; }
                                this.copySource = null;
                            },

                            // TEMPEL "salinan siap" ke unit (dipanggil saat klik unit bila ada salinan).
                            pasteTo(unitId) {
                                if (! this.copySource) { this.selectUnit(unitId); return; }
                                var latlngs = this.copySource.map(function (p) { return [p.lat, p.lng]; });
                                if (this.polygons[unitId]) { this.map.removeLayer(this.polygons[unitId]); delete this.polygons[unitId]; }
                                this.attachPolygon(unitId, latlngs);
                                this.persistFromLayer(unitId, this.polygons[unitId]);
                                this.cancelPaste();          // bersihkan ghost; TAK auto-drag → kursor normal
                                this.activeUnitId = unitId;
                            },

                            // Alt + seret sebuah polygon → "salinan siap" mengambang di posisi jatuh;
                            // lalu klik unit tujuan untuk menempel.
                            startAltCopyDrag(sourcePoly, startLatLng) {
                                var self = this;
                                var srcRing = sourcePoly.getLatLngs()[0].map(function (ll) { return L.latLng(ll.lat, ll.lng); });
                                if (this.pendingLayer) { this.map.removeLayer(this.pendingLayer); }
                                var ghost = L.polygon(srcRing.map(function (ll) { return [ll.lat, ll.lng]; }), { color: '#f59e0b', weight: 2, dashArray: '6,4', fillOpacity: 0.25 }).addTo(this.map);
                                this.pendingLayer = ghost;
                                var origin = startLatLng;
                                this.map.dragging.disable();
                                var onMove = function (ev) {
                                    var dLat = ev.latlng.lat - origin.lat, dLng = ev.latlng.lng - origin.lng;
                                    ghost.setLatLngs(srcRing.map(function (ll) { return [ll.lat + dLat, ll.lng + dLng]; }));
                                };
                                var onUp = function () {
                                    self.map.off('mousemove', onMove);
                                    self.map.off('mouseup', onUp);
                                    self.map.dragging.enable();
                                    self.copySource = self.cloneRing(ghost.getLatLngs()[0]); // siap ditempel ke unit
                                };
                                this.map.on('mousemove', onMove);
                                this.map.on('mouseup', onUp);
                            },

                            // ── Blok area (penanda tahap, TIDAK terikat unit) ──
                            renderExistingBlocks() {
                                var self = this;
                                (this.blocks || []).forEach(function (b) {
                                    if (Array.isArray(b.coords) && b.coords.length >= 3) self.attachBlock(b);
                                });
                            },

                            toggleBlockMode() {
                                this.blockMode = ! this.blockMode;
                                if (! this.blockMode) this.deselectBlock();
                                else { this.activeUnitId = null; }
                            },

                            attachBlock(block) {
                                var self = this;
                                var latlngs = block.coords.map(function (p) { return self.toLatLng(p[0], p[1]); });
                                var poly = L.polygon(latlngs, {
                                    color: '#000', weight: 1,
                                    fillColor: block.color || '#0a0a0a',
                                    fillOpacity: block.opacity != null ? block.opacity : 0.85,
                                }).addTo(this.map);
                                poly._blockId = block.id;
                                poly._blockLabel = block.label || '';
                                if (poly._blockLabel) {
                                    poly.bindTooltip(poly._blockLabel, { permanent: true, direction: 'center', className: 'pe-block-label' });
                                }
                                poly.on('click', function () { self.selectBlock(block.id); });
                                if (poly.pm) {
                                    var sync = function () { var tt = poly.getTooltip(); if (tt) tt.setLatLng(poly.getBounds().getCenter()); };
                                    poly.on('pm:edit',    function () { self.persistBlock(poly); sync(); });
                                    poly.on('pm:drag',    sync);
                                    poly.on('pm:dragend', function () { self.persistBlock(poly); sync(); });
                                }
                                this.blockLayers[block.id] = poly;
                            },

                            persistBlock(poly) {
                                var self = this;
                                var coords = poly.getLatLngs()[0].map(function (ll) { return self.toImageXY(ll); });
                                this.$wire.saveBlock(poly._blockId, coords, poly._blockLabel);
                            },

                            createBlockFromLayer(layer) {
                                var self = this;
                                var latlngs = layer.getLatLngs()[0];
                                this.map.removeLayer(layer);
                                var label = (window.prompt('Teks blok (mis. TAHAP 2):', 'TAHAP 2') || '').trim();
                                var coords = latlngs.map(function (ll) { return self.toImageXY(ll); });
                                this.$wire.saveBlock(null, coords, label).then(function (id) {
                                    if (id) {
                                        self.attachBlock({ id: id, coords: coords, label: label, color: '#0a0a0a', opacity: 0.85 });
                                        self.selectBlock(id);   // langsung bisa digeser/diubah ukuran
                                    }
                                });
                            },

                            // Klik blok → pilih: aktifkan edit (seret untuk geser, tarik titik untuk perbesar/perkecil).
                            selectBlock(id) {
                                if (this.activeBlockId && this.activeBlockId !== id) this.deselectBlock();
                                this.activeUnitId = null;
                                this.activeBlockId = id;
                                var poly = this.blockLayers[id];
                                if (poly && poly.pm) poly.pm.enable({ allowSelfIntersection: false, draggable: true });
                            },

                            deselectBlock() {
                                if (this.activeBlockId) {
                                    var poly = this.blockLayers[this.activeBlockId];
                                    if (poly && poly.pm && poly.pm.disable) poly.pm.disable();
                                }
                                this.activeBlockId = null;
                            },

                            renameBlock() {
                                if (! this.activeBlockId) return;
                                var poly = this.blockLayers[this.activeBlockId];
                                var t = (window.prompt('Teks blok:', poly._blockLabel || '') || '').trim();
                                poly._blockLabel = t;
                                poly.unbindTooltip();
                                if (t) poly.bindTooltip(t, { permanent: true, direction: 'center', className: 'pe-block-label' });
                                this.persistBlock(poly);
                            },

                            removeBlock() {
                                if (! this.activeBlockId) return;
                                var id = this.activeBlockId;
                                if (this.blockLayers[id]) { this.map.removeLayer(this.blockLayers[id]); delete this.blockLayers[id]; }
                                this.$wire.deleteBlock(id);
                                this.activeBlockId = null;
                            },
                        };
            };

            // Daftarkan juga sebagai Alpine.data (bila Alpine memilih versi terdaftar).
            (function () {
                function reg() {
                    if (window.Alpine && ! window.Alpine.__koordinatEditorReg) {
                        window.Alpine.data('koordinatEditor', window.koordinatEditor);
                        window.Alpine.__koordinatEditorReg = true;
                    }
                }
                if (window.Alpine) reg();
                document.addEventListener('alpine:init', reg);
            })();
        </script>
        @endassets

        <div class="mt-4"
             x-data="koordinatEditor({{ \Illuminate\Support\Js::from($payload) }})"
             x-init="initMap()"
             wire:ignore>
            <div class="pe-toolbar">
                <span class="pe-pill blue"  x-text="'Total: ' + units.length + ' unit'"></span>
                <span class="pe-pill green" x-text="'Ada koordinat: ' + units.filter(u => u.coords).length"></span>
                <span class="pe-pill amber" x-text="'Belum: ' + units.filter(u => ! u.coords).length"></span>
                <span class="pe-pill" x-show="activeUnitId"
                      x-text="'Aktif: ' + ((units.find(u => u.id === activeUnitId) || {}).kode || '-')"></span>
                <button type="button"
                        class="text-xs px-3 py-1 rounded bg-emerald-600 text-white hover:bg-emerald-700 font-semibold"
                        @click="saveAll()">💾 Simpan</button>
                <button type="button"
                        class="text-xs px-3 py-1 rounded bg-blue-600 text-white hover:bg-blue-700"
                        @click="fitImage()">Reset View</button>
                <button type="button"
                        class="text-xs px-3 py-1 rounded bg-indigo-600 text-white hover:bg-indigo-700"
                        x-show="activeUnitId"
                        @click="copyShape()">Salin Bentuk</button>
                <span class="pe-pill amber" x-show="copySource" x-text="'Salinan siap — klik unit tujuan untuk menempel'"></span>
                <button type="button"
                        class="text-xs px-3 py-1 rounded bg-gray-500 text-white hover:bg-gray-600"
                        x-show="copySource"
                        @click="cancelPaste()">Batal Salin</button>
                <button type="button"
                        class="text-xs px-3 py-1 rounded bg-rose-600 text-white hover:bg-rose-700"
                        x-show="activeUnitId"
                        @click="clearActive()">Hapus Polygon Aktif</button>

                <span style="width:1px;height:18px;background:#cbd5e1;display:inline-block;"></span>
                <button type="button"
                        class="text-xs px-3 py-1 rounded"
                        :class="blockMode ? 'bg-gray-900 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
                        @click="toggleBlockMode()"
                        x-text="blockMode ? '■ Mode Blok: ON — gambar = blok' : '■ Mode Blok'"></button>
                <button type="button" class="text-xs px-3 py-1 rounded bg-amber-600 text-white hover:bg-amber-700"
                        x-show="activeBlockId" @click="renameBlock()">Ubah Teks Blok</button>
                <button type="button" class="text-xs px-3 py-1 rounded bg-rose-700 text-white hover:bg-rose-800"
                        x-show="activeBlockId" @click="removeBlock()">Hapus Blok</button>
                <button type="button" class="text-xs px-3 py-1 rounded bg-gray-500 text-white hover:bg-gray-600"
                        x-show="activeBlockId" @click="deselectBlock()">Selesai Edit Blok</button>
            </div>

            <div class="pe-grid">
                <div class="pe-sidebar">
                    <input class="pe-search" placeholder="Cari blok / unit…" x-model="search">
                    <div class="text-xs text-gray-500 mb-2 px-1">
                        Klik unit → gambar <b>polygon</b> atau <b>kotak</b> (toolbar kiri-atas). Unit serupa, cara cepat: <b>Alt + seret</b> bentuk yang ada (atau <b>Salin Bentuk</b>) → muncul <b>salinan</b> → <b>klik unit tujuan</b> untuk menempel. <b>Mode Blok</b>: gambar area hitam penanda tahap (mis. TAHAP 2), klik blok untuk geser/ubah ukuran/ubah teks. Auto-save.
                    </div>
                    <template x-for="u in filteredUnits" :key="u.id">
                        <div class="pe-unit-row"
                             :class="{ 'active': activeUnitId === u.id }"
                             @click="copySource ? pasteTo(u.id) : selectUnit(u.id)">
                            <span>
                                <span class="pe-dot" :class="u.coords ? 'pe-dot-on' : 'pe-dot-off'"></span>
                                <span x-text="u.kode"></span>
                                <span class="text-gray-400" x-show="u.tahap" x-text="' (' + u.tahap + ')'"></span>
                            </span>
                            <span class="pe-pill" x-show="u.status" x-text="u.status"></span>
                        </div>
                    </template>
                </div>
                <div>
                    <div id="pe-map"></div>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
