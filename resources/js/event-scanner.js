// Scanner HP modul Event (/event/scan). Kamera via html5-qrcode; logika scan di server.
import { Html5Qrcode, Html5QrcodeSupportedFormats } from 'html5-qrcode';

const KUNCI_TITIK = 'jerp-event-titik';

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

document.addEventListener('alpine:init', () => {
    window.Alpine.data('eventScanner', (scanPointId, undoSeconds) => ({
        fase: 'kamera',          // kamera | hasil | pilih
        hasil: null,
        payloadPilih: null,
        manual: '',
        sibuk: false,
        kameraGagal: null,
        undo: null,              // { id, sampai }
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
                this.tampil(await this.$wire.scan(payload, nupId), payload);
            } catch (e) {
                this.tampil({ status: 'rejected', message: 'Gagal terhubung ke server' }, payload);
            } finally {
                this.sibuk = false;
            }
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
