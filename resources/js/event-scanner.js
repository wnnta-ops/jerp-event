// Scanner HP modul Event (/event/scan). Kamera via html5-qrcode; logika scan di server.
import { Html5Qrcode, Html5QrcodeSupportedFormats } from 'html5-qrcode';

const KUNCI_TITIK = 'jerp-event-titik';
const KUNCI_CARA = 'jerp-event-cara-selesai';
const CARA_SELESAI = ['geser', 'tahan', 'konfirmasi', 'scan'];

function bacaCara() {
    try { const c = localStorage.getItem(KUNCI_CARA); return CARA_SELESAI.includes(c) ? c : 'geser'; } catch (_) { return 'geser'; }
}

function bunyi(ok) {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.frequency.value = ok ? 880 : 220;
        gain.gain.value = 0.15;
        osc.connect(gain).connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + (ok ? 0.12 : 0.4));
    } catch (_) { /* tanpa suara pun tetap jalan */ }
}

// Sesi kedaluwarsa (419) saat poll/scan → muat ulang (ke halaman login bila perlu),
// bukan menampilkan halaman error di atas scanner.
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('request', ({ fail }) => {
        fail(({ status, preventDefault }) => {
            if (status === 419) { preventDefault(); window.location.reload(); }
        });
    });
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('eventScanner', (scanPointId, undoSeconds) => ({
        fase: 'kamera',          // kamera | hasil | pilih
        hasil: null,
        payloadPilih: null,
        manual: '',
        sibuk: false,
        kameraGagal: null,
        undo: null,              // { id, sampai }
        cara: bacaCara(),        // cara menandai customer selesai — diingat per HP
        setelan: false,
        sekarang: Date.now(),
        qr: null,
        tick: null,
        kembali: null,

        init() {
            try { localStorage.setItem(KUNCI_TITIK, String(scanPointId)); } catch (_) {}
            this.tick = setInterval(() => { this.sekarang = Date.now(); }, 1000);
            this.$nextTick(() => this.mulaiKamera());
        },

        destroy() {
            clearInterval(this.tick);
            clearTimeout(this.kembali);
            if (this.qr) { this.qr.stop().catch(() => {}); }
        },

        async mulaiKamera() {
            const el = document.getElementById('qr-reader');
            if (!el) return;
            try {
                this.qr = new Html5Qrcode('qr-reader', { formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE], verbose: false });
                await this.qr.start(
                    { facingMode: 'environment' },
                    { fps: 10, qrbox: (w, h) => { const s = Math.floor(Math.min(w, h) * 0.7); return { width: s, height: s }; } },
                    (teks) => this.kirim(teks),
                    () => {},
                );
            } catch (e) {
                this.kameraGagal = 'Kamera tidak bisa dibuka (' + (e?.message || e) + '). Pastikan izin kamera & alamat https. Ketik No. NUP di bawah.';
            }
        },

        jeda() { try { this.qr?.pause(true); } catch (_) {} },
        lanjut() { try { this.qr?.resume(); } catch (_) {} },

        async kirim(payload, nupId = null) {
            payload = String(payload || '').trim();
            if (!payload || this.sibuk || (this.fase === 'hasil')) return;
            this.sibuk = true;
            this.jeda();
            try {
                this.tampil(await this.$wire.scan(payload, nupId, this.cara === 'scan'), payload);
            } catch (e) {
                this.tampil({ status: 'rejected', message: 'Gagal terhubung ke server' }, payload);
            } finally {
                this.sibuk = false;
            }
        },

        pilihCara(c) {
            this.cara = CARA_SELESAI.includes(c) ? c : 'geser';
            try { localStorage.setItem(KUNCI_CARA, this.cara); } catch (_) {}
            this.setelan = false;
        },

        kirimManual() {
            const v = this.manual;
            this.manual = '';
            this.kirim(v);
        },

        async pilih(nupId) {
            const p = this.payloadPilih;
            this.fase = 'kamera';
            await this.kirim(p, nupId);
        },

        async selesai(participantId) {
            if (this.sibuk) return;
            this.sibuk = true;
            this.jeda();
            try { this.tampil(await this.$wire.selesai(participantId)); } finally { this.sibuk = false; }
        },

        async batalkan() {
            if (!this.undo || this.sibuk) return;
            this.sibuk = true;
            const id = this.undo.id;
            this.undo = null;
            this.jeda();
            try { this.tampil(await this.$wire.undo(id)); } finally { this.sibuk = false; }
        },

        tampil(res, payload = null) {
            clearTimeout(this.kembali);
            if (res.status === 'pick') {
                this.payloadPilih = payload;
                this.hasil = res;
                this.fase = 'pilih';
                navigator.vibrate?.([60, 60, 60]);
                return;
            }
            this.hasil = res;
            this.fase = 'hasil';
            const ok = res.status === 'accepted';
            navigator.vibrate?.(ok ? 80 : [200, 100, 200]);
            bunyi(ok || res.status === 'duplicate');
            if (ok && res.log_id && res.action !== 'undo') {
                this.undo = { id: res.log_id, sampai: Date.now() + undoSeconds * 1000 };
            }
            this.kembali = setTimeout(() => this.tutupHasil(), ok ? 1500 : 2500);
        },

        tutupHasil() {
            clearTimeout(this.kembali);
            this.fase = 'kamera';
            this.hasil = null;
            this.lanjut();
        },

        batalPilih() {
            this.fase = 'kamera';
            this.hasil = null;
            this.lanjut();
        },

        get sisaUndo() {
            if (!this.undo) return 0;
            const s = Math.ceil((this.undo.sampai - this.sekarang) / 1000);
            if (s <= 0) { this.undo = null; return 0; }
            return s;
        },

        lama(iso) {
            if (!iso) return '—';
            const d = Math.max(0, Math.floor((this.sekarang - new Date(iso).getTime()) / 1000));
            const j = Math.floor(d / 3600), m = Math.floor((d % 3600) / 60), s = d % 60;
            const mm = String(m).padStart(2, '0'), ss = String(s).padStart(2, '0');
            return j > 0 ? `${j}:${mm}:${ss}` : `${mm}:${ss}`;
        },

        warnaHasil() {
            const s = this.hasil?.status;
            if (s === 'accepted') return this.hasil.warning ? 'bg-amber-500' : 'bg-emerald-600';
            if (s === 'duplicate') return 'bg-sky-600';
            return 'bg-rose-600';
        },
    }));

    // Slider "Geser untuk selesai" milik petugas: lepas di >= 85% → tandai selesai.
    // Dipasang di dalam eventScanner, jadi selesai() milik induknya bisa dipanggil langsung.
    window.Alpine.data('geserSelesai', (participantId) => ({
        x: 0,
        maks: 1,
        asal: 0,
        geser: false,
        selesaiKah: false,

        get persen() { return this.maks > 0 ? Math.min(1, this.x / this.maks) : 0; },

        ukur() { this.maks = Math.max(1, this.$refs.track.clientWidth - this.$refs.knob.offsetWidth - 4); },

        mulai(e) {
            if (this.selesaiKah) return;
            this.ukur();
            this.geser = true;
            this.asal = e.clientX - this.x;
            this.$refs.track.classList.remove('balik');
            this.$refs.knob.setPointerCapture(e.pointerId);
            const gerak = (ev) => { if (this.geser) this.x = Math.max(0, Math.min(this.maks, ev.clientX - this.asal)); };
            const lepas = () => {
                this.geser = false;
                this.$refs.knob.removeEventListener('pointermove', gerak);
                this.$refs.knob.removeEventListener('pointerup', lepas);
                this.$refs.knob.removeEventListener('pointercancel', lepas);
                if (this.persen >= 0.85) { this.konfirmasi(); } else { this.$refs.track.classList.add('balik'); this.x = 0; }
            };
            this.$refs.knob.addEventListener('pointermove', gerak);
            this.$refs.knob.addEventListener('pointerup', lepas);
            this.$refs.knob.addEventListener('pointercancel', lepas);
        },

        async konfirmasi() {
            this.ukur();
            this.x = this.maks;
            this.selesaiKah = true;
            await this.selesai(participantId);
        },
    }));

    // Tombol "Tahan untuk selesai": tekan & tahan 1,5 detik; lepas lebih cepat = batal.
    window.Alpine.data('tahanSelesai', (participantId, durasi = 1500) => ({
        p: 0,
        raf: null,
        mulaiPada: 0,
        selesaiKah: false,

        mulai(e) {
            if (this.selesaiKah) return;
            e?.target?.setPointerCapture?.(e.pointerId);
            navigator.vibrate?.(15);
            this.mulaiPada = performance.now();
            clearInterval(this.raf);
            // Timer biasa (bukan requestAnimationFrame) supaya tetap jalan walau layar tak
            // menggambar ulang; ~60 langkah per detik cukup halus untuk bilah progres.
            this.raf = setInterval(() => {
                this.p = Math.min(1, (performance.now() - this.mulaiPada) / durasi);
                if (this.p >= 1) { this.konfirmasi(); }
            }, 16);
        },

        batal() {
            if (this.selesaiKah) return;
            clearInterval(this.raf);
            this.p = 0;
        },

        async konfirmasi() {
            clearInterval(this.raf);
            this.p = 1;
            this.selesaiKah = true;
            await this.selesai(participantId);
        },
    }));

    // Pulihkan titik scan terakhir di HP ini.
    window.Alpine.data('eventScannerPemulih', () => ({
        async init() {
            let id = null;
            try { id = localStorage.getItem(KUNCI_TITIK); } catch (_) {}
            if (id) {
                const ok = await this.$wire.pilihTitik(Number(id));
                if (!ok) { try { localStorage.removeItem(KUNCI_TITIK); } catch (_) {} }
            }
        },
    }));
});

export function lupakanTitik() {
    try { localStorage.removeItem(KUNCI_TITIK); } catch (_) {}
}
window.jerpLupakanTitikEvent = lupakanTitik;
