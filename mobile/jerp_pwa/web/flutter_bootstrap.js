{{flutter_js}}
{{flutter_build_config}}

// Tanpa serviceWorkerSettings: SW bawaan Flutter sudah deprecated (hanya
// unregister diri & reload). Offline ditangani pwa_sw.js (lihat index.html).
_flutter.loader.load();
