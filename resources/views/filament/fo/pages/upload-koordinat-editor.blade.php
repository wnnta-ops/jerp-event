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
        </style>

        {{-- Dynamic Leaflet + Geoman loader. Bypass @push/@stack issues di Filament Page. --}}
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

            (function () {
                if (window.__koordinatEditorRegistered) return;
                window.__koordinatEditorRegistered = true;

                function register() {
                    window.Alpine.data('koordinatEditor', function (payload) {
                        return {
                            project: payload.project,
                            units: payload.units || [],
                            activeUnitId: null,
                            search: '',
                            map: null,
                            polygons: {},

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
                                        drawPolyline: false, drawRectangle: false, drawText: false,
                                        cutPolygon: false, rotateMode: false,
                                        drawPolygon: true,
                                    });
                                    var self = this;
                                    this.map.on('pm:create', function (e) { self.onPolygonCreated(e); });
                                }

                                this.renderExistingPolygons();
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
                                poly.on('click', function () { self.selectUnit(unitId); });
                                if (poly.pm) {
                                    poly.on('pm:edit',    function () { self.persistFromLayer(unitId, poly); });
                                    poly.on('pm:dragend', function () { self.persistFromLayer(unitId, poly); });
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
                                var target = this.activeUnitId;
                                if (! target) {
                                    alert('Pilih unit dulu di sidebar sebelum gambar polygon.');
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
                            },

                            selectUnit(unitId) {
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
                        };
                    });
                }

                // Register SEBELUM Alpine memproses x-data (event alpine:init), agar
                // komponen koordinatEditor sudah ada saat elemen di-init — mencegah
                // "units/search/activeUnitId is not defined" (scope kosong → map hitam).
                if (window.Alpine) register();
                else document.addEventListener('alpine:init', register);
            })();
        </script>

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
                        class="text-xs px-3 py-1 rounded bg-blue-600 text-white hover:bg-blue-700"
                        @click="fitImage()">Reset View</button>
                <button type="button"
                        class="text-xs px-3 py-1 rounded bg-rose-600 text-white hover:bg-rose-700"
                        x-show="activeUnitId"
                        @click="clearActive()">Hapus Polygon Aktif</button>
            </div>

            <div class="pe-grid">
                <div class="pe-sidebar">
                    <input class="pe-search" placeholder="Cari blok / unit…" x-model="search">
                    <div class="text-xs text-gray-500 mb-2 px-1">
                        Klik unit di sidebar lalu gambar polygon di siteplan (toolbar kiri-atas → ikon polygon). Auto-save saat selesai.
                    </div>
                    <template x-for="u in filteredUnits" :key="u.id">
                        <div class="pe-unit-row"
                             :class="{ 'active': activeUnitId === u.id }"
                             @click="selectUnit(u.id)">
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
