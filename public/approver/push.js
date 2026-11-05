'use strict';

// Notifikasi push J-ERP Approver — langganan Web Push lewat service worker pwa_sw.js.
// Dipanggil dari lib/push/push_web.dart; semua hasil berupa string JSON supaya interop sederhana.
(function () {
  const kunciKeBytes = (b64) => {
    const pad = '='.repeat((4 - (b64.length % 4)) % 4);
    const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(raw, (c) => c.charCodeAt(0));
  };
  const didukung = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  const reg = async () => (didukung() ? (await navigator.serviceWorker.getRegistration()) || navigator.serviceWorker.ready : null);

  window.jerpPush = {
    async info() {
      const ios = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
      const terpasang = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
      return JSON.stringify({ didukung: didukung(), izin: didukung() ? Notification.permission : 'unsupported', ios, terpasang });
    },

    async langganan(publicKey) {
      try {
        if (!didukung()) return JSON.stringify({ error: 'unsupported' });
        const izin = await Notification.requestPermission();
        if (izin !== 'granted') return JSON.stringify({ error: izin });
        const r = await navigator.serviceWorker.ready;
        const kunci = kunciKeBytes(publicKey);
        let sub = await r.pushManager.getSubscription();
        // Kunci server berganti → langganan lama tidak berlaku, buat ulang.
        if (sub && sub.options && sub.options.applicationServerKey) {
          const lama = new Uint8Array(sub.options.applicationServerKey);
          if (lama.length !== kunci.length || lama.some((b, i) => b !== kunci[i])) {
            await sub.unsubscribe();
            sub = null;
          }
        }
        if (!sub) sub = await r.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: kunci });
        return JSON.stringify(sub.toJSON());
      } catch (e) {
        return JSON.stringify({ error: String(e && e.message || e) });
      }
    },

    async endpoint() {
      try {
        const r = await reg();
        const sub = r && await r.pushManager.getSubscription();
        return sub ? sub.endpoint : null;
      } catch (e) {
        return null;
      }
    },

    async batal() {
      try {
        const r = await reg();
        const sub = r && await r.pushManager.getSubscription();
        if (!sub) return null;
        const ep = sub.endpoint;
        await sub.unsubscribe();
        return ep;
      } catch (e) {
        return null;
      }
    },
  };
})();
