# Mobile — Approver App

Folder ini untuk aplikasi **mobile approver** (Expo / React Native) yang terhubung
ke J-ERP lewat **Mobile Approver API** (`/api/mobile/*`, auth Sanctum token).

## 🚀 Jalankan via Docker (satu klik)

Tak perlu install Node di server. Dari root repo:

```bash
docker compose -f docker-compose.mobile.yml up -d      # start (Node 20 + Expo)
docker logs -f jerp-mobile                              # lihat QR / URL exp://...
```

Scan QR dengan **Expo Go** di HP (Android: app Expo Go; iOS: Camera). Mode **tunnel**
(default) → HP connect dari jaringan mana pun, tak perlu buka firewall. API tetap
dipanggil HP langsung ke domain produksi (lihat `src/config.ts` → `API_BASE_URL`).

Stop: `docker compose -f docker-compose.mobile.yml down`.

> **Catatan**
> - Login pakai akun J-ERP (mis. `admin@jerp.test`). Hanya approve/reject + riwayat.
> - Build pertama lambat (`npm install` di dalam container, ~beberapa menit) — `node_modules`
>   disimpan di named volume jadi start berikutnya cepat.
> - Kalau **tunnel gagal** (ngrok), pakai mode **LAN**: di `docker-compose.mobile.yml`
>   set `REACT_NATIVE_PACKAGER_HOSTNAME` ke IP/domain server, ganti command ke
>   `npx expo start --port 8081`, dan buka **port 9091** di firewall. Lalu di Expo Go
>   buka `exp://<ip-server>:9091`.
> - Untuk produksi jangka panjang, sebaiknya **build APK** (EAS Build) lalu pasang di HP —
>   tak perlu Expo/dev-server jalan terus.

## Cara memindahkan `approval_flow` ke sini

1. Pindahkan **isi** folder `C:\Users\itptm\Documents\Project\Docker\approval_flow`
   ke dalam folder ini (`jerp/mobile/`). Hasil akhir kira-kira:
   ```
   jerp/mobile/
     App.tsx
     package.json
     src/...
     app.json
     ...
   ```
2. **Hapus `.git` bawaan approval_flow** setelah dipindah (`jerp/mobile/.git`)
   supaya tidak jadi repo bersarang / submodule tak sengaja. Riwayat app lama
   tetap aman di repo `approval_flow` asalnya.
3. Beri tahu saya kalau sudah dipindah — saya akan **rewire `src/data/mockService.ts`**
   agar memanggil API J-ERP (login token + endpoint approval). UI app tidak berubah.

## Endpoint API yang sudah disiapkan di J-ERP

Base URL dev: `http://127.0.0.1:8081/api/mobile`

| Method | Path | Fungsi |
|---|---|---|
| POST | `/auth/login` | email+password → `{ token, user }` |
| GET | `/auth/me` | profil approver |
| POST | `/auth/logout` | cabut token |
| GET | `/approvals` | queue `{ needs, inchain, done }` |
| GET | `/approvals/{id}` | satu approval |
| GET | `/approvals/{id}/docs` | dokumen pendukung (kosong dulu) |
| POST | `/approvals/{id}/decision` | `{ action: approved\|rejected, reason? }` |
| POST | `/approvals/{id}/review` | reviewer (no-op aman, belum dimodelkan) |
| POST | `/approvals/decisions` | bulk `{ ids[], action, reason? }` |
| GET | `/approvals/history` | riwayat keputusan |
| GET | `/approvals/signature` | spesimen TTD (kosong dulu) |

Semua kecuali `/auth/login` butuh header `Authorization: Bearer <token>`.
