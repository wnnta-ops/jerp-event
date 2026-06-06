# Dokumentasi Build Ulang eProperty — Target Laravel 11

**Paket dokumentasi spesifikasi** untuk membangun ulang suite ERP properti **eProperty (IPRO / Eprop)** dari snapshot deployment legacy (tenant **MUP / Java Residence**) ke aplikasi modern berbasis **Laravel 11**.

> Status: rekonstruksi dari snapshot deployment per September 2025.
> Sumber kebenaran legacy: folder `../Backup APP Eprop/` (payload IIS terkompilasi + file `.bak` SQL Server) dan dokumen referensi `../CLAUDE.md`, `../PRD.md`, `../ERD.md`.
> Dokumen versi Laravel 12 lama (`../PRD_cloning.md`, `../ERD_cloning.md`, `../claude_cloning.md`) **hanya arsip** — paket ini menggantikannya untuk target Laravel 11.

---

## 1. Tujuan Paket Dokumen

Menyediakan satu set dokumentasi yang **lengkap dan mandiri** sehingga tim developer dapat membangun ulang eProperty di Laravel 11 tanpa harus membongkar binary IIS legacy. Dokumen ini menjawab:

- **Apa kebutuhan bisnisnya** (BRD)
- **Fitur produk apa yang harus dibangun** (PRD)
- **Bagaimana struktur datanya** (ERD)
- **Bagaimana alur prosesnya berjalan** (Business Flow)
- **Kebutuhan non-fungsional & teknis lainnya** (NFR / Teknis)

---

## 2. Daftar Dokumen & Urutan Baca

| No | File | Isi | Audiens utama |
| --- | --- | --- | --- |
| 00 | [00_README.md](00_README.md) | Indeks, glosarium, ringkasan, matriks penelusuran | Semua |
| 01 | [01_BRD.md](01_BRD.md) | **Business Requirements** — latar belakang, tujuan bisnis, stakeholder, kebutuhan & aturan bisnis | Business owner, PM, analis |
| 02 | [02_PRD.md](02_PRD.md) | **Product Requirements** — fitur per modul, navigasi, acceptance criteria (target Laravel 11) | PM, developer, QA |
| 03 | [03_ERD.md](03_ERD.md) | **Entity Relationship** — skema MySQL 8 / migrasi Laravel 11, tabel per modul, relasi | Developer, DBA |
| 04 | [04_BusinessFlow.md](04_BusinessFlow.md) | **Alur Bisnis** — flow end-to-end & per proses (diagram Mermaid), pemetaan ke padanan Laravel | Analis, developer, QA |
| 05 | [05_NFR_dan_Kebutuhan_Teknis.md](05_NFR_dan_Kebutuhan_Teknis.md) | **NFR & Teknis** — arsitektur Laravel 11, stack, keamanan, integrasi, migrasi data, environment | Arsitek, developer, DevOps |

**Urutan baca yang disarankan:** 00 → 01 → 02 → 04 → 03 → 05.
(Pahami konteks bisnis & produk dulu, lalu alur, baru skema dan teknis.)

---

## 3. Ringkasan Sistem (Legacy → Target)

Sistem legacy terdiri dari **5 aplikasi** di atas **satu instance SQL Server** dengan banyak database:

| Aplikasi legacy | Stack legacy | Peran |
| --- | --- | --- |
| **BO** — Back Office | ASP.NET WebForms 3.5/4 (single DB) | Accounting inti: GL, AR, AP, CB, IVT, HR, LAND, PR, LEASE, SAL, LGL, FOBO |
| **FO** — Front Office | ASP.NET WebForms 4.8 + SignalR + Owin, **16 sub-app IIS** (DB per modul) | Operasional customer-facing: sales, finance AR, collection, legal, launching, NUP, komisi, approval, dll. |
| **KPR API** — Bank Loan | **.NET 7 + FastEndpoints + EF Core 7 + Hangfire + Redis + Serilog** | Pemrosesan kredit bank (KPR). 103 migrasi EF. **Referensi arsitektur modern.** |
| **NUP publik** | ASP.NET WebForms 4.5 + reCAPTCHA | Registrasi mandiri calon pembeli (dapat nomor antrian) |
| **TuxArch** | ASP.NET WebForms 4.5 + Windows Service | Backup `.bak` harian + zip + retention + alert |

**Target Laravel 11** — konsolidasi menjadi **1 monolith modular** + **1 database MySQL 8** (prefix tabel per modul):

```
eProperty (Laravel 11)
├── app/Modules/FO/        → MarketingSales, FinanceAR, Collection, Legal,
│                            Launching, KpaView, Komisi, Approval, Adminjual,
│                            Dashboard, Settings
├── app/Modules/BO/        → GeneralLedger, AccountsReceivable, AccountsPayable,
│                            CashBank, Inventory, HR, FoBoBridge, Setup
├── app/Modules/BankLoan/  → Contracts, ContractBanks, BIChecking, Realization (RA),
│                            Retention (PR/RR), Buyback, Mom, Reminders, Dashboard
├── app/Modules/Backup/    → Dashboard, Schedules, Logs, Reports, Config
└── app/Modules/System/    → Users, Roles, Masters, Numerator, Settings, AuditLog
```

**Stack target ringkas:** PHP 8.2+/8.3 · Laravel 11 · MySQL 8 · Redis 7 · Livewire 3 · Filament 3 · Laravel Reverb (WebSocket) · Horizon (queue) · Sanctum (auth) · Spatie Permission/Activitylog/Backup · Browsershot (PDF). Detail di [05_NFR_dan_Kebutuhan_Teknis.md](05_NFR_dan_Kebutuhan_Teknis.md).

---

## 4. Prinsip yang Dipertahankan dari Legacy

1. **Bahasa & terminologi Indonesia** — UI, label dokumen cetak, nama kolom domain, dan enum status tetap Indonesia.
2. **Kode dokumen verbatim** — `KPR`, `RA`, `PR`, `RR` (KPR API) dan numerator FO/BO (TTS, BKM, PPJB, AJB, dll.).
3. **Kompatibilitas dokumen cetak** — layout TTS/BKM/PPJB/AJB/IMB/kuitansi harus dikenali operator & customer.
4. **Numerator atomic** — penomoran tidak boleh racy (`MAX()+1`); gunakan sequence/row-lock.
5. **Audit trail** — semua aksi tulis ter-log (kepatuhan legal & pajak).

---

## 5. Glosarium Domain (Indonesia)

| Singkatan | Kepanjangan / Arti |
| --- | --- |
| **NUP** | Nomor Urut Pemilihan — nomor antrian untuk hari launching |
| **KPR / KPA** | Kredit Pemilikan Rumah / Apartemen (pembiayaan bank) |
| **PPJB** | Perjanjian Pengikatan Jual Beli (kontrak pendahuluan) |
| **AJB** | Akta Jual Beli (akta final) |
| **BAST / ST** | Berita Acara Serah Terima (handover unit) |
| **IMB / PBG** | Izin Mendirikan Bangunan / Persetujuan Bangunan Gedung |
| **TTS** | Tanda Terima Sementara (kuitansi sementara penerimaan dana) |
| **TTR** | Tanda Terima Resmi (kuitansi resmi) |
| **TTG / TTI** | Tanda Terima Giro / Internal |
| **BKM / BKK** | Bukti Kas Masuk / Keluar |
| **BGM / BGK** | Bukti Giro Masuk / Keluar |
| **BG** | Bilyet Giro |
| **BS** | Bank Statement / Bukti Setor |
| **FP / FPB** | Faktur Pajak / Form Permintaan Barang |
| **PR / PO / PL** | Purchase Request / Order / Price List |
| **RA** | Realisasi Akad (pencairan KPR) — kode KPR API |
| **PR (KPR)** | Pengajuan Retensi (sisa dana ditahan bank) — kode KPR API (kolisi nama dgn Purchase Request) |
| **RR** | Realisasi Retensi (pencairan retensi) — kode KPR API |
| **SP3K** | Surat Persetujuan Pemberian Kredit (dari bank) |
| **BI Checking** | Pengecekan riwayat debitur di Bank Indonesia / SLIK OJK |
| **LPA / OTS** | Legal Property Appraisal / On The Spot (survei) |
| **MEMO** | Memo finansial internal / cashback |
| **VA** | Virtual Account (rute pembayaran bank per customer) |
| **MoM** | Minutes of Meeting (risalah rapat developer ↔ bank) |
| **FOBO** | FO-BO bridge (posting jurnal otomatis FO → BO) |
| **CF** | Closing Fee (komisi closing) |
| **BLT** | Bukti Laporan Transaksi (dokumen komisi) |
| **PJT** | Pemberitahuan Jatuh Tempo (surat penagihan) |
| **SKL** | Surat Keterangan Lunas |
| **GN / GU** | Ganti Nama / Ganti Unit (perubahan kontrak) |
| **ADJ** | Adjustment kontrak |

---

## 6. Matriks Penelusuran (Traceability)

Setiap modul harus terdokumentasi konsisten di keempat dimensi. Gunakan tabel ini untuk verifikasi kelengkapan.

| Modul | Kebutuhan bisnis (BRD) | Fitur produk (PRD) | Entitas (ERD) | Alur (Business Flow) |
| --- | --- | --- | --- | --- |
| NUP (publik) | BR-NUP | §FO.NUP publik | `fo_nup`, `fo_customers`, `users` | Sub-flow 1 |
| Launching | BR-LNC | §FO.Launching | `fo_nup`, `fo_units`, `fo_reservasi` | Sub-flow 2 |
| Marketing/Sales | BR-MKT | §FO.MarketingSales | `fo_kontrak`, `fo_reservasi`, `fo_units`, `fo_skema` | Sub-flow 3 |
| KPR / Bank Loan | BR-KPR | §BankLoan | `bankloan_*` | Sub-flow 4 |
| Finance AR | BR-FIN | §FO.FinanceAR | `fo_tts`, `fo_bkm`, `fo_va`, `fo_fp` | Sub-flow 5 |
| Collection | BR-COL | §FO.Collection | `fo_pjt`, `fo_skl`, `fo_followup` | Sub-flow 6 |
| Legal | BR-LGL | §FO.Legal | `fo_legal_*` | Sub-flow 7 |
| Komisi | BR-KOM | §FO.Komisi | `fo_komisi_*` | Sub-flow 8 |
| Accounting (BO) | BR-ACC | §BO | `bo_*` | Sub-flow 9 |
| Pembayaran Xendit | BR-PAY | §FO.FinanceAR / Integrasi | `fo_tts`, `fo_va` | Sub-flow 10 |
| Backup | BR-BCK | §Backup | `backup_*` | Sub-flow 11 |

---

## 7. Catatan Metodologi & Batasan

- Skema **KPR API otoritatif** (direkonstruksi dari 103 migrasi EF Core di `eProperty.BankLoan.API.xml`).
- Skema **FO/BO bersifat inferred** dari nama halaman `.aspx`, stored procedure sync, dan connection string. **Harus diverifikasi** dengan me-restore file `.bak` ke SQL Server dev sebelum implementasi final (lihat [05_NFR_dan_Kebutuhan_Teknis.md §Migrasi Data](05_NFR_dan_Kebutuhan_Teknis.md)).
- Semua kredensial pada snapshot legacy dianggap **compromised** — hanya dipakai sebagai referensi skema, **wajib di-rotate**.
- File `.bak` dan snapshot legacy bersifat **read-only**; jangan diubah.
