# Rencana Perbaikan QA — Integrasi FO + BO + Approval

Status: **DRAFT untuk review** (belum dieksekusi). Sumber: audit 5 reviewer (Security, Laravel/Sync/GL, Approval, Filament/Vue, DevOps).
Skor kesiapan saat ini **34/100 — FAIL**. Target setelah Wave 0–1: layak uji terkendali; setelah Wave 0–3: layak produksi.

Legenda effort: **S** ≤1 jam · **M** beberapa jam · **L** ≥1 hari. App: **J** = J-ERP · **I** = ipro-fo · **D** = deploy/infra.

---

## Ringkasan paket kerja (bisa jadi 1 commit/PR per paket)

| Paket | Tema | Wave |
|---|---|---|
| A | Approval: otorisasi + integritas (jalur uang) | 0–1 |
| B | Auth & API security (login, throttle, SSO) | 0 |
| C | GL & Sync: integritas data keuangan | 0–2 |
| D | Deploy/DevOps hardening | 0–2 |
| E | Performance | 2 |
| F | Frontend polish (Vue) | 3 |
| G | Testing (lintas paket) | 0–3 |

---

## WAVE 0 — BLOCKER (wajib, sebelum dipakai siapa pun)

> Tujuan: hentikan kebocoran. Approval bisa di-eksekusi tanpa wewenang & dobel; login tanpa rem; GL bisa jurnal ganda; BO gagal konek di server.

### Paket A — Approval (inti)
| ID | Sev | App | Perbaikan | File | Effort |
|---|---|---|---|---|---|
| A1 | 🔴 | J | **Otorisasi terpusat**: buat `ApprovalPolicy` + chain-step approver (port pola ipro-fo `ActOnApproval`: cek SuperAdmin / role / user untuk level berjalan). Panggil `authorize()` di mobile controller, Filament action, **dan** di dalam `ApprovalService` (chokepoint). | `ApprovalService.php:42-91`, `Api/Mobile/ApprovalController.php:61-118`, `ApprovalResource.php:262-302`, +`app/Policies/ApprovalPolicy.php` | L |
| A2 | 🔴 | J+I | **Anti double-execute**: bungkus transisi dalam 1 transaksi + `lockForUpdate` + re-cek status di dalam tx; eksekusi keuangan di tx yang sama (rollback bila gagal). | `ApprovalService.php:57-75`; ipro-fo `Domain/Approval/Actions/ActOnApproval.php:31-104` | M |
| A3 | 🟠 | J | **Separation of duties**: tolak bila aktor = `requested_by`; tolak bila L2 aktor = `level1_by`. | `ApprovalService.php:42-75` | S |

### Paket B — Auth & API
| ID | Sev | App | Perbaikan | File | Effort |
|---|---|---|---|---|---|
| B1 | 🔴 | J | **Rate-limit login** (RateLimiter per email+IP di komponen Livewire). | `app/Livewire/Auth/Login.php:22-50` | S |
| B2 | 🔴 | J | **Throttle `/api/mobile`** (`throttle:api`) + ketat di `auth/login` (`throttle:5,1`); batasi `ids` (`max:100`). | `routes/api.php:20-36`, `AppServiceProvider` (limiter) | S |
| B3 | 🔴 | J+I | **SSO anti-replay**: tambah `jti` sekali-pakai (Cache add s/d exp) + keluarkan token dari URL (auto-submit POST atau one-time code) + scrub dari log/Telescope. | `Api...SsoController` (J), `app/Http/Controllers/SsoController.php` (I) | M |

### Paket C — GL (idempotensi)
| ID | Sev | App | Perbaikan | File | Effort |
|---|---|---|---|---|---|
| C1 | 🔴 | I | **Unique index** `bs_journals(tenant_id, reference)` + tangkap duplikat (23000) → kembalikan jurnal yang ada. | migrasi ipro_bo + `IproGlPoster.php:58-62` | S |

### Paket D — Deploy
| ID | Sev | App | Perbaikan | File | Effort |
|---|---|---|---|---|---|
| D1 | 🔴 | D | `shared.yml` `DB_PASSWORD/USERNAME` dari **env** (`${DB_PASSWORD:?}`), bukan hardcode `secret`. | `docker-compose.shared.yml:17` | S |
| D3 | 🟠 | D | Lepas `|| true` pada **CREATE DATABASE/GRANT** (biar gagal keras). | `deploy.sh:20`, `up.sh:21` | S |

**Exit Wave 0:** approval hanya bisa oleh approver sah & tak bisa dobel; login & API ada rem; SSO tak bisa replay; GL tak bikin jurnal ganda; BO konek di server.

---

## WAVE 1 — HIGH (sebelum produksi)

### Paket A (lanjutan)
| ID | Sev | App | Perbaikan | File | Effort |
|---|---|---|---|---|---|
| A4 | 🟠 | J | `decideMany`: `ids` `distinct`+`max`, **log kegagalan** (hapus empty catch), kembalikan hasil per-id. | `ApprovalController.php:92-118` | S |
| A5 | 🟡 | J | Reject **wajib alasan** di API (`required_if:action,rejected`). | `ApprovalController.php:64-74` | S |
| A6 | 🟡 | J | Form edit Filament: `status/approved_by/requested_by` jadi **display-only** (tak bisa lewati workflow). | `ApprovalResource.php:84-92` | S |
| A7 | 🟠 | J | Mobile token **scoped ability** + login dibatasi peran approver. | `Api/AuthController.php:25-30` | S |

### Paket C (lanjutan)
| ID | Sev | App | Perbaikan | File | Effort |
|---|---|---|---|---|---|
| C3 | 🟠 | J | **Transaksi** booking + `payment_schedules` (delete→insert) dalam 1 tx `ipro_bo`. | `IproMasterSync.php:280-314` | S |
| C4 | 🟠 | J | `payment_scheme_id` via **lookup `type`** (tenant/project), fallback dibuat — bukan hardcode 1/2/3. | `IproMasterSync.php:236,250` | M |
| C5 | 🟡 | J | COA tak ter-map → **akun suspense** + error keras ke outbox (bukan drop diam); assert debit==kredit. Akuntan rapikan id ganda. | `IproGlPoster.php:45-54`, `config/ipro.php` | M |
| C7 | 🟠 | J | Ganti exception ditelan → `Log::error` + tabel **`sync_failures`** (replayable) + alert. | `BoServiceProvider.php:51-57`, `PostFoEventToIproGl.php` | M |

### Paket D (lanjutan)
| ID | Sev | App | Perbaikan | File | Effort |
|---|---|---|---|---|---|
| D2 | 🟠 | D | Wire `docker-compose.prod.yml` ke deploy/up (`COMPOSE_FILE`) → **port DB/Redis/Meili tak ter-expose** ke host. *(verifikasi prod.yml ada di server)* | `deploy.sh:17`, `up.sh:18` | S |
| D4 | 🟠 | D+J | Mobile `API_BASE_URL` dari **`EXPO_PUBLIC_API_BASE_URL=${FO_URL}/api/mobile`** (default localhost, bukan produksi). | `mobile/approval_flow/src/config.ts`, `docker-compose.mobile.yml` | S |
| D5 | 🟠 | D | `npm ci` (jaga lockfile) + pin `@rollup/rollup-linux-*-musl`, ganti `rm -rf node_modules` tiap deploy. | `deploy.sh:28` | S |
| D6 | 🟠 | I | Hapus/override `horizon/scheduler/reverb` yang masih `DB_HOST=mysql` & tak di network `shared-db` (cegah `compose up` penuh crash). | ipro-fo `docker-compose.yml:69`, `shared.yml` | S |

**Exit Wave 1:** audit lengkap, validasi konsisten lintas-surface, sync tahan-gagal & tak hilang data, deploy aman & reproducible.

---

## WAVE 2 — MEDIUM (kualitas & ketahanan)

| ID | Sev | App | Perbaikan | File | Effort |
|---|---|---|---|---|---|
| C2 | 🟠 | J | **Outbox/queue GL**: jangan post di dalam transaksi FO; pakai `afterCommit` + queued job (retry) + unique ref (C1). | `PostFoEventToIproGl.php`, `IproGlPoster.php` | L |
| C6 | 🟡 | J | `nextNumber` jurnal via tabel `numerators`/`lockForUpdate` (anti race + >99999). | `IproGlPoster.php:97-106` | S |
| C8 | 🟠 | J | **Queue hook `saved()`** (afterCommit + job per-id, debounced) — hilangkan latency & amplifikasi di request. | `BoServiceProvider.php:59-63` | M |
| C9 | 🟡 | J | Map enum: KPR `BATAL` → status terminal; log saat key tak ter-map (bukan default diam). | `IproMasterSync.php:384` dll | S |
| C10 | 🟡 | J | `IproMasterSync` resolve `userId` **lazy** (jangan query di constructor singleton). | `IproMasterSync.php:30-44` | S |
| E1 | 🟡 | J | Cache dashboard ~2 mnt + `getTrend` jadi 1 query grouped + `getStats()` dipanggil sekali. | `Dashboard.php:145-213`, `dashboard.blade.php:261` | S |
| E2 | 🟡 | J | Memoize `iproClusterId/iproUnitTypeId` per id saat backfill. | `IproMasterSync.php:147-225` | S |
| E3 | 🟡 | J | Memoize/sederhanakan `buildFullNavigation` (`isActiveWhen` tanpa `parse_url` per item). | `FoPanelProvider.php:100-138` | S |
| D7 | 🟡 | D | GRANT least-privilege (SELECT/INSERT/UPDATE/DELETE saja); hapus `GRANT SELECT ON jerp.*` redundan. | `deploy.sh:20` | S |
| D8 | 🟢 | D | Default `SEED=0`; minta `SEED=1` eksplisit hanya deploy pertama. | `deploy.sh:12` | S |
| D9 | 🟡 | I+D | Healthcheck php-fpm ipro-fo `app` + nginx `service_healthy`; `logging` size limit. | ipro-fo `docker-compose.yml` | M |
| D10 | 🟡 | D | Retry konektivitas DB sebelum migrate; assert `IPRO_DB_HOST==DB_HOST`. | `deploy.sh:18-25`, `config/database.php` | S |

---

## WAVE 3 — LOW / polish

| ID | Sev | App | Perbaikan | File | Effort |
|---|---|---|---|---|---|
| C11 | 🟡 | J | Audit per-aksi (IP/device/baris `ApprovalAction`); `reject` jangan timpa `approved_by` (pakai `rejected_by`). | `ApprovalService.php:82-88` | S |
| F1 | 🟢 | I | `Show.vue`: type prop (bukan `any`), perbaiki staleness `f.sprs` fallback. | `Show.vue:70-109` | M |
| F2 | 🟢 | I | `:key` jangan index; tambah loading/error/empty state. | `Show.vue:510` | S |
| B4 | 🟡 | J | PII: baca cipher dari config (jangan hardpin CBC); simpan `null` bila NIK kosong. | `IproMasterSync.php:32-37,62` | S |
| B5 | 🟡 | J+I | Pertimbangkan kunci enkripsi-field terpisah (bukan APP_KEY ipro-fo) untuk perkecil blast-radius. | `config/ipro.php` | M |

---

## Paket G — Testing (paralel tiap wave)

> ⚠️ Catatan: `php artisan test` di container bisa **menghapus DB dev MySQL**. Pakai env phpunit `force=true` / SQLite one-off (lihat memori proyek).

- **Wave 0**: Feature test authz approval (user bukan approver → 403), SoD (pemohon self-approve → 403), concurrency double-execute (eksekusi 1×), SSO replay ditolak, login throttle, GL event dobel → 1 jurnal.
- **Wave 1**: API reject wajib alasan, `decideMany` lapor id gagal, sync SPR scheme non-1/2/3 tetap jalan, transaksi schedules tak hilang saat insert gagal.
- **Wave 2**: GL outbox retry, dashboard query count turun, hook saved ter-queue.
- **Regression**: alur sync FO→BO penuh setelah refactor transaksi/queue.

---

## Urutan eksekusi yang disarankan

1. **Wave 0** (A1–A3, B1–B3, C1, D1, D3) — paling berdampak, sebagian besar S/M. Estimasi ~1–2 hari.
2. **Wave 1** (A4–A7, C3–C5, C7, D2,D4–D6) — ~2–3 hari.
3. **Wave 2** (C2,C6,C8–C10, E1–E3, D7–D10) — ~2–3 hari (C2 outbox paling berat).
4. **Wave 3** (C11, F1–F2, B4–B5) — polish.
5. **Testing** menyertai tiap wave; jangan commit tanpa perintah Anda.

**Catatan:** beberapa perbaikan menyentuh kode **bawaan** (Approval, login, mobile API) yang sudah ada sebelum integrasi — termasuk dalam scope "perbaiki semuanya". Item bertanda *verifikasi* (mis. D2 prod.yml) perlu dicek dulu di server sebelum diubah.
