import axios from 'axios';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Laravel Echo + Pusher protocol (used by Reverb).
// Configures only when the required env vars are present (REVERB_APP_KEY etc).
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

if (import.meta.env.VITE_REVERB_APP_KEY) {
    // Host Reverb SELALU mengikuti domain halaman → satu bundle jalan di lokal
    // (127.0.0.1) maupun domain produksi tanpa rebuild per-env, dan tak nyangkut ke
    // "localhost" (di server = komputer pengunjung → real-time gagal).
    //   https → wss:443 (Reverb di-proxy reverse-proxy lewat domain)
    //   http (dev) → port Reverb dev (VITE_REVERB_PORT, mis. 8088)
    const isHttps = window.location.protocol === 'https:';
    const devPort = Number(import.meta.env.VITE_REVERB_PORT ?? 8088);
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: window.location.hostname,
        wsPort: isHttps ? 443 : devPort,
        wssPort: isHttps ? 443 : devPort,
        forceTLS: isHttps,
        enabledTransports: ['ws', 'wss'],
    });
}
