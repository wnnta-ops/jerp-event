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
    <div wire:ignore.self class="mb-4 rounded-xl p-4"
         style="background: var(--gray-50, rgb(249 250 251)); border: 1px solid var(--gray-200, rgb(229 231 235));"
         x-data="aktivasiScanState()" x-init="init()">
        <div class="flex items-center justify-between gap-3 mb-3">
            <div class="text-sm font-semibold">📷 Scan QR Customer / Barcode NUP</div>
            <button type="button" @click="toggle()" class="text-xs hover:underline" style="color:#60a5fa;">
                <span x-text="on ? 'Matikan kamera' : 'Hidupkan kamera'"></span>
            </button>
        </div>
        <div class="grid gap-3" style="grid-template-columns: minmax(0,240px) minmax(0,1fr);">
            <div wire:ignore id="an-scanner" style="aspect-ratio:4/3; border-radius:.6rem; overflow:hidden; background:#0f172a; border:1px solid rgba(255,255,255,.15);"></div>
            <div>
                <p class="text-xs text-gray-500 mb-2">Arahkan QR customer ke kamera — NUP langsung diaktivasi. Atau ketik manual:</p>
                <div class="flex gap-2">
                    <input type="text" wire:model="scanKode" @keyup.enter="$wire.aktivasiByScan()"
                           placeholder="Mis. NUP-00001 atau CUST:123"
                           class="flex-1 rounded-lg border px-3 py-2 text-sm" style="border-color: var(--gray-300);">
                    <button type="button" wire:click="aktivasiByScan" wire:loading.attr="disabled"
                            class="rounded-lg px-4 py-2 text-sm font-semibold" style="background:#059669; color:#fff;">
                        Aktivasi
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{ $this->table }}

    <script>
        function aktivasiScanState() {
            return {
                on: false, inst: null, lock: false,
                init() { this.$nextTick(() => this.start()); },
                async start() {
                    if (this.on || !window.Html5Qrcode) return;
                    try {
                        this.inst = new Html5Qrcode('an-scanner');
                        await this.inst.start({ facingMode: 'environment' }, { fps: 10, qrbox: 220 },
                            (teks) => this.onScan(teks), () => {});
                        this.on = true;
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
