# Mobile — Approver App

Folder ini untuk aplikasi **mobile approver** (Expo / React Native) yang terhubung
ke J-ERP lewat **Mobile Approver API** (`/api/mobile/*`, auth Sanctum token).

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
