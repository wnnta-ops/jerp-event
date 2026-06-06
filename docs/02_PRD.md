# PRD — Product Requirements Document (Target Laravel 11)

**eProperty (Clone) — Property Developer ERP**
Companion: [00_README.md](00_README.md) · [01_BRD.md](01_BRD.md) · [03_ERD.md](03_ERD.md) · [04_BusinessFlow.md](04_BusinessFlow.md) · [05_NFR_dan_Kebutuhan_Teknis.md](05_NFR_dan_Kebutuhan_Teknis.md)

> Dokumen ini menjabarkan **fitur produk** yang harus dibangun di Laravel 11. Untuk *mengapa* lihat BRD; untuk *alur proses* lihat Business Flow; untuk *skema data* lihat ERD; untuk *arsitektur & NFR* lihat dokumen 05.

---

## 1. Ringkasan Produk & Visi

**eProperty** adalah ERP terintegrasi untuk developer properti yang menutup seluruh siklus: NUP → Launching → Reservasi/Kontrak → KPR → Pembayaran → Collection → Legal → Pembukuan.

**Perubahan kunci dari legacy:**

| Aspek | Legacy | Target Laravel 11 |
| --- | --- | --- |
| Aplikasi | 5 aplikasi terpisah | 1 monolith modular |
| Database | 17 DB SQL Server | 1 DB MySQL 8 (prefix tabel per modul) |
| UI | WebForms postback | Livewire 3 (FO) + Filament 3 (BO/Backup/System) |
| Auth | Forms-auth per sub-app | SSO terpadu (Sanctum + Spatie Permission) |
| Real-time | SignalR | Laravel Reverb (WebSocket) |
| Job/cron | `.vbs` POST ke `.aspx` | Horizon (queue) + Scheduler |
| PDF | wkhtmltopdf | Browsershot (Puppeteer) + DomPDF fallback |
| Backup | TuxArch (Windows Service) | Spatie Backup + Filament UI + Scheduler |

**Yang TIDAK berubah:** bahasa Indonesia, kode dokumen (KPR/RA/PR/RR + numerator), format cetak, logika bisnis, terminologi domain.

---

## 2. Persona Pengguna & Navigasi

Lihat stakeholder lengkap di [01_BRD.md §3](01_BRD.md). Aplikasi memiliki **5 menu utama** + 1 route publik:

| Menu | Route prefix | Panel/UI | Pengguna |
| --- | --- | --- | --- |
| **FO** (Front Office) | `/fo/*` | Livewire + Blade | Sales, Finance customer-facing, Collection, Legal, Launching, Komisi |
| **BO** (Back Office) | `/bo/*` | Filament 3 | Accounting (GL, AR, AP, CB, IVT, HR) |
| **BankLoan** (KPR) | `/bankloan/*` | Livewire + Filament hybrid | KPR Officer |
| **Backup** | `/backup/*` | Filament 3 | IT Admin |
| **System** | `/system/*` | Filament 3 | IT Admin (users, roles, master, settings, audit) |
| **NUP publik** | `/nup/daftar` | Blade/Livewire (tanpa auth) | Calon pembeli |

### 2.1 Model Hak Akses

- Penamaan permission: `module.area.action` (mis. `fo.marketingjual.kontrak.create`, `bo.gl.jurnal.approve`).
- Role contoh (di-seed): `super_admin`, `direktur`, `finance_manager`, `sales_manager`, `sales`, `collection`, `legal`, `kpr_officer`, `accounting`, `it_admin`, `customer` (publik/terbatas).
- Multi-company: middleware pembatas + scope `forCompany()`.

---

## 3. Cakupan Fungsional per Modul

> Penamaan layar legacy (`.aspx`) disertakan sebagai referensi agar fitur dapat ditelusuri ke sistem lama.

### 3.1 FO — Front Office

#### 3.1.1 NUP Publik (`/nup/daftar`, tanpa auth) — [BR-NUP](01_BRD.md)
- Form registrasi mandiri calon pembeli. Field: No. Customer (auto), Project, Sumber Data/Iklan, Nama Lengkap (sesuai KTP), Nomor KTP, Tempat/Tanggal Lahir, Email, Pekerjaan, No HP (utama + opsional), NPWP, alamat lengkap (jalan, RT/RW, provinsi, kota, kecamatan, kelurahan), status pernikahan.
- **Data pasangan** (kondisional jika menikah): nama, KTP, email, HP, tempat/tgl lahir, pekerjaan, alamat (checkbox "sama dengan pemohon"), unggah KTP.
- **Alamat surat-menyurat** (checkbox "sama dengan KTP").
- **Preferensi unit** (checkbox, tidak mengikat).
- **Referensi sales**: nama & no telp sales, jenis tipe NUP, cara bayar.
- **Unggah** (maks 5 MB/file): KTP customer, NPWP, KTP pasangan.
- **reCAPTCHA v3** + checkbox pernyataan keabsahan data.
- Output: buat customer (lead) + akun terbatas + entri NUP, kirim konfirmasi (email/WA).

#### 3.1.2 Launching — [BR-LNC](01_BRD.md)
- **Hari-H**: Cashier, Panggil NUP (otomatis/manual/tampil layar TV), Reception (regis → hold/unhold/cancel).
- **Closing NUP**: wizard 4 langkah (`ClosingNUP` → `ClosingNUP4`).
- **Pemilihan unit real-time**: peta site plan live (`UnitPetaLive`) via **Reverb** — status tersedia/booking/terjual update < 1 detik; tampilan TV besar (`ShowPetaTV1`, `PetaBesar`).
- **Kalkulator skema** & **Ilustrasi KPR**.
- TTS/BKM langsung untuk pembayaran NUP/booking on-the-spot.

#### 3.1.3 MarketingSales — [BR-MKT](01_BRD.md)
- **Master**: Unit (+ waiting list), Customer (+ merge `CustomerGabung`, histori).
- **Reservasi**: buat/edit, ganti nama, ganti unit, promote, jadwal tagihan.
- **Kontrak/PPJB**: lifecycle PPJB, FPS (faktur pajak standar), adjustment (reguler + PPN), status kontrak.
- **Skema**: harga, diskon, bunga.
- **Closing langsung** (tanpa NUP) dengan approval.
- **Tagihan**: edit, reschedule, reset, custom.
- **Upload bulk**: jadwal tagihan, kontrak, pembayaran.
- **Cetak**: SP, SP Bank, GN/GU, TTR, WL, FPS, Batal, Jadwal Tagihan.

#### 3.1.4 FinanceAR — [BR-FIN](01_BRD.md)
- **TTS**: registrasi, registrasi marketing, posting ke BKM, void, edit, slip.
- **BKM**: cetak (single/batch/multi).
- **MEMO**: cashback/koreksi/refund, registrasi, edit, void.
- **TTR**: daftar, belum print.
- **Bilyet Giro**: daftar, ganti, status kliring/tolak.
- **Faktur Pajak**: input, posting, upload, daftar (e-Faktur).
- **Virtual Account**: registrasi, edit, impor (BCA/BNI/BRI/Mandiri), ekspor (BNI/BRI/Mandiri), upload.
- **Transfer anonim**: alokasi dana masuk tak teridentifikasi.
- **Customer view**: info, lunas, MEMO, TTS.
- **Posting CB** & dashboard finance AR.
- **API sync ke BankLoan**: menyediakan data TTS/BKM (pengganti `TTSBkmService.asmx`).

#### 3.1.5 Collection — [BR-COL](01_BRD.md)
- Daftar kontrak/tunggakan, **PJT** (auto-generate), **SKL**.
- Follow-up (+ daftar, history, grouping).
- **Denda otomatis** (pengganti `AutoDenda.vbs`).
- Dashboard collection.

#### 3.1.6 Legal — [BR-LGL](01_BRD.md)
- **PPJB** (edit, TTD, target, berkas), **AJB** (edit, TTD, target), **ST/BAST** (edit, TTD, target).
- **IMB/PBG** (edit, proses, target), **Sertifikat** (proses, target).
- Status & berkas, akad dengan bank.

#### 3.1.7 KpaView (tipis) — [BR-KPR](01_BRD.md)
- View FO untuk proses KPR; **sumber kebenaran = modul BankLoan**. Menyajikan pengajuan, realisasi, SP3K, LPA, OTS, wawancara, akad, berkas, cetak pengajuan/realisasi. Konsumsi data via service BankLoan (bukan duplikasi tabel).

#### 3.1.8 Komisi — [BR-KOM](01_BRD.md)
- **CF** (closing fee): registrasi, refund (CFR), potong (CFP).
- **Komisi**: sales, per pengajuan (KomisiP), realisasi (KomisiR); skema komisi multi-termin + reward.
- **Reward** (+ RewardP).
- **BLT**: Sales, Kantor, Koordinator (cetak masing-masing).
- Tipe tarif, tipe gimmick; reminder pencairan/generate komisi.

#### 3.1.9 Approval — [RULE-03](01_BRD.md)
- Antrian approval per jenis perubahan kontrak: ADJ, Batal, Custom Tagihan, Diskon, Ganti Nama (GN), Ganti Unit (GU), Reschedule. Two-step approve per jenis.

#### 3.1.10 Adminjual & Settings
- **Adminjual**: master Agent (+ target), Unit (+ price list + upload), Skema (+ bunga + diskon), Gimmick, Tipe Biaya, Level Sales, Tipe Sales, Peta (site plan + koordinat), Lokasi.
- **Settings**: alamat email, batas reserve, format AJB/PPJB/Unit, format warna legal/unit, master agent/level/lokasi/properti/skema, approval detail.

#### 3.1.11 Dashboard FO
- Ringkasan KPI operasional (collection, finance AR), kalender, daily report/KPR.

### 3.2 BO — Back Office (Accounting) — [BR-ACC](01_BRD.md)

UI berbasis **Filament 3**. Modul aktif (urut volume halaman legacy):

| Modul | Fungsi inti | Layar legacy representatif |
| --- | --- | --- |
| **CB** (Cash & Bank) | BKM/BKK, BGM/BGK, BNG, BS, ADM, ANO, reimbursement (PBS), refund, rekonsiliasi bank | `ADMRegis1..`, `ANOIdent1..5`, `BGKFile`, `RekonBank1` |
| **AR** (Acc. Receivable) | Invoice piutang (FI/FK/FP), denda, BPD, aging, addendum | `FIRegis1..5`, `FK`, `FP`, `QueryAging`, `BatchFP` |
| **IVT** (Inventory) | Stok, BBB/BKB/BPB, FPB (approval), PR (approval), price list, adjustment | `BKB`, `BPB`, `FPB`, `PR`, `PL` |
| **GL** (General Ledger) | Chart of account, jurnal (+ reversal), approval, closing periode, amortisasi, neraca/laba-rugi/arus kas | `JurnalRegis1..4`, `ClosingPT1..2`, `QueryBS`, `QueryCashFlow` |
| **AP** (Acc. Payable) | Invoice hutang (PO & non-PO), kuitansi, memorial, TTG, BPPH, posting, aging | `InvoicePORegis1..5`, `BatchINV`, `BatchBPPH`, `BTBPosting1..3` |
| **LAND** | Land bank: akuisisi tanah, transfer bank ledger | `LandBankMasuk/Keluar`, `LandInvoiceAP` |
| **PR** | Purchase request → approval → PO, RFQ, cost sheet | — |
| **LEASE / SAL / LGL / HR** | Lease finance, sales ringkas, legal, HR (karyawan, departemen, BRQ) | — |
| **FOBO** (Bridge) | Posting jurnal otomatis FO → BO (kasir jual, NUP, memo, pencairan akad, buyback, refund) | `KasirJual`, `PencairanAkad(Sync)`, `Buyback`, `RefundLebihBayar` |

- **Pola transaksi legacy** `Regis1..4` (entry → detail/validasi → approval → posting GL) di-rancang ulang sebagai *Resource + Action + Service* di Filament; posting GL via Service yang memastikan jurnal balance.
- **Multi-company & multi-cost-center** wajib.

### 3.3 BankLoan (KPR) — [BR-KPR](01_BRD.md)

Port dari KPR API .NET 7 (skema otoritatif, lihat [03_ERD.md §3](03_ERD.md)). Sub-area:

- **Contracts**: kontrak KPR (header), pre-interview (sumber dana, tujuan pembelian), status aktif & buyback.
- **BIChecking**: hasil + file pendukung.
- **ContractBanks**: pengajuan ke bank (multi-bank/kontrak), checklist requirement per bank (+ file + filing date), interview, **SP3K**, **agreement/akad**, retention.
- **Realization (RA)**: realisasi akad/pencairan (+ excess amount).
- **Retention (PR/RR)**: pengajuan retensi (PR) + realisasi retensi (RR, dengan remaining amount).
- **Buyback**: pembelian kembali/pembatalan + posting balik.
- **Mom**: minutes of meeting developer ↔ bank (terkait project).
- **Reminders** (Horizon jobs): BI Checking jatuh tempo, pengajuan retensi (output Excel).
- **Dashboard**: KPI kontrak.
- **Sinkronisasi FO ↔ BankLoan**: dua arah (event-driven, menggantikan stored procedure `SP_FOtoStandAlone_*`).

### 3.4 Backup — [BR-BCK](01_BRD.md)

Pengganti TuxArch. Berbasis **Spatie Backup + Filament**:
- **Dashboard**: total DB, sukses/gagal hari ini, ukuran storage, riwayat.
- **Schedules**: jadwal backup (harian siang/malam) + laporan bulanan.
- **Logs**: riwayat & query per status/tanggal.
- **Reports**: laporan bulanan (email + Trello).
- **Config**: retensi, kompresi, tujuan disk (local/S3), penerima alert.

### 3.5 System — [BR-SYS](01_BRD.md)

- **Users / Roles**: CRUD, foto, blokir/aktivasi, kick session, login attempt lock, password history.
- **Masters**: Project, Company, Bank, CostCenter, dll. (shared).
- **Numerator**: konfigurasi prefix, reset, padding, per-company (atomic).
- **Settings**: konfigurasi global.
- **AuditLog**: log aktivitas (Spatie Activitylog), log akses, IP whitelist admin.

---

## 4. Numerator / Kode Dokumen

- Dikonfigurasi terpusat di **System → Numerator** (`numerators` table) + kode KPR di config.
- **Kode KPR API (wajib verbatim)**: `Contract=KPR`, `AgreementRealization=RA`, `RetentionSubmission=PR`, `RetentionRealization=RR`.
- **Numerator FO/BO**: TTS, BKM, BKK, BG, MEMO, TTR, FP, PPJB, AJB, IMB, ST, NUP, dll.
- Aturan: prefix per jenis dokumen, reset per tahun/bulan (configurable), zero-padding N digit, sequence per company.
- **WAJIB atomic** (sequence/row-lock) — dilarang `MAX()+1`. Lihat [RULE-01](01_BRD.md) & [05 §Numerator](05_NFR_dan_Kebutuhan_Teknis.md).

---

## 5. Cetakan / Print Layout (harus dikenali pengguna)

Template di `resources/views/pdf/*.blade.php`, render via Browsershot (kompleks) / DomPDF (sederhana). Wajib visual-diff + sign-off operator sebelum go-live.

| Dokumen | Modul | Template legacy referensi |
| --- | --- | --- |
| TTS | FinanceAR, Launching | `PrintTTSTemplate.ascx`, `PrintTTSNUPTemplate2.ascx` |
| BKM | FinanceAR, Launching | `PrintBKMTemplate.ascx`, `PrintBKMBatch/Multi.aspx` |
| MEMO | FinanceAR | `PrintMEMOTemplate.ascx` |
| TTR | MarketingSales | `PrintTTRTemplate.ascx` |
| SP / SP Bank | MarketingSales, Launching | `PrintSPTemplate.ascx`, `PrintSPBankTemplate.ascx` |
| Jadwal Tagihan | MarketingSales, Launching | `PrintJadwalTagihanTemplate.ascx` |
| Faktur Pajak (FPS) | MarketingSales | `PrintFPSTemplate.ascx` |
| GN / GU | MarketingSales | `PrintGNTemplate.ascx`, `PrintGUTemplate.ascx` |
| Waiting List / Batal | MarketingSales | `PrintWLTemplate.ascx`, `PrintBatalTemplate.ascx` |
| NUP slip + WA Blast | NUP | `PrintNUPTemplate2.ascx`, `PrintNUPWATemplate.ascx` |
| Refund NUP | NUP | `PrintRefundTemplate.ascx` |
| Komisi BLT (Sales/Kantor/Koor) | Komisi | `PrintBLTSalesTemplate.ascx`, dll. |
| KPR Pengajuan/Realisasi | KpaView/BankLoan | `PrintPengajuanTemplate.ascx`, `PrintRealisasiTemplate.ascx` |
| KPR Dokumen | BankLoan | `Document Layout.html` + template per dokumen |
| Voucher AP/AR/CB (INV, KUI, MEMO, TTG, BKM, BKK, BGM, BGK, BS, dll.) | BO | `Print<JENIS>.aspx` |
| Reminder Excel | BankLoan | `Reminder-BI-Checking.xlsx`, `Reminder-Pengajuan-Retensi.xlsx` |

---

## 6. Acceptance Criteria per Modul (Go-Live)

Setiap modul dianggap siap rilis bila:

1. **Cakupan alur** — seluruh business requirement (BR-xx) modul terimplementasi & lulus uji happy-path + minimal 1 edge case.
2. **Print byte-compatible** — dokumen cetak lulus visual diff & sign-off operator.
3. **Numerator** — penomoran atomic, format identik legacy, lulus uji konkurensi.
4. **Data migration** — reconciliation cocok (row count + sum nominal) pada subset & full.
5. **Performa** — memenuhi target di [05 §NFR](05_NFR_dan_Kebutuhan_Teknis.md) (dashboard, form, real-time).
6. **Keamanan** — authorization per aksi, audit log aktif, tidak ada secret hardcoded.
7. **Audit log** — semua aksi tulis tercatat.
8. **Backup drill** — backup & restore teruji (untuk modul Backup & cutover).

---

## 7. Pertanyaan Terbuka

Lihat [01_BRD.md §8](01_BRD.md) — keputusan multi-tenant, retensi backup, e-Faktur H2H, provider WA, modul BO dormant, status `marketingjualold`.
