<x-filament-panels::page>
    <div class="mb-4">
        <div class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 flex items-center gap-2">
            <a href="{{ url('/fo') }}" class="hover:underline" style="color:#60a5fa;">Dashboard FO</a>
            <span>›</span>
            <a href="{{ url('/fo/launching') }}" class="hover:underline" style="color:#60a5fa;">Launching</a>
            <span>›</span>
            <span>Aktivasi NUP</span>
        </div>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">
            Check-in customer di hari-H launching. Hanya NUP yang sudah <strong>APPROVED</strong> di Verifikasi Data yang tampil.
            Setelah <em>Aktivasi</em>, customer dapat lanjut ke <strong>Pilih Unit</strong>.
        </p>
    </div>

    {{-- ── Scan QR Customer / No. NUP → langsung aktivasi ── --}}
    {{-- SRI: berkas CDN diverifikasi hash-nya — bila unpkg dibajak, skrip ditolak browser. --}}
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"
            integrity="sha384-c9d8RFSL+u3exBOJ4Yp3HUJXS4znl9f+z66d1y54ig+ea249SpqR+w1wyvXz/lk+"
            crossorigin="anonymous"></script>
    {{-- Gaya ditulis sendiri (bukan kelas Tailwind grid-*) karena kelas grid tidak
         ter-compile di CSS panel Filament — pola yang sama dipakai halaman FO lain. --}}
    <style>
        .an-scan { max-width: 880px; margin-left: auto; margin-right: auto; border-radius: .9rem; overflow: hidden;
            background: #fff; border: 1px solid rgb(229 231 235); box-shadow: 0 1px 2px rgb(0 0 0 / .04); }
        .dark .an-scan { background: rgba(255,255,255,.03); border-color: rgba(255,255,255,.1); }

        .an-scan__head { display: flex; align-items: center; justify-content: space-between; gap: .75rem;
            padding: .7rem 1rem; border-bottom: 1px solid rgb(243 244 246); }
        .dark .an-scan__head { border-color: rgba(255,255,255,.08); }
        .an-scan__title { display: flex; align-items: center; gap: .5rem; font-size: .875rem; font-weight: 600; }
        .an-scan__dot { width: .5rem; height: .5rem; border-radius: 999px; background: #9ca3af; flex: 0 0 auto; }
        .an-scan__dot.on { background: #10b981; box-shadow: 0 0 0 3px rgb(16 185 129 / .18); }
        .an-scan__toggle { font-size: .75rem; font-weight: 600; color: #ea580c; padding: .3rem .6rem;
            border-radius: .5rem; border: 1px solid rgb(254 215 170); background: rgb(255 247 237); }
        .dark .an-scan__toggle { background: rgba(234,88,12,.12); border-color: rgba(234,88,12,.35); color: #fdba74; }
        .an-scan__toggle:hover { filter: brightness(.97); }

        .an-scan__body { display: grid; gap: 1rem; padding: 1rem; grid-template-columns: 240px minmax(0,1fr); }
        @media (max-width: 720px) { .an-scan__body { grid-template-columns: minmax(0,1fr); } }

        .an-scan__cam { position: relative; aspect-ratio: 4/3; border-radius: .7rem; overflow: hidden;
            background: #0b1220; border: 1px solid rgba(255,255,255,.12); }
        .an-scan__cam video, .an-scan__cam canvas { width: 100% !important; height: 100% !important; object-fit: cover; display: block; }
        .an-scan__camoff { position: absolute; inset: 0; display: grid; place-items: center; gap: .25rem;
            color: #94a3b8; font-size: .75rem; text-align: center; padding: .5rem; }

        .an-scan__ctl { display: flex; flex-direction: column; gap: .6rem; }
        .an-scan__label { font-size: .7rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; }
        .dark .an-scan__label { color: #94a3b8; }
        .an-scan__field { width: 100%; border-radius: .55rem; padding: .5rem .7rem; font-size: .8125rem;
            border: 1px solid rgb(209 213 219); background: #fff; color: #111827; }
        .dark .an-scan__field { background: rgba(255,255,255,.04); border-color: rgba(255,255,255,.15); color: #e5e7eb; }
        .an-scan__field:focus { outline: none; border-color: #ea580c; box-shadow: 0 0 0 3px rgb(234 88 12 / .15); }
        /* Select butuh penanganan khusus:
           1) Filament memasang gambar panah lewat background-image. Properti
              `background` (shorthand) di .an-scan__field mereset background-repeat
              jadi `repeat`, sehingga panahnya TER-UBIN berderet. Karena itu di sini
              appearance dimatikan & panah digambar sendiri sekali saja.
           2) Di tema gelap, daftar pilihan native memakai warna sistem sehingga
              teksnya gelap di atas putih (tak terbaca). color-scheme + warna
              option eksplisit membuatnya ikut gelap. */
        .an-scan__select { appearance: none; -webkit-appearance: none; -moz-appearance: none;
            padding-right: 2rem;
            background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20' stroke='%236b7280' stroke-width='1.75'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right .5rem center; background-size: 1.1rem; }
        .dark .an-scan__select { color-scheme: dark;
            background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20' stroke='%2394a3b8' stroke-width='1.75'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M6 8l4 4 4-4'/%3E%3C/svg%3E"); }
        .dark .an-scan__select option { background-color: #1e293b; color: #e5e7eb; }

        .an-scan__row { display: flex; gap: .5rem; }
        .an-scan__btn { white-space: nowrap; border-radius: .55rem; padding: .5rem 1.1rem; font-size: .8125rem;
            font-weight: 700; background: #059669; color: #fff; }
        .an-scan__btn:hover { background: #047857; }
        .an-scan__btn:disabled { opacity: .6; cursor: not-allowed; }
        .an-scan__hint { font-size: .75rem; color: #6b7280; line-height: 1.45; }
        .dark .an-scan__hint { color: #94a3b8; }
    </style>

    <div wire:ignore.self class="an-scan mb-5" x-data="aktivasiScanState()" x-init="init()">
        <div class="an-scan__head">
            <div class="an-scan__title">
                <span class="an-scan__dot" :class="on ? 'on' : ''"></span>
                Scan QR Customer / Barcode NUP
            </div>
            <button type="button" @click="toggle()" class="an-scan__toggle">
                <span x-text="on ? 'Matikan kamera' : 'Hidupkan kamera'"></span>
            </button>
        </div>

        <div class="an-scan__body">
            <div class="an-scan__cam">
                <div wire:ignore id="an-scanner" style="width:100%; height:100%;"></div>
                <div class="an-scan__camoff" x-show="!on" x-cloak>
                    <div>Kamera mati</div>
                </div>
            </div>

            <div class="an-scan__ctl">
                {{-- Pemilih kamera (webcam USB tambahan) — muncul bila kamera >1. --}}
                <div x-show="cameras.length > 1" x-cloak>
                    <div class="an-scan__label" style="margin-bottom:.25rem;">Kamera</div>
                    <select x-model="camId" @change="gantiKamera()" class="an-scan__field an-scan__select">
                        <option value="">Kamera bawaan</option>
                        <template x-for="c in cameras" :key="c.id">
                            <option :value="c.id" x-text="c.label"></option>
                        </template>
                    </select>
                </div>

                <div>
                    <div class="an-scan__label" style="margin-bottom:.25rem;">Atau ketik manual</div>
                    <div class="an-scan__row">
                        <input type="text" wire:model="scanKode" @keyup.enter="$wire.aktivasiByScan()"
                               placeholder="Mis. NUP-00001 atau CUST:123" class="an-scan__field">
                        <button type="button" wire:click="aktivasiByScan" wire:loading.attr="disabled" class="an-scan__btn">
                            Aktivasi
                        </button>
                    </div>
                </div>

                <p class="an-scan__hint">Arahkan QR customer ke kamera — NUP <strong>langsung diaktivasi</strong> tanpa perlu mencari barisnya di tabel.</p>
            </div>
        </div>
    </div>

    {{ $this->table }}

    <script>
        function aktivasiScanState() {
            return {
                on: false, inst: null, lock: false,
                // Pemilihan kamera (mendukung webcam USB tambahan); pilihan diingat
                // per komputer & dibagi dgn halaman Pilih Unit (kunci sama).
                cameras: [], camId: '',
                init() {
                    try { this.camId = localStorage.getItem('pu_cam_id') || ''; } catch (e) {}
                    this.$nextTick(() => this.start());
                },
                async muatKamera() {
                    if (!window.Html5Qrcode) return;
                    try {
                        const list = await Html5Qrcode.getCameras();
                        this.cameras = (list || []).map((c, i) => ({ id: c.id, label: c.label || ('Kamera ' + (i + 1)) }));
                        if (this.camId && !this.cameras.some(c => c.id === this.camId)) this.camId = '';
                    } catch (e) { /* izin belum diberikan */ }
                },
                async gantiKamera() {
                    try { localStorage.setItem('pu_cam_id', this.camId || ''); } catch (e) {}
                    await this.stop();
                    await this.start();
                },
                async start() {
                    if (this.on || !window.Html5Qrcode) return;
                    try {
                        this.inst = new Html5Qrcode('an-scanner');
                        // Kamera pilihan operator (mis. webcam USB); bila belum memilih,
                        // pakai kamera belakang seperti semula.
                        const sumber = this.camId ? { deviceId: { exact: this.camId } } : { facingMode: 'environment' };
                        await this.inst.start(sumber, { fps: 10, qrbox: 220 },
                            (teks) => this.onScan(teks), () => {});
                        this.on = true;
                        this.muatKamera();
                    } catch (e) { console.warn('Scanner Aktivasi NUP gagal start:', e); }
                },
                async stop() {
                    if (!this.on || !this.inst) return;
                    try { await this.inst.stop(); this.inst.clear(); } catch (e) {}
                    this.on = false;
                },
                async toggle() { this.on ? await this.stop() : await this.start(); },
                onScan(teks) {
                    // html5-qrcode memanggil callback tiap frame selama QR terlihat →
                    // tanpa kunci, satu QR memicu puluhan aktivasi beruntun.
                    const kode = (teks || '').trim();
                    if (!kode || this.lock) return;
                    this.lock = true;
                    this.$wire.set('scanKode', kode).then(() => this.$wire.aktivasiByScan());
                    setTimeout(() => { this.lock = false; }, 2500);
                },
            };
        }
    </script>
</x-filament-panels::page>
