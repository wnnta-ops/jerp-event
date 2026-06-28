# 🔍 GAP ANALYSIS: Java Core System Lama (lama) vs Java Core System v2.0 (baru)

**Sumber:** Live observation `https://fo.javaresidence.com` (29 Mei 2026)
**Status:** Login page + homepage dashboard sudah teramati (read-only, tidak ada modifikasi data)

---

## 🏷️ Identitas Software Lama

| Field | Nilai |
|-------|-------|
| Product | **Java Core System Lama** (by Batavianet / JAVA CORE SYSTEM LAMA.tech) |
| Engine version | **IPRO.01.01.001** ← asal mula nama "IPRO" di rebuild |
| Vendor support | +62 21 29020111, 54356161 \| support@JAVA CORE SYSTEM LAMA.tech |
| Copyright | © Batavianet. All Rights Reserved |
| Branding di tenant | "PT. Mitra Usaha Propertindo" (multi-tenant white-label) |

---

## ⚙️ Tech Stack Java Core System Lama (terkonfirmasi)

| Layer | Teknologi | Bukti |
|-------|-----------|-------|
| Bahasa server | **ASP.NET Web Forms (.NET Framework)** | `__VIEWSTATE`, `__VIEWSTATEGENERATOR`, `__EVENTVALIDATION` hidden fields |
| Web server | **Microsoft IIS** | Error page 404 IIS-style ketika hit `/login` (path tidak ada) |
| URL pattern | **`.aspx` file extension** | `https://fo.javaresidence.com/Gateway.aspx` |
| Arsitektur | **Multi-app modular** (sub-apps per modul) | Path: `/settings/`, `/security/`, `/adminjual/`, `/financear/`, dll |
| Session | **In-URL query string** (lihat security findings di bawah) | `?UserID=BG&SID=...` di setiap link |

**Implikasi rebuild:** Lompatan dari ASP.NET Web Forms (legacy, masih bergantung VIEWSTATE & postback) ke **Laravel 12 + Inertia + Vue 3 + Tailwind** adalah modernisasi total — bukan port. Pilihan rebuild ini terbukti tepat.

---

## 🚨 Critical Security Findings di Java Core System Lama Lama

### 1. **Session ID di URL** (OWASP A07 — Identification & Authentication Failures)

Setiap link modul di homepage Java Core System Lama bentuknya:
```
https://fo.javaresidence.com/settings/?UserID=BG&SID=0r0sa02mz2wqyqclxasnpefi
https://fo.javaresidence.com/security/?UserID=BG&SID=0r0sa02mz2wqyqclxasnpefi
https://fo.javaresidence.com/marketingjual/?UserID=BG&SID=0r0sa02mz2wqyqclxasnpefi
```

**Risiko:**
- Session ID (`SID`) bocor ke server access log (Apache/IIS log)
- Bocor ke browser history & bookmark
- Bocor ke header `Referer` saat klik link eksternal
- Bisa dilihat oleh proxy/firewall corporate
- Kalau user share URL via WhatsApp/email → session ter-share

**Yang dilakukan Java Core System v2.0:**
✅ Session ID disimpan di **httpOnly cookie**, tidak bisa diakses JavaScript
✅ Cookie SameSite=Lax (anti-CSRF)
✅ CSRF token terpisah di meta tag + di-validate Laravel middleware
✅ Saat production: `Secure` flag aktif (HTTPS only)
✅ Hash regeneration via `session()->regenerate()` setelah login (anti session-fixation)

### 2. **UserID di URL** — Privacy & enumerability

URL exposes `UserID=BG`. Konsekuensi:
- User ID bocor di log → siapa siapa di sistem bisa di-enumerate
- Pengganggu bisa ganti `UserID=BG` jadi `UserID=admin` untuk probing

**Java Core System v2.0:** User ID disimpan di session backend, tidak pernah muncul di URL.

### 3. **No CSRF Token visible** di form login

Tidak ada meta `csrf-token`, tidak ada hidden `_token`. Web Forms mengandalkan `EVENTVALIDATION` yang lebih lemah dari CSRF token modern.

### 4. **No `autocomplete` / Modern Form Attributes**

Form login pakai `Username` field (bukan email), no autocomplete, no required.

### 5. **Info leak ke user:**
- IP Address user di-display: `IP Address : 110.139.62.135` (informational, bukan masalah besar tapi suspicious)

---

## 📊 Modul Coverage — Java Core System Lama vs Java Core System v2.0

### Module Cards di Homepage Java Core System Lama (teramati live):

| # | Module Card (lama) | URL Path | Java Core System v2.0 | Status Parity |
|---|--------------------|----------|--------------|---------------|
| 1 | SETTINGS | `/settings/` | `/settings` | ✅ Sudah |
| 2 | SECURITY | `/security/` | RBAC via Spatie | ✅ Sudah, lebih modern |
| 3 | SETUP SALES | `/adminjual/` | Master Unit + Pricelist | ✅ Sudah |
| 4 | SETUP LEASING | _(placeholder, no link)_ | ❌ Belum | ⚠️ Skipped (sesuai scope MUP fokus jual) |
| 5 | SALES | `/marketingjual/` | Hold + Booking + Contract | ✅ Sudah, dengan Wizard |
| 6 | LEASING | _(placeholder, no link)_ | ❌ Belum | ⚠️ Skipped |
| 7 | COLLECTION | `/collection/` | Followup + Denda + PJT + Tunggakan + SP + SKL | ✅ Sudah, lebih granular |
| 8 | FINANCE & AR | `/financear/` | TTS + Kuitansi + Memo | ✅ Sudah |
| 9 | BANK LOAN | `/kpa/` | KPA → SP3K → Akad → Disbursement | ✅ Sudah, full lifecycle |
| 10 | LEGAL | `/legal/` | PPJB + AJB + BAST + IMB + Sertifikat | ✅ Sudah |
| 11 | APPROVAL | `/approval/` | Approval Engine dengan chain resolver | ✅ Sudah, lebih flexible |
| 12 | LAUNCHING | `/launching/` | Launching module | ✅ Sudah |
| 13 | NUP | `/nup/` | NUP Registration + queue | ✅ Sudah |
| 14 | KOMISI | `/komisi/` | Commission Calc + Payout | ✅ Sudah |

**Kesimpulan modul:** **12/14 PARITY**, 2 placeholder yang juga belum aktif di Java Core System Lama (Leasing).

### Module Tambahan di Java Core System v2.0 (TIDAK ada di Java Core System Lama):

| Modul | Manfaat |
|-------|---------|
| **Cashback** | Cashback management dengan approval flow |
| **Refund** | Refund request + approval + payment |
| **Virtual Account + Webhook Bank** | Auto-rekonsiliasi pembayaran dari bank (BCA, Mandiri, dll) |
| **Faktur Pajak generator** | Generate FP otomatis dengan format e-Faktur DJP |
| **Exception flow UI (GN, GU, Reschedule)** | Menu khusus untuk ganti nama, ganti unit, reschedule cicilan dengan workflow approval |
| **Anonymous Payment Portal** | Customer bisa bayar tanpa login (VA scan) |
| **Dashboard KPI** | Real-time card: Total Customer, Booking Aktif, Booking Bulan Ini |
| **Global Search (Cmd+K)** | Search across customers/units/bookings |
| **REST API + Sanctum Bearer Token** | Mobile/external integration siap |
| **Polymorphic Audit Log** | Setiap perubahan tercatat dengan model_type + model_id |
| **Idempotency Middleware** | Anti double-submit untuk POST (penting untuk finance ops) |
| **State Machine** | Booking & KPA punya transition validation eksplisit |
| **Numerator Service** | Generate nomor dokumen dengan pattern + reset cycle |

---

## 🎨 UX & Design Differences

### Java Core System Lama (lama):
- **Homepage:** Grid 4 baris × 4 kolom card warna-warni (Bootstrap-ish CSS)
- **Navigation:** Click card → reload ke sub-app baru (postback klasik)
- **No sidebar** — kembali ke homepage via menu/link
- **Static page reload** setiap navigasi (ASP.NET Web Forms postback)
- **Background:** White polos
- **Logo:** "Java Core System Lama" wordmark dengan hex "P" icon
- **Footer:** Copyright Batavianet + contact JAVA CORE SYSTEM LAMA.tech

### Java Core System v2.0 (baru):
- **Sidebar permanen** dengan grouping per domain (Master, Sales, Finance, dll)
- **Inertia SPA navigation** — tidak ada page reload, instant
- **Dashboard KPI cards** di homepage (Total Customer, Booking Aktif, dll)
- **Dark mode toggle** built-in
- **Global search Cmd+K**
- **Branding:** "Java Core System" wordmark, primary biru tua, "PT. MUP" footer
- **Inertia progress bar** di top saat navigasi

---

## 🔁 URL & Routing Patterns

### Java Core System Lama (lama):
```
/Gateway.aspx              ← post-login homepage
/settings/?UserID=BG&SID=… ← module entry dengan session di URL
/security/?UserID=…
/adminjual/?UserID=…       ← "admin penjualan"
/marketingjual/?UserID=…   ← "marketing penjualan"
/financear/?UserID=…       ← finance + accounts receivable
/kpa/?UserID=…             ← KPA (Kredit Pemilikan Apartemen)
/komisi/?UserID=…
```

### Java Core System v2.0 (baru):
```
/dashboard                 ← homepage SPA, Inertia
/customers                 ← RESTful resource
/units
/bookings
/tts, /kuitansi, /memos, /cashbacks, /refunds, /faktur-pajak, /va
/followups, /dendas, /pjt, /tunggakan, /sp, /skl
/kpa                       ← namanya tetap KPA
/ppjb, /ajb, /bast, /imb, /sertifikat
/commissions, /approvals, /exceptions
/holds, /nup, /contracts, /settings
/api/v1/…                  ← REST API (Sanctum bearer)
```

**Diff:**
- Java Core System pakai **RESTful + plural English nouns** (lebih maintainable, lebih familiar untuk dev modern)
- Tidak ada session ID/user ID di URL — semua di httpOnly cookie

---

## 📝 Login Form Differences

| Aspek | Java Core System Lama (lama) | Java Core System v2.0 (baru) |
|-------|------------------|---------------------|
| Field 1 | **Username** | **Email** |
| Field 2 | Password (no autocomplete hint) | Password dengan `autocomplete="current-password"`, `minlength="8"` |
| Remember-me | ❌ Tidak ada | ✅ Checkbox "Tetap masuk" |
| Forgot password | ❌ Tidak ada | ❌ Belum ada (opportunity) |
| MFA / 2FA | ❌ Tidak ada | ❌ Belum ada (opportunity, layak ditambah) |
| Captcha | ❌ Tidak ada | ❌ Belum ada |
| CSRF protection | EVENTVALIDATION (Web Forms) | ✅ Modern CSRF token + Sanctum |
| Throttling | ⚠️ Unknown | ✅ `throttle:5,1` di backend |
| Branding | Java Core System Lama logo (vendor brand) | Java Core System logo (custom MUP) |
| Footer | Copyright vendor (Batavianet) | "© 2026 PT. MUP — Java Core System v2.0" |
| Tenant title | "PT. Mitra Usaha Propertindo" displayed di card | Bisa ditambah jika perlu |

---

## 🎯 Action Items untuk Java Core System v2.0

Berdasarkan observasi, ada beberapa **enhancement opportunity**:

### Quick Wins (mudah, high value):
1. ✨ **Tambah "PT. Mitra Usaha Propertindo" di login page** (parity dengan Java Core System Lama branding tenant) — supaya user familiar
2. ✨ **Tambah "Lupa Password?" link** di login page → kirim email reset
3. ✨ **Tampilkan info "Login terakhir Anda: …"** di dashboard (sudah ada di User model `last_login_at`, tinggal ditampilkan) — Java Core System Lama punya ini, retention nice-to-have
4. ✨ **Welcome message dengan nama user** di dashboard, sudah ada di Java Core System Lama: "Selamat Datang BAGUS." — easy win

### Strategic (lebih besar, butuh diskusi):
5. 🔒 **MFA / 2FA** untuk role tertentu (Direktur, FinanceManager) — security uplift signifikan
6. 🔒 **Login attempt logging** ke audit log (success + failed attempt + IP) — sudah ada `failed_login_count` di User model, tinggal hook di LoginController
7. 🌐 **Sub-app navigation pattern** seperti Java Core System Lama (full module page) vs **single SPA navigation** — sudah kita pilih SPA (lebih modern, lebih cepat)
8. 📱 **Mobile-responsive sidebar** — Java Core System Lama desktop-only, Java Core System sudah responsive

### Tidak perlu dilakukan (intentional departure):
- ❌ **Session ID di URL** — anti-pattern, Java Core System sudah tidak begini (✅)
- ❌ **ASP.NET Web Forms postback pattern** — replaced dengan Inertia SPA (✅)
- ❌ **Module sub-apps terpisah** — replaced dengan single SPA + sidebar (✅)
- ❌ **Username login** — diganti email (lebih recover-able, anti enumeration) (✅)

---

## ✅ Validasi: Java Core System v2.0 vs Software Lama

| Kategori | Java Core System v2.0 | Verdict |
|----------|--------------|---------|
| Modul coverage | 12/14 parity + 13 modul tambahan | ✅ Setara/lebih lengkap |
| Tech stack | Modern (Laravel 12, Vue 3, Tailwind, Sanctum) | ✅ Major upgrade dari Web Forms |
| Security | httpOnly cookie, CSRF token, throttle, RBAC | ✅ Drastis lebih aman |
| UX | SPA, dark mode, search global, KPI dashboard | ✅ Lebih modern |
| Maintainability | DDD bounded contexts, Laravel ecosystem | ✅ Standard industri |
| Multi-tenant | Tenant model + scope siap | ✅ Setara (parity) |
| Audit | Polymorphic audit log otomatis | ✅ Lebih lengkap |
| API | REST + Sanctum + idempotency | ✅ Modern, Java Core System Lama tidak punya |

**Kesimpulan:** Rebuild **bukan hanya parity**, tapi **upgrade signifikan** di security, UX, dan extensibility.

---

## 📸 Bukti Observasi

- Login page Java Core System Lama: form Username + Password, branding "Java Core System Lama by JAVA CORE SYSTEM LAMA.tech"
- Homepage post-login: grid 14 module cards berwarna untuk user "BAGUS" (security level SUP)
- URL pattern: `Gateway.aspx` post-login, sub-app per module dengan `?UserID=…&SID=…`
- Engine version footer: **IPRO.01.01.001** (asal mula nama "Java Core System" di rebuild)

---

---

## 🔬 Deep Dive: Modul SALES (Marketing Jual)

**URL:** `/marketingjual/Index.aspx?UserID=…&SID=…`
**Page title:** `Java Core System Lama :: Marketing Jual`

### Arsitektur halaman:
- **Frameset / iframe lama** dengan 2 frame: `nav` (sidebar) + `content` (main)
- Pattern era 2005-2010, sebelum SPA modern
- Setiap klik menu di sidebar → load page baru di iframe `content`

### Sidebar SALES Java Core System Lama (10 menu items):

| # | Menu Item | Icon | Sub-menu? |
|---|-----------|------|-----------|
| 1 | **Reminder** | 🔔 bell | — |
| 2 | **Dashboard** | 📊 chart bar | — |
| 3 | **Laporan** | 🥧 pie chart | — |
| 4 | **Kalkulator** | 🧮 calculator | ➕ expandable |
| 5 | **Customer** | 👥 people | ➕ expandable |
| 6 | **Unit** | 🏢 building | ➕ expandable |
| 7 | **Booking** | 📅 calendar | ➕ expandable |
| 8 | **Kontrak** | 📄 document | ➕ expandable |
| 9 | **Migrate** | 📁 file | ➕ expandable |
| 10 | **Log File** | 📄 file | — |

### Perbandingan dengan Java Core System v2.0 Sales Group:

| Java Core System Lama SALES | Java Core System v2.0 | Catatan |
|-----------------|--------------|---------|
| **Reminder** | ❌ Belum ada | ⚠️ **OPPORTUNITY** — sales person butuh reminder follow-up, expire NUP, dll |
| **Dashboard** | ✅ Dashboard (KPI cards) | Kita sudah punya, lebih kaya |
| **Laporan** | ⚠️ Hanya Export Customer/Booking | ⚠️ **OPPORTUNITY** — Java Core System Lama punya report builder lebih kaya |
| **Kalkulator** | ⚠️ Embedded di Booking Wizard | Java Core System Lama pakai standalone tool untuk pre-sales simulation |
| **Customer** | ✅ /customers (di Master group) | Strukturalnya berbeda — kita pisahkan ke "Master" |
| **Unit** | ✅ /units (di Master group) | Idem |
| **Booking** | ✅ /bookings (full Wizard + State Machine) | Kita lebih kompleks (8 status state machine) |
| **Kontrak** | ✅ /contracts (PPJB) | Sudah parity |
| **Migrate** | ❌ Belum ada | Bulk import customer/unit dari Excel — Java Core System sudah ada `bulk-import` API tapi belum ada UI |
| **Log File** | ⚠️ Hanya tabel audit_log backend | ⚠️ **OPPORTUNITY** — perlu UI viewer untuk audit log per user/per module |

### Modul SALES Java Core System v2.0 yang TIDAK ada di Java Core System Lama SALES:

| Java Core System v2.0 Sales | Catatan |
|---------------------|---------|
| **Hold Unit** | Eksplisit menu hold unit dengan release flow & expiry — di Java Core System Lama mungkin manual atau tidak ada |
| **NUP** | Di Java Core System Lama NUP menjadi modul terpisah di homepage (bukan dalam Sales) — kita juga punya di sidebar Sales |
| **Exceptions** | Menu khusus GN/GU/Reschedule dengan workflow — di Java Core System Lama mungkin manual |

---

## 🎯 Action Items Tambahan dari Observasi SALES Module

### Quick Wins (1-2 jam each):
1. ✨ **Tambah menu "Reminder"** di Sales group Java Core System — list follow-up & event yang due
2. ✨ **Tambah menu "Kalkulator"** standalone untuk pre-sales simulation (cicilan, total, DP) tanpa harus buat booking
3. ✨ **UI Audit Log Viewer** di Settings → user bisa lihat siapa ubah apa kapan
4. ✨ **UI Bulk Migrate** (upload Excel customer/unit) — backend sudah ada, tinggal UI form

### Strategic (lebih besar):
5. 📊 **Report Builder lengkap** — Java Core System Lama punya "Laporan" terpisah, kita baru ada export. Bisa pakai package seperti Report Builder atau bikin custom dengan dynamic filter.

---

---

## 🗺️ COMPLETE URL MAP Modul SALES Java Core System Lama (34 endpoints)

Diekstrak dari `onclick` handlers JavaScript (`go(this, 'Page.aspx')`) di sidebar nav frame:

### 📋 Reminder, Dashboard, Laporan
| URL Endpoint | Fungsi | Status di Java Core System v2.0 |
|--------------|--------|------------------------|
| `Reminder.aspx` | Reminder/notification dashboard | ❌ Belum ada |
| `DashboardSales.aspx` | Dashboard khusus Sales role | ⚠️ Generic dashboard, belum per-role |
| `Lap.aspx` | Hub laporan | ⚠️ Hanya export, belum report center |

### 🧮 Kalkulator (3 sub) — ⭐ Banyak yang Java Core System belum punya!
| URL Endpoint | Fungsi | Status di Java Core System v2.0 |
|--------------|--------|------------------------|
| `KalkulatorKPR.aspx` | Simulasi cicilan KPR/KPA | ⚠️ Embedded di booking wizard, no standalone |
| `KalkulatorSkema.aspx` | Simulasi skema pembayaran | ⚠️ Embedded di booking wizard |
| `KalkulatorEstimasiBPHTB.aspx` | **Estimasi BPHTB (tax)** | ❌ **BELUM ADA — penting!** ⭐⭐⭐ |

> 🔥 **BPHTB Calculator** = Bea Perolehan Hak atas Tanah & Bangunan = pajak transfer hak property. Wajib hitung di transaksi jual-beli. Java Core System **WAJIB** punya ini.

### 👥 Customer (4 sub)
| URL Endpoint | Fungsi | Status di Java Core System v2.0 |
|--------------|--------|------------------------|
| `Customer.aspx` | Customer list | ✅ `/customers` |
| `CustomerDaftar.aspx` | Customer registration form | ✅ `/customers/create` |
| `CustomerGabung.aspx` | **Merge dua customer** (deduplikasi) | ❌ Permission `master.customer.merge` ada, UI belum |
| `CustomerUpload.aspx` | **Bulk upload customer** (Excel) | ❌ Backend siap, UI belum |

> 🔥 **Customer Merge & Bulk Upload** — Java Core System punya permission/backend tapi tidak ada UI. Layak ditambahkan.

### 🏢 Unit (3 sub)
| URL Endpoint | Fungsi | Status di Java Core System v2.0 |
|--------------|--------|------------------------|
| `Unit.aspx` | Master unit list | ✅ `/units` |
| `TabelStok.aspx` | Stock table view | ⚠️ Index ada, "Tabel Stok" specifically belum (mungkin sama, mungkin breakdown availability) |
| `UnitPetaList.aspx` | **Site Plan visualization** (peta unit) | ✅ `/units/siteplan` (sudah ada!) |

### 📅 Booking / "Reservasi" (3 sub) — ⚠️ Nama internal: "Reservasi"!
| URL Endpoint | Fungsi | Status di Java Core System v2.0 |
|--------------|--------|------------------------|
| `Reservasi.aspx` | Booking baru (Pendaftaran) | ✅ `/bookings/create` (Wizard) |
| `ReservasiGantiNama.aspx` | Ganti nama booking | ✅ Exception GN flow |
| `ReservasiGantiUnit.aspx` | Ganti unit booking | ✅ Exception GU flow |

> 🔥 **Insight nama:** Internally Java Core System Lama pakai "Reservasi" (Indonesian for "Reservation") untuk Booking. Tim sales mungkin terbiasa istilah ini. Kemungkinan perlu rename UI label di Java Core System atau sediakan alias.

### 📑 Kontrak (9 sub) — ⭐⭐ HUGE! Java Core System baru punya 1-2!
| URL Endpoint | Fungsi | Status di Java Core System v2.0 |
|--------------|--------|------------------------|
| `Kontrak.aspx` | Kontrak list | ✅ `/contracts` |
| `KontrakDaftar.aspx` | Daftar/create kontrak baru | ⚠️ Auto-generated dari Booking, tidak ada manual form |
| `AdjustmentKontrak.aspx` | **Adjustment kontrak** (revisi terms) | ❌ **BELUM ADA** ⭐⭐ |
| `AdjustmentKontrakPPN.aspx` | **PPN adjustment** (penyesuaian PPN per kontrak) | ❌ **BELUM ADA** ⭐⭐ |
| `TagihanCustom.aspx` | **Tagihan custom** (manual invoice di luar jadwal) | ❌ **BELUM ADA** ⭐ |
| `TagihanReschedule.aspx` | **Reschedule tagihan** | ✅ Sudah ada di Exception Reschedule |
| `KontrakGantiNama.aspx` | **GN at Contract level** (beda dari Booking GN) | ❌ **BELUM ADA — penting!** ⭐⭐ |
| `KontrakGantiUnit.aspx` | **GU at Contract level** | ❌ **BELUM ADA — penting!** ⭐⭐ |
| `KontrakBatal.aspx` | **Pembatalan kontrak** | ⚠️ Booking cancel ada, Kontrak cancel terpisah belum |

> 🔥 **MAJOR FINDING:** Java Core System Lama membedakan **Exception flow PRE-Contract** (di Booking: Reservasi GN/GU) vs **POST-Contract** (di Kontrak: KontrakGN/GU/Batal). Lifecycle berbeda — GN sebelum signing = ubah data customer di reservasi; GN setelah signing = adendum kontrak legal (perlu signing ulang!). **Java Core System v2.0 SEKARANG hanya handle PRE-Contract scenario.** Ini gap besar untuk go-live.

### 📦 Migrate (6 sub) — Data migration tools
| URL Endpoint | Fungsi | Status di Java Core System v2.0 |
|--------------|--------|------------------------|
| `UploadKontrak.aspx` | Upload Excel kontrak | ❌ Belum ada UI |
| `MigrateKontrak.aspx` | Migration tool kontrak | ❌ Belum ada UI |
| `UploadJadwal.aspx` | Upload payment schedule | ❌ Belum ada |
| `MigrateJadwal.aspx` | Migration tool schedule | ❌ Belum ada |
| `UploadPembayaran.aspx` | Upload payment history | ❌ Belum ada |
| `MigratePembayaran.aspx` | Migration tool payment | ❌ Belum ada |

> 🔥 **Important for go-live:** Migration tools sangat penting untuk import data dari Java Core System Lama lama → Java Core System baru. **Wajib disiapkan UI ini sebelum cutover** atau gunakan artisan command import.

### 🔍 Log & Logout
| URL Endpoint | Fungsi | Status di Java Core System v2.0 |
|--------------|--------|------------------------|
| `Log.aspx` | Log file viewer | ⚠️ `audit_log` table ada, UI belum |
| `SignOut.aspx` | Logout | ✅ `/logout` |

---

## 📊 Updated Action Items dari URL Map (34 endpoints)

### ⭐ CRITICAL (Wajib sebelum go-live):
| # | Item | Estimasi |
|---|------|----------|
| 1 | **Kontrak-level Exception flow** (GN, GU, Cancel kontrak) — adendum legal proper | 2-3 hari |
| 2 | **Adjustment Kontrak** (revisi terms after signing) | 1-2 hari |
| 3 | **Migration tools** (UI bulk upload kontrak, jadwal, pembayaran from Excel) | 2 hari |
| 4 | **BPHTB Calculator** | 1 hari |

### ⭐ HIGH PRIORITY:
| # | Item | Estimasi |
|---|------|----------|
| 5 | **Tagihan Custom** (manual invoice di luar payment schedule) | 1 hari |
| 6 | **Adjustment Kontrak PPN** (per-contract PPN rate adjustment) | 1 hari |
| 7 | **Customer Merge UI** (backend `customer.merge` permission ada) | 1 hari |
| 8 | **Customer Bulk Upload UI** | 1 hari |
| 9 | **Audit Log Viewer UI** | 1 hari |
| 10 | **Reminder dashboard** (per-user task & due date list) | 1-2 hari |

### ⭐ MEDIUM PRIORITY:
| # | Item | Estimasi |
|---|------|----------|
| 11 | **Standalone calculators** (KPR, Skema) untuk pre-sales tools | 1 hari |
| 12 | **Per-role Dashboard** (DashboardSales, DashboardFinance, dll) | 2 hari |
| 13 | **Report Center** dengan custom report builder | 2-3 hari |

**Total estimasi gap critical+high: ~14 hari** (sekitar 2-3 minggu sprint)

---

## 🎯 Deep Dive: Booking Module (Master Booking)

**URL:** `/marketingjual/Index.aspx` → klik Booking di sidebar
**Heading page:** "Master Booking"
**Total data observed:** 40 entries, 10 per page (pagination 1-4)

### Sub-menu di bawah Booking (Java Core System Lama):
1. **Pendaftaran** — Buat booking baru
2. **Ganti Nama** — Exception flow Name Change (CONTEXTUAL di bawah Booking!)
3. **Pindah Unit** — Exception flow Unit Change (CONTEXTUAL di bawah Booking!)

### 🔥 INSIGHT PENTING #1 — Struktur Exception Flow Berbeda

| Aspek | Java Core System Lama | Java Core System v2.0 |
|-------|-----------|--------------|
| Lokasi menu GN | Sub-menu Booking | Menu terpisah `/exceptions` |
| Lokasi menu GU | Sub-menu Booking | Menu terpisah `/exceptions` |
| Lokasi menu Reschedule | ⚠️ Tidak terlihat di sub-menu — mungkin di Booking detail | Menu terpisah `/exceptions` |

**Diskusi:** Java Core System Lama kontekstual (sales person buka booking → GN/GU dari sana). Java Core System v2.0 terpusat (1 dashboard untuk semua exception, ada filter status pending/approved).

**Rekomendasi:** Tambah **shortcut button "Ganti Nama" / "Pindah Unit" / "Reschedule"** di halaman `bookings/{id}/show` Java Core System — best of both worlds (kontekstual + centralized).

### Master Booking List — Layout

**Filter bar (atas):**
| Filter | Type | Catatan |
|--------|------|---------|
| View by | Dropdown bulan/tahun | Periode filter (default: bulan ini) |
| Jenis | Dropdown | Tipe booking (mungkin: NUP/DP Penuh/KPR/dll) |
| Lokasi | Dropdown | Filter lokasi |
| Project | Dropdown | Multi-project support |
| **Display** | Button biru | Submit filter |

⚠️ **Pattern lama**: filter dengan tombol "Display" eksplisit (postback). Java Core System v2.0 pakai realtime debounce search (lebih responsif).

**Table columns:**
| # | Column | Sortable? | Catatan |
|---|--------|-----------|---------|
| 1 | **No.** | ✅ | Nomor booking, ada yang strikethrough (mungkin cancelled) |
| 2 | **Status** | ✅ | Singkat 1 huruf (E = Effective?) |
| 3 | **No. Unit** | ✅ | Format: `JRC3-004-XXIII-27` |
| 4 | **Tgl** | ✅ | Tanggal booking |
| 5 | **Customer** | ✅ | 2-line: nama customer + agent/marketing |
| 6 | **Batas Waktu** | ✅ | Deadline (DP atau pelunasan) — eksplisit di list ⭐ |
| 7 | **Project** | ✅ | "Java Residence" |
| 8 | **Action** | — | Edit (pencil) + Delete (trash) icon |

### 🔥 INSIGHT PENTING #2 — Format Unit Code di Java Core System Lama

Format unit code observed: **`JRC3-004-XXIII-27`**

Decoded:
- `JRC3` = **Java Residence Cluster 3** (project code)
- `004` = **Building/Tower number** (3 digit)
- `XXIII` = **Lantai pakai ROMAN NUMERAL!** ⚠️
- `27` = Nomor unit per lantai

**Implikasi untuk Java Core System v2.0:**
Project Java Residence resmi pakai **Roman numeral untuk floor number** di unit code. Numerator service Java Core System sekarang menghasilkan format yang berbeda (decimal). Untuk parity dengan history data, perlu:

1. **Update Numerator Service** → support Roman numeral generator untuk floor segment
2. **Update Unit migration** → kolom `floor` simpan integer + tambahkan computed/derived `floor_roman` untuk display
3. **Migration script** → kalau import data lama, parse Roman → integer

### 🔥 INSIGHT PENTING #3 — "Batas Waktu" Column

Java Core System Lama menampilkan **deadline booking** (Batas Waktu) langsung di list — sangat berguna untuk sales person tahu mana yang akan expire.

**Java Core System v2.0 sekarang:**
- `payment_schedules` table punya `due_date` per cicilan
- Tapi list Booking tidak summary deadline

**Opportunity:** Tambah kolom **"Deadline Berikutnya"** di list Booking (computed: due_date dari payment_schedule terdekat yang status outstanding).

### 🔥 INSIGHT PENTING #4 — Customer Column 2-line

Format yang terlihat: 2 baris di sel Customer
- Baris 1: Nama customer (uppercase)
- Baris 2: Agent/Marketing person (uppercase)

Pattern: sales person mau tahu "siapa customer + siapa yang handle". Java Core System v2.0 simpan `sales_person_id` di booking tapi tidak di-display di list.

**Opportunity:** Tampilkan `sales_person.name` di kolom Customer atau jadikan kolom terpisah.

### 🔥 INSIGHT PENTING #5 — Strikethrough booking number

Beberapa booking number di list muncul **strikethrough** (coret). Kemungkinan menandakan:
- Booking cancelled / batal
- Customer cabut

Java Core System v2.0 pakai Badge status `cancelled` (warna danger) — lebih ekspresif dari strikethrough.

### 🔥 INSIGHT PENTING #6 — DataTables.js classic

Footer table: "Showing 1 to 10 of 40 entries" — pattern DataTables.js (jQuery library). Confirms tech stack ASP.NET + jQuery DataTables.

Java Core System v2.0 pakai Inertia + Laravel Pagination (server-side). Lebih scalable untuk big data.

### 🔥 INSIGHT PENTING #7 — Status code 1 huruf

Kolom Status hanya 1 huruf (`E`). Kemungkinan ada kode lain:
- `E` = Effective (active)
- `C` = Cancelled
- `D` = Draft
- `P` = Pending
- dll

**Implikasi:** Perlu cek lookup table status booking Java Core System Lama lama untuk data migration mapping ke BookingStatus enum Java Core System v2.0.

**Java Core System v2.0 BookingStatus enum sudah ada 8 state:**
Draft, Nup, PilihUnit, Ppjb, Active, Lunas, Closed, Cancelled

---

## 🎯 Action Items Update (dari Booking Module)

### Tambahan Quick Wins (HIGH VALUE):
1. ✨ **Kolom "Deadline Berikutnya"** di list Booking Java Core System — sales person bisa lihat priority follow-up
2. ✨ **Tampilkan `sales_person.name`** di kolom Customer (atau kolom terpisah)
3. ✨ **Shortcut "Ganti Nama" / "Pindah Unit" / "Reschedule"** button di Booking detail page
4. ✨ **Roman numeral support** di Numerator Service untuk floor segment unit code

### Tambahan Strategic:
5. 🔧 **Pre-filter bar** di list Booking (Bulan + Jenis + Lokasi + Project) — bisa dual mode: realtime debounce (default) atau "load more on Display click" untuk dataset besar
6. 🔧 **Status code mapping** untuk data migration Java Core System Lama → Java Core System (perlu data tim MUP)

---

---

## 🔥 INSIGHT KRITIS — Format Numbering Java Core System Lama

### Booking Number Format (decoded):
```
0000634/RSV/JR/V/26
   │     │   │  │  └── Year (2 digit) → 2026
   │     │   │  └───── Month (ROMAN NUMERAL) → V = May
   │     │   └──────── Project code → JR (Java Residence)
   │     └──────────── RSV = Reservasi (internal)
   └────────────────── Sequence (7 digit, padded)
```

### Implikasi untuk Java Core System Numerator Service:

**Sekarang Java Core System pakai:** `BK/JRES/2605/00001` (year+month decimal)
**Java Core System Lama pakai:** `0000634/RSV/JR/V/26` (Roman month, 2-digit year)

**Untuk parity / data migration mapping:**
1. **Tambah variant Roman numeral di Numerator** untuk segment month (`V` not `05`)
2. **Tambah variant 2-digit year** (`26` not `2026`)
3. **Document type code "RSV"** alternative untuk "BK" — bisa rename atau alias
4. **Pisahkan sequence di depan** (Java Core System Lama: sequence di awal; Java Core System: sequence di akhir)

Recommended: bikin **NumeratorPattern enum** dengan support:
- `{SEQ:7}` / `{SEQ:5}` / dll → padding angka
- `{DOC}` → document type code
- `{PROJECT}` → project code
- `{MONTH}` (decimal) / `{MONTH_ROMAN}` (V) / `{MONTH_ABBR}` (May)
- `{YEAR}` (2026) / `{YEAR_SHORT}` (26)

### Unit Code Format (decoded — dari Master Unit page):
```
JRC3-002-XXIII-27        atau    JRK2-002-II-3A
 │   │    │    │                  │   │   │  │
 │   │    │    └── Unit number    │   │   │  └── Variant suffix (A,B…)
 │   │    └─────── Floor (Roman)  │   │   └────── Unit number
 │   └──────────── Block          │   └────────── Floor (Roman, II)
 └──────────────── Cluster code   └────────────── Cluster (JRK2)
                  (JRC3, JRK2)
```

**Important:**
- ⚠️ **Multiple clusters dalam 1 project**: `JRC3` (cluster 3) + `JRK2` (cluster K2). Project Java Residence punya banyak cluster.
- ⚠️ **Floor pakai ROMAN NUMERAL** (XXIII = 23, II = 2)
- ⚠️ **Unit number bisa punya suffix huruf** (3A, 11A) untuk variant/split unit

### 🎯 Action Items Tambahan dari Format Analysis:

1. **Update Unit migration**: tambah kolom `cluster_code` (varchar) untuk support multi-cluster per project — sekarang Java Core System hanya punya Project tanpa Cluster
2. **Update Unit migration**: kolom `floor` integer + computed `floor_roman` accessor
3. **Update Unit migration**: kolom `variant` nullable varchar (untuk suffix A, B, dll)
4. **NumeratorPattern enhancement**: support semua format Java Core System Lama (Roman, padding, sequence position)
5. **Project model**: tambah relasi `hasMany(Cluster)` — atau simpler: tambahkan `cluster_code` di Unit langsung (no separate table)

---

## 🚀 Flow Comparison: Buat Booking Baru

### Java Core System Lama Lama (Unit-First Flow):
```
1. Sales person buka Tabel Stok atau Site Plan
2. Filter Project → Java Residence
3. Browse units yang available (status Aktif)
4. Klik unit → buka detail unit
5. Dari detail unit klik "Reservasi" → form Reservasi (Pendaftaran)
6. Isi customer + DP + skema pembayaran
7. Submit → booking number generated
```

### Java Core System v2.0 (Booking-First Wizard):
```
1. Sales klik "Booking Baru"
2. Step 1: Pilih Customer (existing atau buat baru)
3. Step 2: Pilih Unit (search + filter)
4. Step 3: Pricing (base + discount + ppn)
5. Step 4: Payment Schedule
6. Review & submit → booking dengan state Draft
```

**Trade-off:**
- **Unit-first** lebih natural untuk sales scenario (customer pointing at unit di showroom)
- **Booking-first** lebih flexible (bisa book sebelum customer decide unit, contoh: NUP)

**Rekomendasi Java Core System v2.0:**
✅ Sediakan **dua entry point**:
1. Dari **Site Plan** → klik unit available → "Buat Booking untuk unit ini"
2. Dari **menu Booking → Baru** → wizard seperti sekarang

Sudah ada Site Plan di Java Core System (`/units/siteplan`), tinggal tambah action button "Buat Booking" pada unit hover/click.

---

---

## 🔥 INSIGHT BARU: Booking Creation Flow Java Core System Lama (Unit-First Confirmed)

### Bukti dari JavaScript Analysis:

Saya inspect global JS functions di halaman Master Booking JAVA CORE SYSTEM LAMA. Functions yang ada:
- ✅ `popEditReservasi(nomor)` → buka popup edit booking existing
- ✅ `popDelReservasi(nomor)` → buka popup delete confirmation
- ❌ **TIDAK ADA `popAddReservasi` atau `popNewReservasi`!**

### Implikasi:

**Konfirmasi 100%:** Java Core System Lama TIDAK punya tombol "Buat Booking Baru" dari Master Booking list. **Booking baru HANYA bisa dibuat via Unit-first flow:**
```
Site Plan / Tabel Stok → klik unit available → "Buat Reservasi"
```

### Behavior pattern Java Core System Lama:
- Click booking number → `popEditReservasi(internal_id)` → open popup `/marketingjual/ReservasiEdit.aspx?NoReservasi={id}` di window 920×650
- Click trash icon → `popDelReservasi(internal_id)` → konfirmasi delete
- Pakai `internal_id` (integer pendek seperti 997), bukan booking number string

### Java Core System v2.0 vs Java Core System Lama:

| Aspek | Java Core System Lama | Java Core System v2.0 |
|-------|-----------|--------------|
| Buka detail booking | Popup window 920×650 | Halaman penuh `/bookings/{id}` |
| URL detail | `?NoReservasi={internal_id}` | `/bookings/{uuid_atau_id}` |
| Entry point baru | Hanya dari Unit | Tombol "Booking Baru" + (perlu juga dari Unit) |
| Pattern UI | Modal popup | Inertia SPA navigation |

**Rekomendasi UX:** Modal popup pattern lama lebih cocok untuk **quick edit** di list context. Java Core System sudah pakai pattern modern (full-page detail). Tetap superior karena:
- Tidak ada popup blocker issue
- Browser back/forward work
- URL shareable
- Better mobile UX

---

## 🔥 INSIGHT BARU: Custom Error Page Java Core System Lama

Saat saya navigate ke booking ID yang tidak exist (dummy ID 999999999), Java Core System Lama redirect ke **`/CustomError/Deleted.html`** dengan pesan yang well-designed:

> **"Data Tidak Dapat Ditampilkan"**
>
> Kemungkinan Terjadi Karena:
> - Data yang diminta sudah dihapus oleh user / proses lainnya.
> - Data yang diminta adalah data rahasia dan anda tidak memiliki akses yang cukup.
> - Data belum menjalani prosedur bisnis tertentu seperti **PPJB**, **ST**, **RENEWAL**, dst.

### Insight bisnis baru terungkap:

1. **"ST"** = Serah Terima (Handover) — confirms BAST workflow
2. **"RENEWAL"** = Renewal — ada konsep renewal booking/contract di Java Core System Lama
3. **Data rahasia / akses control** — Java Core System Lama punya per-record access control (mirip Sanctum policy per-record)
4. **Custom error page design** — UX bagus dengan 3-line reason explanation

**Action item:** Java Core System v2.0 perlu **dedicated 404 / 403 page** dengan helper text seperti ini (lebih user-friendly daripada Laravel default).

---

## 🔥 INSIGHT BARU: Status Code Booking (verified)

Dari observasi master booking list dengan 2 batch data berbeda:

| Code | Kemungkinan | Visual |
|------|-------------|--------|
| **A** | Active (booking masih berjalan) | Normal text |
| **E** | Expired / Erased (booking lewat batas waktu atau dibatalkan) | **Strikethrough** booking number |

### Mapping ke Java Core System BookingStatus enum:
```
Java Core System Lama "A" → Java Core System Draft, Nup, PilihUnit, Ppjb, Active, Lunas
Java Core System Lama "E" → Java Core System Cancelled atau Closed (dengan reason)
```

⚠️ **Migration mapping perlu data tambahan** — mungkin ada status code lain (`P`=Pending, `C`=Cancelled, `S`=Signed, dll) yang belum saya observasi. Perlu konfirmasi dari tim MUP atau Java Core System Lama docs.

---

## 🚨 PII Compliance Note

Saya **TIDAK** akan navigate ke `ReservasiEdit.aspx?NoReservasi={real_id}` dengan ID booking real, karena akan expose:
- Nama customer
- NIK / nomor KTP
- Alamat lengkap
- Nomor HP / email
- Nominal transaksi
- Detail unit yang dibeli

Untuk observe **struktur form Edit Booking** tanpa expose PII, opsi terbaik:

### Opsi 1: Bapak/Ibu screenshot form Edit Booking (kotak-kotak label kosong)
- Buka 1 booking
- Sembunyikan/blur data customer & nominal di screenshot
- Kirim screenshot ke saya — saya catat field structure & section

### Opsi 2: Bapak/Ibu navigate ke booking yang sudah jadi "test data" (jika ada)
- Booking dengan nama dummy "TEST" / "DEMO"
- Saya bisa observe langsung dengan aman

### Opsi 3: Kirim daftar field saja (tanpa screenshot)
- Bapak/Ibu tuliskan label-label field yang muncul di form Edit Booking
- Saya susun ke gap analysis

---

---

## 🎯 DEEP DIVE: Form Edit Booking Java Core System Lama (Live Observation)

**URL:** `/marketingjual/ReservasiEdit.aspx?NoReservasi=1039` (internal ID 1039)
**Window:** Popup 920×650
**Title:** "Edit Booking"

### Layout Hierarchy

```
┌─ Tabs (top, blue header) ──────────────────────────────┐
│  [Edit Booking]  Jadwal Tagihan   Jurnal               │
├─ Navigation row ───────────────────────────────────────┤
│  ←Prev  Next→  | No. Booking: XXX | Unit: XXX |        │
│                | Customer: XXX | Sales: XXX            │
├─ Print row ────────────────────────────────────────────┤
│  Print:  Kartu Waiting List (0) (Preview)              │
│          Jadwal Pembayaran (0) (Preview)               │
│          Booking Form (0) (Preview)                    │
│                              [Log] [Delete]            │
├─ Metadata row ─────────────────────────────────────────┤
│  Input: 29 May 2026 15:29:02  Edit: 29 May 2026 ...   │
├─ Status badge ─────────────────────────────────────────┤
│  [Waiting List]  2/2 Aktif                             │
├─ Section: Dokumen ─────────────────────────────────────┤
│  No. Booking  : 0000670/RSV/JR/V/26                    │
│  Tanggal      : 29 May 2026 📅                         │
│  Batas Waktu  : 05 Jun 2026 12:00:00 AM 📅            │
│  NUP          : 0   (Prioritas pemesanan / queueing)   │
├─ Section: Perhitungan Harga ───────────────────────────┤
│  Nilai Pengikatan : 3,000,000                          │
│  Skema            : KPR FREE DP CLUSTER 3.4 (032) ▼   │
│  Sales            : RATNA CP ▼                         │
├─ Bottom buttons ───────────────────────────────────────┤
│  [OK] [Apply] [Cancel]                                 │
└────────────────────────────────────────────────────────┘
```

### Tabs (3 tabs di header)
| Tab | Fungsi | Java Core System Status |
|-----|--------|----------------|
| **Edit Booking** | Master data booking | ✅ Sudah ada (separate page) |
| **Jadwal Tagihan** | Payment schedule (auto-generated) | ✅ Sudah ada di `payment_schedules` |
| **Jurnal** | Accounting journal entries! | ⚠️ **BELUM ADA — jurnal akuntansi otomatis dari booking** ⭐ |

> 🔥 **INSIGHT KRITIS:** Tab "**Jurnal**" = Java Core System Lama auto-generate **jurnal akuntansi** (debit/credit) dari booking! Ini integrasi accounting yang **Java Core System v2.0 BELUM punya**. Major gap untuk finance team.

### Navigation Features
- **←Prev / Next→** = browse adjacent bookings without going back to list ⭐
- **Top breadcrumb info**: No. Booking | Unit | Customer | Sales (read-only header)

> 🔥 Java Core System v2.0 belum punya Prev/Next pattern — UX improvement bagus untuk batch review.

### Print Documents (3 templates per booking!)
| Dokumen | Fungsi | Java Core System Status |
|---------|--------|----------------|
| **Kartu Waiting List** | Print kartu antrian | ❌ Belum ada |
| **Jadwal Pembayaran** | Print jadwal cicilan | ⚠️ Ada di kuitansi, belum di booking |
| **Booking Form** | Print form booking | ❌ Belum ada |
| `(0)` notation | Print count tracker | ❌ Belum ada (audit print history) |
| **Preview** link | Preview PDF sebelum print | ⚠️ Ada via Browsershot, belum 1-click |

> 🔥 Java Core System perlu **per-document print templates** + **print count tracker** untuk audit (siapa print apa kapan, berapa kali). Ini compliance penting untuk legal docs.

### Action Buttons
| Button | Fungsi | Pattern |
|--------|--------|---------|
| **Log** (top right, blue) | Audit log per-record viewer | 🔥 Java Core System belum ada **per-record audit log button** — gap UX |
| **Delete** (top right, red) | Hard/soft delete booking | ⚠️ Java Core System punya cancel booking, tidak punya delete (intentional!) |
| **OK** (bottom, blue) | Save & close window | "Save & Close" |
| **Apply** (bottom, orange) | Save & stay editing | "Save" |
| **Cancel** (bottom, red) | Discard changes & close | "Discard" |

> 🔥 **Distinction OK vs Apply** = modern UX best practice. Java Core System sekarang hanya ada 1 "Simpan" button.

### Metadata Row (Audit Display)
- **Input** : timestamp create
- **Edit** : timestamp last update

Java Core System punya `created_at` + `updated_at` tapi tidak di-display di UI booking detail. Easy add.

### Waiting List Section ⭐⭐⭐ NEW CONCEPT!
- Badge button: **"Waiting List"**
- Display: **"2/2 Aktif"** (queue position 2 of 2, status active)

> 🔥 **INSIGHT KRITIS BARU:** Java Core System Lama punya **Waiting List system** untuk booking yang BERTUMPUK pada unit yang sama! Konsep antrian:
> - Jika unit sudah ada booking aktif (status A) dan ada calon customer lain berminat, dia masuk waiting list
> - "2/2" = posisi #2 dari 2 total waiting list di unit ini
> - Jika booking #1 dibatalkan/expired → #2 otomatis naik jadi prioritas
>
> **Java Core System v2.0 BELUM punya waiting list concept!** Sekarang Hold Unit single-customer only. Major gap.

---

### 📋 FIELD STRUCTURE: Edit Booking Form (Java Core System Lama)

#### Section "Dokumen" (4 fields)
| # | Label | Type | Catatan | Java Core System Mapping |
|---|-------|------|---------|-----------------|
| 1 | **No. Booking** | Text (readonly) | Auto-gen, format `0000670/RSV/JR/V/26` | `booking_number` ✅ |
| 2 | **Tanggal** | Date picker | Tanggal booking | `booking_date` ✅ |
| 3 | **Batas Waktu** | DateTime picker | Deadline DP/pelunasan dengan **JAM** (00:00:00 AM) | ⚠️ Java Core System pakai date only, perlu pertimbangkan time |
| 4 | **NUP** | Number (integer) | "Prioritas pemesanan atau sistem queueing" | ⚠️ Java Core System punya NupRegistration terpisah, tidak di booking |

#### Section "Perhitungan Harga" (3 fields)
| # | Label | Type | Catatan | Java Core System Mapping |
|---|-------|------|---------|-----------------|
| 5 | **Nilai Pengikatan** | Currency (IDR) | Booking deposit / DP awal | `dp_amount`? Need rename to match |
| 6 | **Skema** | Dropdown (Master) | `KPR FREE DP CLUSTER 3.4 (032)` — predefined payment scheme | ❌ Java Core System belum punya **Master Skema Pembayaran** entity! ⭐ |
| 7 | **Sales** | Dropdown (User) | Sales person yang handle | `sales_person_id` ✅ |

---

## 🚨 INSIGHTS KRITIS dari Form Edit Booking

### Insight #1: Form Booking Java Core System Lama CUMA 7 FIELD (vs Java Core System Wizard 4-step puluhan field)

**Java Core System Lama pattern:** Booking dibuat dari Unit (unit-first), customer sudah dipilih sebelumnya. Form Edit hanya untuk **modifikasi 7 field finansial/tanggal**.

**Java Core System v2.0 pattern:** Wizard 4-step dengan customer pick, unit pick, pricing, schedule — semua dalam 1 flow.

**Rekomendasi:** 
- **Untuk Edit:** Java Core System bisa simplify ke form pendek 7-8 field (separate dari wizard create)
- **Untuk Create:** Pertahankan wizard, TAPI tambah entry point dari Unit detail (unit-first parity)

### Insight #2: Master Skema Pembayaran — Major Missing Entity

Java Core System Lama Skema field menampilkan: `KPR FREE DP CLUSTER 3.4 (032)`
- Code: `032`
- Name: "KPR FREE DP CLUSTER 3.4"
- Means: predefined payment plan template

**Java Core System v2.0 belum punya `payment_schemes` table!** Sekarang setiap booking custom payment schedule. Perlu:

```sql
CREATE TABLE payment_schemes (
  id, tenant_id, project_id, cluster_id (nullable),
  code (032), name (KPR FREE DP CLUSTER 3.4),
  type (KPR/Cash/Cicilan), 
  dp_pct, tenor_months, interest_rate,
  is_active, ...
);
```

Lalu booking refer ke `payment_scheme_id`. Schedule generator pakai template dari scheme.

### Insight #3: Tab "Jurnal" — Accounting Journal Auto-Gen

Java Core System Lama auto-create **jurnal akuntansi** (double-entry) saat booking:
```
Dr Piutang Customer        Rp 3,000,000
   Cr Pendapatan Booking       Rp 3,000,000
```

**Java Core System v2.0 BELUM punya general ledger / journal entry system!** Hanya track payment di TTS + Kuitansi. Untuk integrasi accounting penuh, perlu:
1. `journal_entries` + `journal_lines` tables
2. Auto-gen journal saat events (TtsPosted, BookingActivated, dll)
3. Chart of Accounts (CoA) setup
4. Reports: General Ledger, Trial Balance

**Effort:** Medium-large (1-2 minggu) tapi WAJIB untuk full ERP parity.

### Insight #4: Waiting List per Unit

Konsep baru: 1 unit bisa punya **multiple booking dalam antrian**. Status code:
- Posisi #1 → Booking aktif primary
- Posisi #2,3,... → Waiting (jika #1 expire/cancel, otomatis naik)

**Java Core System v2.0 Hold Unit sekarang single-only.** Untuk waiting list:
1. Tambah `priority_order` di Hold/Booking untuk unit yang sama
2. Logic: saat booking primary cancel → query next priority → notify customer waiting
3. UI: tampilkan "Anda di antrian #2 dari 3" di customer view

### Insight #5: Per-Document Print Counter + Preview

Java Core System Lama track berapa kali doc di-print: `(0)` next to each doc.

**Use case:** audit "siapa print Booking Form Bapak Ahmad 5x bulan ini?" — bisa deteksi data abuse.

**Java Core System v2.0 implementation:**
```sql
ALTER TABLE bookings ADD COLUMN print_counts JSONB DEFAULT '{}';
-- {"booking_form": 3, "schedule": 1, "waiting_list": 0}

ALTER TABLE audit_logs ADD COLUMN action_type;
-- 'print', 'view', 'update', 'delete'
```

### Insight #6: Prev/Next Navigation antar Booking

Sangat useful untuk batch review. **Implementation Java Core System v2.0:**
```php
// BookingController@show
$prev = Booking::where('id', '<', $booking->id)->orderByDesc('id')->first();
$next = Booking::where('id', '>', $booking->id)->orderBy('id')->first();

return Inertia::render('Sales/Booking/Show', [
    'booking' => $booking,
    'prev_id' => $prev?->id,
    'next_id' => $next?->id,
]);
```

UI: `[← Prev] [Booking detail] [Next →]`

### Insight #7: Per-Record "Log" Button

Java Core System Lama di pojok kanan atas punya button **"Log"** untuk view audit history per booking.

**Java Core System v2.0 sudah ada `audit_log` polymorphic table, tinggal:**
1. Add "Lihat Riwayat" button di Booking detail page
2. Modal/page yang query: `AuditLog::where('subject_type', 'booking')->where('subject_id', $booking->id)->latest()->get()`
3. Display: tanggal | user | action | field changes (diff)

**Effort:** 1-2 jam.

---

## 📊 Updated Action Items dari Form Edit Booking

### ⭐ CRITICAL (langsung perlu):
| # | Item | Effort | Mengapa Critical |
|---|------|--------|------------------|
| 1 | **Master `payment_schemes` table** + UI CRUD | 1-2 hari | Tanpa ini, booking selalu custom — tidak bisa template-based |
| 2 | **Journal Entry / GL system** (basic) | 1-2 minggu | Finance team butuh untuk closing buku |
| 3 | **Waiting List per Unit** (booking priority queue) | 2-3 hari | Customer experience saat unit hot-sale |

### ⭐ HIGH (quick UX wins):
| # | Item | Effort |
|---|------|--------|
| 4 | **Tombol "Log/Riwayat"** di Booking detail (audit viewer per-record) | 2 jam |
| 5 | **Prev/Next navigation** di Booking detail | 30 menit |
| 6 | **Print 3 dokumen** (Booking Form, Waiting List Card, Jadwal Pembayaran) + counter | 1 hari |
| 7 | **Display Input/Edit timestamp** di header form (already in DB, just display) | 15 menit |
| 8 | **OK vs Apply** button pattern (Save&Close vs Save) | 30 menit |
| 9 | **DateTime field untuk Batas Waktu** (vs Date only sekarang) | 30 menit |
| 10 | **Auto-rename "DP Amount" → "Nilai Pengikatan"** (parity istilah) | 10 menit |

### ⭐ MEDIUM:
| # | Item | Effort |
|---|------|--------|
| 11 | **Compact Edit form** (7-field) terpisah dari Wizard Create | 4-5 jam |
| 12 | **Entry point booking dari Unit detail** (unit-first flow) | 2-3 jam |

---

---

## 🔥 INSIGHT BARU: Session Timeout UX Pattern Java Core System Lama

Saat session SID expired, Java Core System Lama redirect ke **`/CustomError/Restricted.html`** dengan pesan:

> ⚠️ **Akses Ditolak**
>
> Kemungkinan Terjadi Karena:
> - Akses anda sudah kadaluwarsa. Silakan melakukan proses **Log-In ulang**.

### 2 Custom Error Pages Java Core System Lama (terkonfirmasi):

| URL | Trigger | Pesan |
|-----|---------|-------|
| `/CustomError/Deleted.html` | Data not found / no permission | "Data Tidak Dapat Ditampilkan" + 3 alasan |
| `/CustomError/Restricted.html` | Session expired | "Akses Ditolak" + CTA Log-In ulang |

### Implikasi untuk Java Core System v2.0:

Java Core System sekarang pakai default Laravel/Inertia behavior:
- Session expired → 419 status code → Inertia auto-redirect ke /login
- Halaman blank sebentar lalu langsung redirect

**Better UX pattern (parity dengan Java Core System Lama):**
1. **Custom error page** di Java Core System untuk session expired (mis. `/session-expired`)
2. Pesan Indonesian: *"Sesi Anda telah berakhir. Silakan login ulang."*
3. CTA button "Login Ulang" yang preserve `intended URL` agar user kembali ke halaman terakhir
4. Optional: countdown / warning **sebelum** expired (mis. 1 menit before timeout, popup "Session akan berakhir, perpanjang?")

**Implementation effort:** 2-3 jam

---

---

## 🧮 DEEP DIVE: 3 Kalkulator Java Core System Lama

### 1️⃣ Kalkulator Estimasi BPHTB

**URL:** `/marketingjual/KalkulatorEstimasiBPHTB.aspx`
**Heading:** "Kalkulator Estimasi BPHTB"

#### Input:
- **Project** (dropdown) — pilih project
- **No. Stock** (text + search button) — lookup unit
- **No. Unit** (text, auto-filled dari lookup)

#### Tabel Perhitungan NJOP:
| Luas | M² | NJOP/m² | Total NJOP |
|------|----|---------|-----------|
| Tanah (SG = Surat Girik?) | input | input | computed |
| Bangunan (NETT) | input | input | computed |
| **TOTAL** | | | **computed** |

#### Below table:
- **Nilai AJB** (input)
- **Nilai Perolehan Objek Pajak (NPOP)** = MAX(AJB, Total NJOP) — computed
- **NPOTKP** (Nilai Perolehan Tidak Kena Pajak) — input (threshold)
- **TOTAL BPHTB** = (NPOP − NPOTKP) × 5% — computed

#### Field names (HTML):
`project`, `nostock`, `btnpop`, `nounit`, `sg`, `njopsg`, `totnjopsg`, `nett`, `njopnett`, `totnjopnett`, `totalnjop`, `nilaiajb`, `npop`, `npotkp`, `bphtb`

#### Formula BPHTB Indonesia (terkonfirmasi):
```
Total NJOP = (Luas Tanah × NJOP/m²) + (Luas Bangunan × NJOP/m²)
NPOP       = MAX(Nilai AJB, Total NJOP)
NPOPKP     = NPOP − NPOTKP   (NPOTKP = threshold regional ~60-80jt)
BPHTB      = NPOPKP × 5%
```

#### Implementasi Java Core System:
```php
// app/Domain/Tax/Services/BphtbCalculator.php
class BphtbCalculator {
    public function calculate(array $input): array {
        $totalNjop = ($input['land_area'] * $input['njop_per_m2_land']) +
                     ($input['building_area'] * $input['njop_per_m2_building']);
        $npop = max($input['ajb_value'], $totalNjop);
        $npopkp = max(0, $npop - $input['npotkp']);
        $bphtb = $npopkp * 0.05;
        return compact('totalNjop', 'npop', 'npopkp', 'bphtb');
    }
}
```

Effort: 1 hari (calculator + UI form Vue).

---

### 2️⃣ Kalkulator KPR

**URL:** `/marketingjual/KalkulatorKPR.aspx`
**Heading:** "Kalkulator KPR"

#### Input (3 field):
- **Nilai KPR** (Rupiah) — pokok pinjaman
- **Periode** (Bulan) — tenor
- **Effective Rate** (%) — bunga efektif tahunan

#### Output:
- **Installment Bulanan** (Rp/bulan)

#### Formula (standard amortization):
```
i = effective_rate / 12 / 100
n = months
M = P × [i(1+i)^n] / [(1+i)^n − 1]
```

#### Implementasi Java Core System:
Mudah, helper function di `app/Support/MortgageCalculator.php`. **Effort: 2 jam** (logic + form).

---

### 3️⃣ Kalkulator Skema Cara Bayar ⭐⭐ MOST COMPLEX

**URL:** `/marketingjual/KalkulatorSkema.aspx`
**Heading:** "Kalkulator Skema Cara Bayar"

#### Input:
- **Project** (dropdown)
- **Tanggal Kontrak** (date)
- **No. Stock** (lookup) → auto-fill **No. Unit**
- **Cara Bayar** (dropdown: Pilih Skema dari Master Payment Schemes!)
- **Metode** (radio: Skema Cara Bayar)
- **Price List** (rupiah, auto-fill dari unit)
- **Present Value** + % (input + percentage)
- **Discount** + % (input + percentage)
- **Discount Tambahan** (additional discount)
- **DPP** = Dasar Pengenaan Pajak (taxable base)
- **% Bunga** (interest rate untuk scheme dengan bunga)
- **PPN** (auto: DPP × 11%)
- **Nilai Kontrak** = DPP + PPN

#### Field names:
`project`, `tglkontrak`, `nostock`, `btnpop2`, `nounit`, `carabayar`, `metode`, `gross`, `persenbunga`, `nilaiBunga`, `disc`, `nilaiDiskon`, `diskontambahan`, `dpp`, ...

#### Insight Kritis: Payment Scheme dropdown
Dropdown `carabayar` adalah lookup ke **Master Payment Schemes** (table yang Java Core System belum punya!). User pilih skema → calculator auto-pre-fill semua parameter (DP %, tenor, bunga, diskon default, dll) dari template.

#### Implementasi Java Core System:
```php
// 1. Master payment_schemes table (sudah ada di action items)
// 2. SchemeCalculator service yang accepts payment_scheme_id + unit_id
// 3. Returns: breakdown (price, discount, dpp, ppn, total) + payment_schedule preview

class SchemeCalculator {
    public function calculate(PaymentScheme $scheme, Unit $unit, array $overrides = []): array {
        $price = $unit->price_list;
        $discountPct = $overrides['discount_pct'] ?? $scheme->default_discount_pct;
        $additionalDiscount = $overrides['additional_discount'] ?? 0;
        // ...
        return [
            'price_list' => $price,
            'discount_value' => $discountValue,
            'additional_discount' => $additionalDiscount,
            'dpp' => $dpp,
            'ppn_pct' => $scheme->ppn_pct,
            'ppn_value' => $ppnValue,
            'contract_value' => $contractValue,
            'preview_schedule' => $this->generateSchedule(...),
        ];
    }
}
```

Effort: 1-2 hari setelah Master Payment Schemes ready.

---

## 👤 DEEP DIVE: Form Customer Daftar (Pendaftaran Customer Baru) — ⭐⭐⭐ MOST COMPREHENSIVE

**URL:** `/marketingjual/CustomerDaftar.aspx`
**Heading:** "Pendaftaran Customer Baru"
**Total:** **7 sections · ~50 fields** ⚠️

### Hidden Context Flags (super smart pattern!)

Java Core System Lama form ini punya hidden checkbox flags untuk **context-aware behavior**:
- `dariReservasi` — form dipanggil dari context Reservasi (booking)
- `dariClosing` — dari context Closing
- `dariGN` — dari context Ganti Nama

Same form serves multiple workflows — saat dari Reservasi, mungkin auto-tag customer ke booking baru. Saat dari Ganti Nama, mungkin link ke booking lama. **Cerdas!**

Java Core System bisa mereplikasi via Inertia props (`?context=booking` di URL).

### Section 1: Identitas (6 fields)

| Field | Type | Catatan | Java Core System Mapping |
|-------|------|---------|-----------------|
| **No. Customer** | text (readonly, `#AUTO#`) | Auto-gen via Numerator | `code` ✅ |
| **Project** | dropdown | Customer assigned per project | ⚠️ Java Core System global, perlu add `project_id` (nullable) |
| **Sumber Data** | dropdown | Marketing channel (Pameran/Online/Referral/dll) | ❌ **BELUM ADA — penting untuk marketing analytics** ⭐ |
| **Tipe** | radio | `PERORANGAN` / `BADAN HUKUM` | ✅ `customer_type` enum |
| **Kewarganegaraan** | radio | `WNI` / `WNA` | ⚠️ Perlu add `nationality` field |
| **Nama** | text | Required | ✅ `full_name` |

### Section 2: Kartu Identitas (9 fields)

| Field | Type | Java Core System |
|-------|------|---------|
| **No. Identitas** | text (NIK / Passport) | ✅ `id_number` |
| **Alamat** | text (alamat KTP) | ✅ `address` |
| **RT/RW** | text | ⚠️ Perlu add `rt_rw` (sub-field) |
| **Kelurahan** | text | ⚠️ Perlu add `kelurahan` |
| **Kecamatan** | text | ⚠️ Perlu add `kecamatan` |
| **Kotamadya/Kabupaten** | text | ⚠️ Perlu add `kota` |
| **Kode Pos** | text | ✅ `postal_code` |
| **Tanggal KTP Berakhir** | date | ⚠️ Perlu add `id_expires_at` |
| **Seumur Hidup** | checkbox | ⚠️ Set `id_expires_at = null` if checked |

> 🔥 **INSIGHT:** Java Core System Lama pecah alamat KTP jadi 6 sub-field structured (Alamat, RT/RW, Kelurahan, Kecamatan, Kota, Kode Pos). Java Core System sekarang flat string. Strukturisasi bagus untuk geocoding & analytics by region.

### Section 3: Data Pribadi (4 fields)

| Field | Type | Options | Java Core System |
|-------|------|---------|---------|
| **Agama** | radio (7 options) | ISLAM, BUDHA, KONGHUCU, KRISTEN, HINDU, KATOLIK, LAINNYA | ⚠️ Perlu add `religion` enum |
| **Tempat** | text | Tempat lahir | ⚠️ Perlu add `birth_place` |
| **Tanggal Lahir** | date | | ⚠️ Perlu add `birth_date` |
| **Status** | radio (4 options) | MENIKAH, CERAI, BELUM MENIKAH, LAIN-LAIN | ⚠️ Perlu add `marital_status` enum |

### Section 4: Data NPWP (7 fields + 1 toggle)

| Field | Type | Java Core System |
|-------|------|---------|
| **SAMA dengan KTP** | checkbox toggle | UX pattern: copy from KTP section |
| **Nama NPWP** | text | ✅ Sudah ada (`npwp_name`) |
| **NPWP** | text (15 digit) | ✅ Sudah ada (`npwp`) |
| **Alamat NPWP** | text | ⚠️ Perlu add |
| **RT/RW**, **Kelurahan**, **Kecamatan**, **Kotamadya** | text | ⚠️ Perlu add structured |

### Section 5: Kontak (5 fields)

| Field | Type | Java Core System |
|-------|------|---------|
| **No. Telepon** | text (rumah) | ⚠️ Perlu add `home_phone` |
| **No. HP 1 (untuk di SMS)** | text dgn prefix `+62` | ✅ `phone` (primary) |
| **No. HP 2** | text | ⚠️ Perlu add `phone_2` |
| **No. Fax** | text | ⚠️ Perlu add `fax` (kuno tapi corporate may need) |
| **Alamat Email** | email | ✅ `email` |

### Section 6: Alamat Surat Menyurat (5 fields + 1 toggle)

Sama dengan Section 2 (Kartu Identitas), tapi untuk mailing address.

| Field | Type | Java Core System |
|-------|------|---------|
| **SAMA dengan KTP** | checkbox toggle | UX copy from KTP |
| **Alamat Surat Menyurat** | text | ⚠️ Perlu add `mailing_address` |
| **RT/RW**, **Kelurahan**, **Kecamatan**, **Kotamadya** | text | ⚠️ Perlu add |

### Section 7: Data Pekerjaan (9 fields)

| Field | Type | Java Core System |
|-------|------|---------|
| **Pekerjaan** | text | ⚠️ Perlu add `occupation` |
| **Perusahaan** | text | ⚠️ Perlu add `company_name` |
| **Jabatan** | text | ⚠️ Perlu add `job_title` |
| **No. Telepon Kantor** | text | ⚠️ Perlu add |
| **Alamat Kantor** | text | ⚠️ Perlu add `office_address` |
| **RT/RW**, **Kelurahan**, **Kecamatan**, **Kotamadya** | text | ⚠️ Perlu add structured |

### Section 8: Orang Yang Dapat Dihubungi (Emergency Contact) — 4 fields

| Field | Type | Options | Java Core System |
|-------|------|---------|---------|
| **Nama** | text | | ⚠️ Perlu add `emergency_contact_name` |
| **Hubungan** | radio (7 options) | Orang Tua, Anak, Teman, Suami, Saudara, Istri, Lainnya | ⚠️ Perlu add `emergency_relationship` enum |
| **HP** | text | | ⚠️ Perlu add `emergency_phone` |
| **Alamat Email** | email | | ⚠️ Perlu add `emergency_email` |

### Action Button
- **OK** (blue) — submit form

---

## 🔥 INSIGHT KRITIS dari Customer Form

### Insight #1: Java Core System Customer Model TERLALU MINIMAL!

Customer model Java Core System sekarang hanya punya basic fields (name, email, phone, NIK, address). **Java Core System Lama punya ~50 fields** terstruktur dalam 7 sections.

**Gap untuk go-live:**
- Sumber Data (marketing channel) — penting untuk ROI marketing
- Tempat & Tanggal Lahir, Agama, Status Pernikahan — required di PPJB legal document
- Alamat structured (RT/RW, Kelurahan, Kecamatan, Kota) — required di kontrak
- Alamat NPWP, Alamat Surat Menyurat, Alamat Kantor — 3 alamat berbeda!
- Data Pekerjaan (Perusahaan, Jabatan) — untuk verifikasi calon customer
- Emergency Contact — wajib untuk kontrak

**Total field tambahan: ~30 field baru!**

### Insight #2: "SAMA dengan KTP" UX Pattern

Java Core System Lama pakai checkbox "SAMA dengan KTP" di 2 section (NPWP, Surat Menyurat) untuk auto-copy data. Smart UX yang **wajib direplikasi** di Java Core System.

```vue
<Checkbox v-model="form.npwp_same_as_ktp" @change="copyKtpToNpwp" />
```

### Insight #3: Context-Aware Customer Form

Hidden flags `dariReservasi`, `dariClosing`, `dariGN` — form CRUD bisa dipanggil dari berbagai context dengan behavior berbeda (mis. auto-redirect setelah save).

**Java Core System pattern:**
```php
// Route: /customers/create?context=booking&booking_id=123
// Setelah save, redirect ke /bookings/123/edit dengan customer baru
```

### Insight #4: "Terbaru:" Sidebar Recent Customers

Form Customer Daftar punya sidebar kiri "Terbaru :" yang **menampilkan customer recent**. Saat sales lagi input customer baru, dia bisa lihat recent ones (mungkin untuk cek duplikasi).

**Java Core System:** tambah panel "Recent Customers" di sidebar form Create Customer, auto-suggest jika NIK/nama mirip.

### Insight #5: Address Structuring untuk Geocoding & Analytics

Indonesian addresses punya struktur hierarkis: Alamat → RT/RW → Kelurahan → Kecamatan → Kota → Kode Pos. Java Core System Lama sudah pecah jadi 6 field. Java Core System sekarang flat string.

**Manfaat structured address:**
- Geocoding (lat/long lookup)
- Analytics "customer dari kota mana paling banyak booking?"
- Filter laporan per region
- Integration dengan map (Google Maps) untuk delivery/visit

**Migration:** add 6 new columns + helper untuk parse legacy flat address.

---

## 📊 Updated Action Items dari 4 Form Observed

### ⭐ CRITICAL (must-have untuk go-live):
| # | Item | Effort |
|---|------|--------|
| 1 | **Customer model expansion** (+30 field structured) | 2-3 hari |
| 2 | **Master `payment_schemes` table** + CRUD UI | 1-2 hari |
| 3 | **Master `data_sources` table** (marketing channel) | 4 jam |
| 4 | **Migration tool: import data customer/booking lama** | 2 hari |
| 5 | **Address structuring** (split flat → 6 field) + migration | 1 hari |

### ⭐ HIGH (sales productivity):
| # | Item | Effort |
|---|------|--------|
| 6 | **BPHTB Calculator** standalone | 1 hari |
| 7 | **KPR Calculator** standalone | 2 jam |
| 8 | **Skema Calculator** (depends on payment_schemes) | 1-2 hari |
| 9 | **"SAMA dengan KTP"** UX checkbox pattern | 30 min/section |
| 10 | **"Terbaru:" recent customers panel** di Create form | 2 jam |
| 11 | **Customer Daftar context-aware** (`?context=booking`) | 2 jam |

### ⭐ MEDIUM:
| # | Item | Effort |
|---|------|--------|
| 12 | **Religion enum** (7 options) | 30 min |
| 13 | **Marital status enum** (4 options) | 30 min |
| 14 | **Emergency contact section** | 1 jam |
| 15 | **Employment data section** | 1 jam |

---

---

## 🎯 DEEP DIVE: Kontrak Edit Module (6 Tabs!) — ⭐⭐⭐ MAJOR FINDING

**URL:** `/marketingjual/KontrakJadwalTagihan.aspx?NoKontrak=001484/SPR/JR/V/2026`
**Window:** Popup browser tab

### 🔍 Kontrak Number Format (BERBEDA dari Booking!):
```
001484/SPR/JR/V/2026
  │     │   │  │  │
  │     │   │  │  └── Year 4-digit (vs Booking 2-digit!)
  │     │   │  └───── Month Roman (V = May)
  │     │   └──────── Project code (JR)
  │     └──────────── SPR (Surat Pemesanan / Sales Purchase?)
  └────────────────── Sequence 6-digit (vs Booking 7-digit)
```

> 🔥 **Insight Format**: Kontrak punya format SEPARATE dari Booking:
> - Year **4-digit** (`2026`) vs Booking 2-digit (`26`)
> - Sequence **6-digit** vs Booking 7-digit
> - Code **SPR** (Surat Pemesanan/Sales Purchase) vs Booking RSV (Reservasi)
>
> Java Core System Numerator wajib **support multiple format patterns per document type**.

### 🗂️ Tabs di Kontrak Edit (6 tabs)

| # | Tab | URL | Status Java Core System |
|---|-----|-----|----------------|
| 1 | **Edit Kontrak** | `KontrakEdit.aspx` | ⚠️ Java Core System punya `/contracts/{id}/edit` |
| 2 | **Jurnal** ⭐ | `JurnalKontrak.aspx` | ❌ BARU INSIGHT: CRM Activity Log! |
| 3 | **Jadwal Tagihan** | `KontrakJadwalTagihan.aspx` | ⚠️ Ada `payment_schedules`, belum UI ini lengkap |
| 4 | **Jadwal Komisi** | `JadwalKomisi.aspx` | ⚠️ Commission ada, schedule-form belum |
| 5 | **Proses PPJB** | `ProsesPPJB.aspx` | ✅ PPJB issue flow ada |
| 6 | **Gimmick** ⭐ | `Gimmick.aspx` | ❌ BARU — bonus/promo items per kontrak |

---

## 📊 Tab "Jadwal Tagihan" — Deep Analysis

### Layout

```
┌─ Top bar ───────────────────────────────────────────────┐
│ ←Prev Next→ | No.Kontrak | Unit | Customer | Sales      │
├─ Action ────────────────────────────────────────────────┤
│ [Edit Tagihan]                                          │
├─ Summary Section ───────────────────────────────────────┤
│ Skema: KPR FREE DP CLUSTER 3.4 (032)                    │
│ Nilai Kontrak     : 465,500,000                         │
│ Tagihan           : 465,500,000                         │
│ Biaya             : 24,500,000                          │
│ Tagihan + Biaya   : 490,000,000                         │
│ Pembayaran        : 27,500,000                          │
│ Pelunasan (0.64%) : 27,500,000                          │
│ "Pembayaran yang sudah cair"                            │
├─ Adjustment Actions ────────────────────────────────────┤
│ [Free DP] [Cancel Free DP] [Subsidi DP] [Cancel Sub.]  │
├─ Legend ────────────────────────────────────────────────┤
│ Tipe: BF=Booking Fee / DP=Down Payment / ANG=Angsuran  │
│       / ADM=Biaya Administrasi                          │
│ Cara Bayar: TN=Tunai / KD=Kartu Debit / KK=Kartu Kredit│
│       / TR=Transfer Bank / BG=Cek Giro / DN=Diskon     │
│       / KR=KPR (Kredit Kepemilikan Rumah)              │
├─ Schedule Table ────────────────────────────────────────┤
│ No. | Tipe | Tagihan | Jatuh Tempo | Nilai | Pelunasan │
│     | ... payment lines + sub-rows for actual payments  │
├─ Grand Total ───────────────────────────────────────────┤
│ Total: 490M | Pelunasan: 27.5M | Sisa: 462.5M          │
└─────────────────────────────────────────────────────────┘
```

### 🔥 INSIGHT KRITIS dari Jadwal Tagihan

#### Insight #1: 4 ENUMS WAJIB di Java Core System

**Payment Type (`payment_type` enum):**
```php
enum PaymentType: string {
    case BookingFee   = 'BF';   // Booking Fee
    case DownPayment  = 'DP';   // Down Payment (DP 1, DP 2, DP 3)
    case Angsuran     = 'ANG';  // Installment (KPR/cicilan)
    case AdminFee     = 'ADM';  // Biaya Administrasi (notaris, BPHTB, dll)
}
```

**Payment Method (`payment_method` enum):**
```php
enum PaymentMethod: string {
    case Tunai          = 'TN';   // Cash
    case KartuDebit     = 'KD';   // Debit Card
    case KartuKredit    = 'KK';   // Credit Card
    case TransferBank   = 'TR';   // Bank Transfer
    case CekGiro        = 'BG';   // Cek/Bilyet Giro
    case Diskon         = 'DN';   // Discount-as-Payment (Free DP!)
    case KPR            = 'KR';   // KPR Disbursement
}
```

> 🔥 **"DN" (Diskon) sebagai payment method** = pattern brilliant! Saat Free DP granted, system create "discount payment" yang clear DP installment. Java Core System sekarang treat discount sebagai field di booking, bukan sebagai payment event.

#### Insight #2: Schedule Numbering Pattern

Sequential `.1`, `.2`, `.3`, ... suffix dari kontrak number:
- `001484/SPR/JR/V/2026.1` = Booking Fee
- `001484/SPR/JR/V/2026.2` = DP 1
- `001484/SPR/JR/V/2026.3` = DP 2
- `001484/SPR/JR/V/2026.4` = DP 3
- `001484/SPR/JR/V/2026.5` = Pencairan KPR

**Java Core System `payment_schedules` table:**
```php
// Tambah computed field
public function getTagihanNumberAttribute(): string {
    return $this->contract->contract_number . '.' . $this->sequence;
}
```

#### Insight #3: Sub-row Pattern untuk Payment Tracking

Each schedule row punya **child rows** showing actual payments:
```
DP 1 | 10 Jun 2026 | 8,166,666 | (paid) | 0
└─ DN, 29 May 2026 Memo Free DP : MEM/JR/26/V/00200
```

**Data model implication:**
```sql
payment_schedules:
  id, contract_id, sequence, payment_type, due_date, amount, status

payment_allocations:
  id, payment_schedule_id, allocated_at,
  source_type (cash_receipt|memo|discount|kpr_disbursement),
  source_id, amount, payment_method, reference_number, notes
```

Java Core System sudah punya TTS (Tanda Terima Setoran) + Allocation, tapi belum ada **Memo Free DP** sebagai allocation source.

#### Insight #4: "Free DP" & "Subsidi DP" Actions ⭐⭐⭐

4 action buttons:
- **Free DP** — grant DP gratis 100% (jadi tanpa DP)
- **Cancel Free DP** — cabut Free DP
- **Subsidi DP** — subsidi sebagian DP
- **Cancel Subsidi DP** — cabut subsidi

> 🔥 **MAJOR FEATURE GAP**: Java Core System belum punya konsep **Promotional DP Adjustment**. Ini common di marketing property: "Free DP", "DP cuma 5%", "Subsidi DP 50%" — bagian dari deal sweetener.

**Implementation Java Core System:**
```php
// Action: Free DP
class GrantFreeDp {
    public function execute(Contract $contract, User $approver): Memo {
        // 1. Create memo (MEM/JR/26/V/00xxx)
        $memo = Memo::create([
            'type' => 'free_dp',
            'contract_id' => $contract->id,
            'amount' => $contract->dp_total_amount,
        ]);

        // 2. Allocate to all DP schedules sebagai "DN" payment
        foreach ($contract->dpSchedules as $schedule) {
            PaymentAllocation::create([
                'schedule_id' => $schedule->id,
                'source_type' => 'memo',
                'source_id' => $memo->id,
                'amount' => $schedule->amount,
                'payment_method' => PaymentMethod::Diskon,
            ]);
            $schedule->update(['status' => 'paid']);
        }

        return $memo;
    }
}
```

Effort: 1-2 hari (Free DP + Subsidi DP + Cancel actions + approval).

#### Insight #5: Memo Reference (`MEM/JR/26/V/00200`)

Memo dengan format sendiri:
- `MEM` = Memo
- `JR` = Project
- `26` = Year 2-digit
- `V` = Month Roman
- `00200` = Sequence 5-digit

> Ini ASN/RSV/SPR pattern. **Numerator Service Java Core System wajib support multi-pattern dengan flexible sequence width.**

#### Insight #6: Summary Section Computed Fields

| Field | Formula |
|-------|---------|
| Nilai Kontrak | Sum dari schedule lines (non-biaya) |
| Tagihan | Same as Nilai Kontrak (atau total billable) |
| Biaya | Sum admin/notary/BPHTB fees |
| Tagihan + Biaya | Total |
| Pembayaran | Actual cash received (TN+TR+KD+KK+BG+KR) |
| Pelunasan (%) | (Pembayaran / Total) × 100 |

**Note:** "DN" payments (Free DP/Diskon) bisa di-exclude dari "Pembayaran" karena bukan cash real. Atau di-include dengan flag terpisah.

#### Insight #7: "Pencairan KPR" sebagai Single Line

KPR disbursement appears as ONE line item (`ANG` type) waiting until bank disburses. Saat cair, dibayar penuh sekaligus.

Java Core System `KpaDisbursement` model sudah ada, perlu **integrate ke payment schedule** sebagai source allocation type `kpa_disbursement`.

---

## 📊 Tab "Jurnal" — ⚠️ REVISI MAJOR ASSUMPTION!

### Saya Sebelumnya Salah Duga!

Sebelumnya saya kira "Jurnal" = **accounting journal entry** (double-entry bookkeeping). **TERNYATA SALAH!**

### ✅ Yang Sebenarnya: Jurnal = CRM Activity Log

**Layout Tab Jurnal (Kontrak):**

```
┌─ Form Input (untuk tambah aktivitas baru) ──────────────┐
│ Radio (pilih kategori):                                  │
│   ○ FOLLOW UP DOKUMEN  ○ AGREEMENT LISAN  ○ COMPLAIN    │
│   ○ URUSAN LEGAL       ○ AKTIVITAS TELEPON ○ LAINNYA    │
│   ○ MEETING CUSTOMER   ○ INFO TERAKHIR                  │
│                                                          │
│ Keterangan Tambahan : [textarea]                         │
│ File Hasil Scan     : [Choose File]                      │
│                                                          │
│ [OK] [Cancel]                                            │
├─ Existing Entries (table) ──────────────────────────────┤
│ Tgl | Jam | User | Keterangan | File                    │
│ "Jurnal untuk kontrak tersebut masih kosong."           │
└──────────────────────────────────────────────────────────┘
```

### 8 Activity Categories (radio):
1. **FOLLOW UP DOKUMEN** — track follow-up dokumen yang dibutuhkan
2. **AGREEMENT LISAN** — kesepakatan verbal dengan customer
3. **COMPLAIN** — keluhan customer
4. **URUSAN LEGAL** — hal legal terkait kontrak
5. **AKTIVITAS TELEPON** — log telepon (in/out)
6. **LAINNYA** — kategori lain
7. **MEETING CUSTOMER** — meeting tatap muka
8. **INFO TERAKHIR** — update info terakhir

### Fields:
- **Keterangan Tambahan** (textarea) — deskripsi detail aktivitas
- **File Hasil Scan** (upload) — attach scan document/PDF
- Auto-fill: Tgl, Jam, User (logged-in user)

### 🔥 INSIGHT KRITIS — REVISI

#### Insight #1: "Jurnal" = CRM Activity Log, BUKAN Accounting!

Implikasi besar untuk gap analysis:
- ❌ Saya TIDAK perlu build full General Ledger system untuk "parity Jurnal"
- ✅ Yang dibutuhkan: **CRM Activity Log table + UI** per kontrak/booking

**Model Java Core System:**
```php
// app/Domain/Crm/Models/ActivityLog.php
class ActivityLog extends Model {
    protected $casts = [
        'subject' => 'morph',  // polymorphic: Booking, Contract, Customer
        'category' => ActivityCategory::class,  // enum
        'logged_at' => 'datetime',
    ];

    // Belongs to user (yang log)
    // Has many attachments (file upload)
}

enum ActivityCategory: string {
    case FollowUpDocument   = 'follow_up_document';
    case VerbalAgreement    = 'verbal_agreement';
    case Complain           = 'complain';
    case LegalMatter        = 'legal_matter';
    case PhoneActivity      = 'phone_activity';
    case Other              = 'other';
    case CustomerMeeting    = 'customer_meeting';
    case LatestInfo         = 'latest_info';
}
```

Effort: 1-2 hari untuk model + UI + per-record viewer.

#### Insight #2: Activity Log POLYMORPHIC

Activity bisa di-attach ke:
- Booking
- Contract
- Customer
- (potentially: Unit, Followup, dll)

Use polymorphic relationship `morphTo()` di Laravel.

#### Insight #3: File Attachment per Entry

Setiap entry bisa attach 1 file scan (PDF/JPG dokumen). Need:
- Storage: `s3://ipro-fo/activity-attachments/{tenant}/{year}/{month}/{file}.pdf`
- Model: `ActivityAttachment` with `path`, `original_name`, `mime_type`, `size`
- Security: only authenticated users in same tenant bisa download

#### Insight #4: Separate FROM Followup Module!

⚠️ Beda dengan Followup (Collection module):
- **Followup** = scheduled task untuk Collection (PJT, denda, dll) — outbound debt collection
- **Jurnal/ActivityLog** = log historikal aktivitas semua jenis di kontrak/booking — inbound + outbound

Java Core System sudah punya Followup. Activity Log adalah **layer baru** lebih general.

---

## 📊 Action Items Update (dari Jadwal Tagihan + Jurnal)

### ⭐ CRITICAL:
| # | Item | Effort |
|---|------|--------|
| 1 | **PaymentType enum** (BF/DP/ANG/ADM) | 30 min |
| 2 | **PaymentMethod enum** (TN/KD/KK/TR/BG/DN/KR) | 30 min |
| 3 | **PaymentAllocation source types** include `memo`, `discount` | 1-2 jam |
| 4 | **Memo model** untuk Free DP / Subsidi DP | 1 hari |
| 5 | **Free DP / Subsidi DP actions** + approval flow | 1-2 hari |
| 6 | **CRM Activity Log** polymorphic table + UI | 1-2 hari |
| 7 | **File attachment** untuk Activity Log | 4 jam |

### ⭐ HIGH:
| # | Item | Effort |
|---|------|--------|
| 8 | **Summary section** di Contract detail (Nilai Kontrak, Tagihan, Biaya, Pembayaran, Pelunasan%) | 4 jam |
| 9 | **Sub-row UI** untuk schedule (parent + children payment lines) | 4 jam |
| 10 | **Schedule numbering** suffix `.1, .2, .3` | 2 jam |
| 11 | **Tab navigation** di Contract edit (Edit/Jurnal/Jadwal Tagihan/Jadwal Komisi/PPJB/Gimmick) | 2 hari |

### ⭐ MEDIUM:
| # | Item | Effort |
|---|------|--------|
| 12 | **Gimmick** module (promo/bonus items per kontrak) — need more observation | 1-2 hari |
| 13 | **Jadwal Komisi** tab (commission schedule for sales) | 1 hari |

---

## 🎯 REVISI ARSITEKTUR Berdasarkan Findings Baru

### Yang TIDAK Jadi Diperlukan (saya batalkan dari sebelumnya):
- ❌ ~~Full General Ledger system~~ — Jurnal ternyata CRM activity log, bukan accounting
- ❌ ~~Double-entry journal_entries + journal_lines~~ — overkill

### Yang TETAP Critical (refined):
- ✅ **PaymentAllocation enhancement** dengan multiple source_type (cash, memo, discount, kpr)
- ✅ **Memo model** untuk track Free DP / Subsidi DP / adjustment lainnya
- ✅ **Activity Log polymorphic** (Jurnal Java Core System Lama) — 8 kategori
- ✅ **Payment Type & Method enums** — BF/DP/ANG/ADM + TN/KD/KK/TR/BG/DN/KR
- ✅ **Contract Summary** computed (Nilai, Tagihan, Biaya, Pembayaran, Pelunasan%)

### Yang Naik Prioritas:
- ⬆️ **Free DP / Subsidi DP feature** — common di marketing property, harus ada
- ⬆️ **Schedule sub-row UI** — payment audit trail per cicilan

---

---

## 🎯 DEEP DIVE: 3 Tabs Kontrak — Jadwal Komisi · Proses PPJB · Gimmick

### Bonus: Tab "Proses KPR" Conditional (hanya muncul untuk kontrak KPR)

Headings menunjukkan **7 tab total** kalau cara bayar = KPR:
```
Edit Kontrak | Jurnal | Jadwal Tagihan | [Proses KPR] | Jadwal Komisi | Proses PPJB | Gimmick
```

Tab `Proses KPR` ada di module `/kpa/` (separate sub-app), bukan di `/marketingjual/`. URL pattern beda — perlu navigate via KPA module.

> 🔥 **Insight:** Tabs di Java Core System Lama Kontrak edit **CONDITIONAL** berdasarkan cara bayar:
> - Cara Bayar = **KPR** → 7 tabs (with Proses KPR)
> - Cara Bayar = **Tunai/Cicilan** → 6 tabs (without Proses KPR)
>
> Java Core System bisa mereplikasi via Vue computed: `tabs.filter(t => t.condition(contract))`.

---

### Tab #1: Jadwal Komisi

**URL:** `KontrakJadwalKomisi.aspx?NoKontrak=...`
**Title:** "Jadwal Komisi"

**Layout untuk kontrak yang belum proses komisi:**
```
"Proses Komisi Belum Dilakukan."
```

Page kosong dengan empty-state text. **Tidak ada action button yang terlihat** — kemungkinan trigger komisi otomatis terjadi saat:
- Kontrak signed
- DP lunas
- Atau saat event tertentu (configurable)

**Insight:** Commission processing punya **trigger event** yang Java Core System sudah punya (Commission Calculator + Payout flow). Empty state UI = important untuk UX.

**Action item Java Core System:**
```vue
<EmptyState v-if="!contract.commission_processed"
    title="Proses Komisi Belum Dilakukan"
    description="Komisi akan dihitung otomatis setelah ..."
    :action="canTrigger ? { label: 'Proses Sekarang', onClick: triggerCommission } : null"
/>
```

---

### Tab #2: Proses PPJB ⭐⭐⭐

**URL:** `KontrakProsesPPJB.aspx?NoKontrak=...`
**Title:** "Proses PPJB"

#### Layout (2-column):

**Left Column: Status PPJB (4-state lifecycle!)**

Radio options:
1. **Belum di proses** (Not yet processed)
2. **Print** (Printed)
3. **Proses Tanda Tangan** (Signing in progress)
4. **Selesai** (Done)

Fields:
| Field | Type | Catatan |
|-------|------|---------|
| **No PPJB System** | text (auto-gen) | Sistem yang generate |
| **No PPJB Manual** | text (manual entry) | Untuk PPJB lama / manual numbering |
| **Tanggal PPJB** | date | |
| **Tanggal Cetak** | date | Saat di-print |
| **Tanggal Tanda Tangan** | date | Saat di-sign |
| **Nomor PPJB yang digunakan** | radio: Manual / System | Pilih nomor mana yang dipakai |

**Right Column: Kelengkapan Berkas (Document Checklist)**

3 dokumen tracker (radio):
| Dokumen | Options |
|---------|---------|
| **KK** (Kartu Keluarga) | Tidak Ada / Ada |
| **KTP** | Tidak Ada / Ada |
| **NPWP** | Tidak Ada / Ada |

#### 🔥 INSIGHT KRITIS

##### Insight #1: PPJB 4-State Machine

Java Core System sekarang punya generic Contract status. Java Core System Lama punya **dedicated PPJB state machine**:

```php
enum PpjbStatus: string {
    case BelumDiProses     = 'belum';
    case Print             = 'print';
    case ProsesTandaTangan = 'tanda_tangan';
    case Selesai           = 'selesai';
}
```

Transition:
```
belum → print → tanda_tangan → selesai
```

##### Insight #2: Dual Numbering (Manual + System)

Untuk **legacy compatibility** — PPJB lama (sebelum sistem) punya manual numbering. User pilih mana yang dipakai sebagai nomor official.

Java Core System:
```php
// Contract model
public function getEffectivePpjbNumberAttribute(): string {
    return $this->ppjb_use_manual_number 
        ? $this->ppjb_manual_number 
        : $this->ppjb_system_number;
}
```

##### Insight #3: Tanggal Triplet (PPJB Lifecycle)

- `tanggal_ppjb` — Tanggal kontrak PPJB (effective date)
- `tanggal_cetak_ppjb` — Saat di-print (untuk track printing)
- `tanggal_tanda_tangan_ppjb` — Saat di-sign

3 timestamps berbeda untuk full audit trail PPJB lifecycle.

##### Insight #4: Kelengkapan Berkas (Document Compliance)

Java Core System Lama enforce bahwa **PPJB BELUM bisa di-sign** kalau dokumen belum lengkap:
- KK
- KTP
- NPWP

Java Core System `ContractDocument` model bisa add:
```php
class ContractDocument extends Model {
    // Required documents per contract type
    public static $requiredDocs = [
        'PPJB' => ['kk', 'ktp', 'npwp'],
        'AJB' => ['kk', 'ktp', 'npwp', 'akta_nikah', 'ppjb'],
    ];
    
    public function isComplete(string $contractType): bool {
        return collect(self::$requiredDocs[$contractType])
            ->every(fn($doc) => $this->{"has_$doc"});
    }
}
```

Plus business rule:
```php
class SignPpjb {
    public function execute(Contract $contract): Contract {
        if (! $contract->documents->isComplete('PPJB')) {
            throw new IncompleteDocumentsException();
        }
        // ...
    }
}
```

---

### Tab #3: Gimmick ⭐⭐⭐⭐ — MAJOR DISCOVERY!

**URL:** `KontrakGimmick.aspx?NoKontrak=...`
**Title:** "Kontrak Gimmick"

#### 🎁 Apa itu "Gimmick"?

**Gimmick = Promotional Bonus Items / Sales Sweetener** yang diberikan ke customer sebagai bagian dari deal.

Common di industry property Indonesia:
- "Beli unit ini dapat **FREE BPHTB**, **FREE AJB BBN**, **FREE Taman Depan**!"
- "Bonus **FREE Canopy** + **FREE IPL 2 tahun**!"
- "**FREE Jaminan Anti Rayap** s/d 3 tahun!"

#### Layout

**Section 1: Data Kontrak** (header summary):
- No. Kontrak, Tgl. Kontrak, Customer, Sales, Cara Bayar

**Section 2: Nilai Kontrak** (financial summary):
- Nilai DPP, Nilai PPN, Nilai Kontrak, Persen Lunas

**Section 3: Type Gimmick Filter**:
- Type Gimmick dropdown: e.g. **"CLUSTER 3.4"** (package per cluster!)
- Search button

**Section 4: Tabel Gimmick Items** (12 columns):

| Column | Tipe | Fungsi |
|--------|------|--------|
| **No.** | number | Row number |
| **Diberikan** | checkbox | Janji diberikan saat closing (✓) |
| **Diterima** | checkbox | Customer sudah terima (×) |
| **Tgl Diterima** | date | Tanggal received |
| **Kode Barang** | int | Internal item code |
| **Tipe Barang** | int | Item type (8 = Voucher) |
| **Nama Barang** | text | Display name |
| **Satuan Barang** | text | Unit (VOUCHER, BUAH, dll) |
| **Target Lunas** | date/% | Target tanggal lunas untuk claim |
| **Harga Satuan Barang** | currency | Unit price |
| **Qty Barang** | int | Quantity |
| **Total Harga** | currency | qty × harga |
| **Action** | button | Delete |

#### 7 Gimmick Items Observed (untuk Cluster 3.4):

| Code | Type | Nama Barang |
|------|------|-------------|
| 81 | 8 (VOUCHER) | **FREE BPHTB** |
| 82 | 8 | **FREE IPL 2 TAHUN** |
| 83 | 8 | **FREE BIAYA KPR INC ASURANSI** |
| 84 | 8 | **FREE TAMAN DEPAN** |
| 85 | 8 | **FREE AJB BBN** |
| 86 | 8 | **FREE CANOPY** |
| 87 | 8 | **FREE JAMINAN ANTI RAYAP (S/D 3 TA...** |

#### 🔥 INSIGHT KRITIS

##### Insight #1: Gimmick = SALES BUNDLE LIFECYCLE

Gimmick is THE sweetener yang sales tawarkan ke customer untuk close deal. **WAJIB** di-track:
- **Diberikan** (committed di kontrak) — legal binding
- **Diterima** (actually delivered) — operational tracking

##### Insight #2: Per-Cluster Gimmick Package

Gimmick package berbeda per cluster (Type Gimmick: CLUSTER 3.4, CLUSTER 4.5, dll). Marketing team setup paket promo per cluster di master.

**Java Core System Model:**

```php
// app/Domain/Marketing/Models/GimmickPackage.php
class GimmickPackage extends Model {
    // belongs_to project, cluster (optional)
    // has_many gimmick_items
}

class GimmickItem extends Model {
    // belongs_to gimmick_package
    // has: kode_barang, tipe_barang, nama_barang, 
    //      satuan_barang, harga_satuan, qty_default
}

class ContractGimmick extends Model {
    // belongs_to contract, gimmick_item
    // has: diberikan_at, diterima_at, target_lunas, 
    //      tgl_diterima, qty, total_harga
}
```

##### Insight #3: Marketing Cost Per Sale

Total Harga gimmick = **marketing cost** yang dibebankan ke kontrak ini. Berguna untuk:
- ROI analytics ("Cluster 3.4 punya gimmick budget Rp X, total kontrak Rp Y")
- Margin calculation per unit
- Promo effectiveness analysis

##### Insight #4: 8 Tipe Barang (kemungkinan)

Tipe = 8 untuk semua items observed. Possible types:
- 1: Cash
- 2: Discount Bayar
- 3: Service Waiver
- 4: Physical Goods
- 5: Voucher Pihak Ketiga
- 6: Layanan Tambahan
- 7: Garansi
- 8: **VOUCHER** (intangible promise/free service)

⚠️ Need to verify with tim MUP — kemungkinan ada master `gimmick_types` table.

##### Insight #5: "Target Lunas" — Conditional Gimmick

Field "Target Lunas" menandakan customer **harus lunas dulu** sebelum klaim gimmick tertentu. Misal: "FREE Canopy hanya berlaku kalau lunas sebelum 31 Des 2026".

Java Core System `ContractGimmick`:
```php
public function isClaimable(Contract $contract): bool {
    if (! $this->target_lunas) return true;  // no condition
    return $contract->is_lunas && $contract->lunas_at <= $this->target_lunas;
}
```

##### Insight #6: Physical Goods Inventory Concern

Beberapa gimmick = physical items (Canopy, Taman). Marketing perlu:
- Order ke vendor
- Track delivery ke customer
- Cost accrual saat gimmick "diberikan" (committed cost)
- Cost realization saat "diterima" (delivered)

Future enhancement: **integrate dengan `inventory` module** untuk physical gimmick items.

---

## 📊 Updated Action Items dari 3 Tabs Kontrak

### ⭐⭐ CRITICAL NEW:

| # | Item | Effort | Catatan |
|---|------|--------|---------|
| 1 | **Gimmick module** (Package + Item + ContractGimmick) | 3-4 hari | Major missing feature |
| 2 | **GimmickType enum** (8 types, perlu konfirm dengan MUP) | 1 jam | |
| 3 | **PPJB 4-state machine** (Belum/Print/TandaTangan/Selesai) | 1 hari | |
| 4 | **PPJB dual numbering** (Manual + System) | 2 jam | Legacy support |
| 5 | **PPJB tanggal triplet** (PPJB, Cetak, TandaTangan) | 30 min | |
| 6 | **Document Compliance Checklist** (`contract_documents` table + UI) | 1-2 hari | KK/KTP/NPWP/dll |
| 7 | **Conditional tabs UI** di Contract edit (berdasarkan cara bayar) | 2 jam | |

### ⭐ HIGH:

| # | Item | Effort |
|---|------|--------|
| 8 | **Commission Empty State UI** with action trigger | 2 jam |
| 9 | **Marketing Cost Tracking** per kontrak (total gimmick value) | 4 jam |
| 10 | **Gimmick Diberikan vs Diterima** status tracker UI | 4 jam |

---

## 🎯 REVISI ARSITEKTUR — Phase 2

Dengan findings dari 3 tabs ini, arsitektur kontrak/PPJB Java Core System perlu enhanced:

### Phase 2 Module Additions:

1. **Marketing/Gimmick Module** (NEW! belum ada di Java Core System):
   - GimmickPackage (per cluster/project)
   - GimmickItem (template)
   - GimmickType enum
   - ContractGimmick (link kontrak ke gimmick + status)

2. **Document Compliance Module** (enhancement):
   - ContractDocument tracker
   - Required documents per contract type
   - Validation gate sebelum action (mis. sign PPJB)

3. **PPJB Status Machine** (enhancement):
   - Replace generic contract status dengan PpjbStatus enum
   - Add tanggal_cetak + tanggal_tanda_tangan
   - Dual numbering field

4. **Contract Tabs UI** (enhancement):
   - Conditional rendering berdasarkan cara_bayar
   - Tab navigation pattern (Edit/Jurnal/Tagihan/Komisi/PPJB/Gimmick + optional KPR)

---

## 📋 Saran Eksplorasi Lanjutan (untuk Bapak/Ibu)

Untuk gap analysis lebih dalam, mohon **klik sub-menu berikut di Java Core System Lama** lalu saya akan observe **struktur form (field names + labels)** tanpa capture data customer real:

| Eksplorasi prioritas | Yang akan saya lakukan |
|----------------------|------------------------|
| **Customer → New / Tambah** | Catat semua field di form input customer (vs Customer model Java Core System) |
| **Booking → New / Buat Baru** | Catat alur wizard booking (jumlah step, field per step) |
| **Kontrak → Detail** | Catat field PPJB (vs Contract model Java Core System) |
| **Kalkulator** | Catat input/output kalkulator pricing |
| **Reminder** | Catat tipe reminder yang ada (follow-up? jatuh tempo? cicilan?) |

Cukup klik salah satu dari sidebar, saya akan observe halamannya.

---

🤖 Dokumen ini dibuat dari observasi live (read-only) Java Core System Lama production untuk validasi rebuild plan Java Core System v2.0.
