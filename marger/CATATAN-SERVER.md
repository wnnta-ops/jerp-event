# Catatan Menjalankan di Server — FO + BO + Approval (Web & Mobile)

Ringkas. Detail lengkap: lihat `DEPLOY.md`.

## A. Satu perintah, semua jalan
> **Kenapa bukan satu file `docker compose`?** FO & BO memakai nama service generik
> sama (`app`/`nginx`/`redis`) → bentrok bila digabung 1 project; plus ada langkah
> runtime (buat DB `ipro_bo`, connect network, migrate, backfill). Jadi dipakai
> **skrip wrapper** = tetap satu perintah.

| Perintah | Fungsi |
|---|---|
| `bash marger/deploy.sh` | **Deploy PERTAMA** — build, buat DB `ipro_bo`, migrate, **seed**, backfill FO→BO. |
| `git pull && SEED=0 bash marger/deploy.sh` | **Update** kode (migrate tanpa seed, data aman). |
| `bash marger/up.sh` | **Start harian** semua: FO + BO + Mobile (tanpa migrate/seed). |
| `bash marger/down.sh` | **Stop** semua (data & volume aman). |

## B. Urutan setup (sekali)
```bash
# 1. Clone (J-ERP berisi ipro-fo di marger/ + app mobile di mobile/)
git clone https://github.com/arieyogian/jerp.git && cd jerp

# 2. Isi .env DUA app (lihat DEPLOY.md §1). WAJIB konsisten:
#    - SSO_SHARED_SECRET            : sama di J-ERP & ipro-fo
#    - IPRO_APP_KEY (J-ERP) = APP_KEY (ipro-fo)   ← JANGAN key:generate ipro-fo
#    - FO_URL=https://fo.test.com  IPRO_BO_URL=https://bo.test.com  APP_URL sesuai domain
nano .env ; nano marger/ipro-fo_1/ipro-fo/.env

# 3. Mobile Approver — arahkan API ke server:
#    mobile/approval_flow/src/config.ts  →  API_BASE_URL = 'https://fo.test.com/api/mobile'
nano mobile/approval_flow/src/config.ts

# 4. Jalankan semua
bash marger/deploy.sh          # FO + BO (build/migrate/seed/backfill)
bash marger/up.sh              # + start Mobile (atau langsung up.sh saja setelah deploy)

# 5. Reverse proxy + TLS:  fo.test.com → :8081 , bo.test.com → :8090   (DEPLOY.md §3)
```

## C. Cara mengakses
| Bagian | Akses |
|---|---|
| **FO** (Marketing, Finance AR, Collection, Legal, Launching, Komisi) | `https://fo.test.com` → login `admin@jerp.test` → sidebar FO |
| **BO + Bankloan** (GL, AR, AP, Cash, Inventory, COGS, Budget, KPR) | **HANYA via J-ERP**: login `fo.test.com` → hub → tile **Back Office** (SSO). Login langsung `bo.test.com` dimatikan (`SSO_FO_LOGIN_ONLY=true`) → 1 pintu. Darurat: set `false`. |
| **Approval — FO (web)** | sidebar FO → grup **Approval** (`/fo/approvals`): diskon, batal, refund, BLT, komisi |
| **Approval — BO (web)** | sidebar BO → **Approval Center** (`/approvals`): keuangan/GL/AP |
| **Approval — Mobile** | app Expo `mobile/approval_flow`; jalankan `up.sh` → `docker logs -f jerp-mobile` → **scan QR pakai Expo Go** di HP. API ke `<FO_URL>/api/mobile`. |

> Approval ada di **web (FO & BO)** dan **mobile (Expo)** — approver bisa approve dari HP.

## D. Verifikasi cepat
1. `fo.test.com/login` → hub muncul (Front Office / Back Office / Backup).
2. Klik **Back Office** → masuk BO via SSO (tanpa login ulang).
3. FO → **Approval** tampil (tidak 500); BO → **Approval Center** tampil.
4. BO → **Master Unit** → 242 unit (cluster/tipe/LT-LB terisi).
5. **Mobile**: `docker logs -f jerp-mobile` → scan QR di Expo Go → login → daftar approval muncul → coba approve.

## E. Yang HARUS diingat
- **1 MySQL**: `jerp-mysql` berisi `jerp` (FO) + `ipro_bo` (BO). MySQL lama ipro-fo sudah dihapus dari compose.
- **APP_KEY ipro-fo jangan diganti** (rusak enkripsi NIK/NPWP).
- **Mobile config.ts** harus diarahkan ke domain server (`<FO_URL>/api/mobile`) sebelum dipakai HP.
- Mobile = **Expo dev server (tunnel/ngrok)** untuk testing dgn Expo Go; untuk rilis app toko perlu build APK/IPA (eas build) — di luar scope server test.
- **Email FO↔BO** SSO: petakan di `marger/ipro-fo_1/ipro-fo/config/sso.php → email_map`.
- **Mapping COA** (`config/ipro.php → coa_map`) best-effort, jurnal status **draft** → verifikasi akuntan sebelum `IPRO_GL_STATUS=posted`.
- Restart `ipro-fo-app` → restart `ipro-fo-nginx` juga (cegah 502). `up.sh` sudah otomatis restart nginx.
