# J-ERP Approver — Flutter PWA

Aplikasi approver (Flutter Web, bisa di-install sebagai PWA) untuk **BO Approval Inbox**:
Memo Urgent, TTD Berkas, Invoice Hutang, Alokasi & Koreksi Budget. Rencananya menggantikan
app Expo di `../approval_flow`.

## Jalankan lokal

Butuh Flutter SDK (`brew install --cask flutter`, cek dengan `flutter doctor` — cukup baris *Chrome* ✓).

```bash
flutter run -d chrome                     # mode dev, hot reload
flutter build web --release               # hasil di build/web
php -S 127.0.0.1:8083 -t build/web        # serve hasil build → http://localhost:8083
```

Di Claude desktop: preview server `jerp-pwa` (port 8083) di `../../../.claude/launch.json`.

Base URL API (default `http://localhost:8000/api/mobile`):

```bash
flutter build web --release --dart-define=API_BASE_URL=https://domain-fo/api/mobile
```

## Install sebagai PWA

PWA (service worker + tombol install) hanya jalan di **HTTPS** atau **localhost**.

- **Laptop (Chrome/Edge)**: buka `http://localhost:8083` → ikon *Install* di address bar.
- **HP**: harus lewat HTTPS, mis. Cloudflare tunnel ke port 8083, lalu
  - Android Chrome: menu ⋮ → *Install app* / *Add to Home screen*.
  - iPhone Safari: tombol Share → *Add to Home Screen*.

## Struktur

- `lib/api/api_client.dart` — HTTP ke `/api/mobile` (Bearer token, pesan error Laravel → teks Indonesia).
- `lib/auth/session.dart` — login/logout/pulihkan sesi; token disimpan di localStorage (`jerp_token`).
- `lib/pages/` — `login_page.dart`, `inbox_page.dart`.
- `flutter test` — widget test pakai `MockClient` (tidak butuh server).

- `lib/inbox/` — model, `InboxStore` (dipakai Home/Approval/History), kartu.
- `lib/pages/shell_page.dart` — 4 tab: Home, Approval, History, Profil.

Login memakai `POST /auth/login` FO: approver FO, atau user yang punya tahap di Approval Chain
BO Approval Inbox (`BoChainApproverCheck`).

Data inbox: PWA → FO `/api/mobile/inbox`, `/inbox/history`, `/inbox/{id}`, `/inbox/{id}/act`
→ FO `BoInboxBridge` (HMAC `SSO_SHARED_SECRET`) → BO `/api/v1/fo-bridge/approval-inbox/*`
(`VerifyFoBridge` + `ApprovalInboxBridgeController`, memakai `ApprovalInboxQuery` & `ActOnApproval`).
Urgent = Memo Internal jenis urgent; sisanya Reguler. Uji BO: `php tests/Manual/uji-fo-bridge-inbox.php`.

Dashboard (tab Home): PWA → FO `/api/mobile/dashboard` → BO `/api/v1/fo-bridge/dashboard`
(`DashboardBridgeController` memanggil `DashboardController` BO dan mengambil props Inertia-nya).
Tampil hanya bila user punya izin BO `dashboard.{kpr,cash,accounting,project}.view`; grafik
digambar generik di `lib/dashboard/kartu_grafik.dart` (judul mengikuti Pages/Dashboard/Index.vue).

Kunci aplikasi (Profil › Pengaturan, opsional, bawaan mati): `lib/kunci/` — PIN 6 digit (hash SHA-256
10.000x + garam di localStorage), biometrik opsional lewat passkey platform (`web/kunci.js`, verifikasi
lokal tanpa server). Terkunci saat app dibuka & setelah > 5 menit di latar belakang; salah 5x / "Lupa PIN"
= keluar akun; dihapus saat logout. Ini penghalang di HP, bukan enkripsi token.

Notifikasi push (Profil › Pengaturan › Notifikasi push, per perangkat): `lib/push/` + `web/push.js`,
handler `push`/`notificationclick` di `web/pwa_sw.js` (ketuk → `?buka=ID` → detail). Server: FO
`PushController` (`/api/mobile/push/*`), job `php artisan pwa:push` tiap menit (`PushApprover`: giliran
saya, ajuan saya diputuskan via BO `/approval-inbox/pengajuan`, pengingat harian), tabel `fo_push_*`,
kunci VAPID `php artisan pwa:vapid` (sekali per lingkungan). Langganan terikat ke token login — logout /
perangkat dikeluarkan = berhenti. Lokal: preview `jerp-push` (loop pwa:push; jangan `schedule:work`,
itu ikut menjalankan collection:run dll). iPhone: hanya setelah Add to Home Screen (iOS 16.4+).

## Catatan teknis

- `web/pwa_sw.js` = service worker sendiri (network-first, request `/api/` tidak di-cache).
  SW bawaan Flutter sudah deprecated (cuma unregister diri), jadi `web/flutter_bootstrap.js`
  sengaja memanggil `_flutter.loader.load()` tanpa `serviceWorkerSettings`.
  Naikkan `CACHE` di `pwa_sw.js` kalau daftar file shell berubah.
- Ikon di `web/icons/` masih placeholder (oranye `#f97316`, huruf J + centang) — ganti
  dengan logo resmi kalau sudah ada.
