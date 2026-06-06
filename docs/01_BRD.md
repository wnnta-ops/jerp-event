# BRD — Business Requirements Document

**eProperty (Clone) — Property Developer ERP**
Companion: [00_README.md](00_README.md) · [02_PRD.md](02_PRD.md) · [03_ERD.md](03_ERD.md) · [04_BusinessFlow.md](04_BusinessFlow.md) · [05_NFR_dan_Kebutuhan_Teknis.md](05_NFR_dan_Kebutuhan_Teknis.md)

> Dokumen ini fokus pada **kebutuhan bisnis** (apa & mengapa), bukan implementasi teknis (bagaimana). Untuk fitur produk lihat PRD; untuk alur proses lihat Business Flow.

---

## 1. Latar Belakang & Problem Statement

eProperty adalah suite ERP terintegrasi untuk pengembang properti (residensial / mixed-use) di Indonesia, yang menangani seluruh siklus hidup proyek properti — dari registrasi calon pembeli (NUP), launching unit, kontrak (PPJB/AJB), pembiayaan KPR, penagihan, sampai serah terima (BAST) dan pembukuan finansial (GL/AR/AP/CB).

Sistem yang berjalan saat ini (tenant MUP / Java Residence) adalah hasil akumulasi belasan tahun, dengan masalah berikut:

| # | Masalah | Dampak bisnis |
| --- | --- | --- |
| P1 | Teknologi usang (ASP.NET WebForms 3.5/4.x precompiled, tanpa source code) | Sulit/ tidak bisa dimodifikasi; bergantung vendor; risiko keberlangsungan |
| P2 | 5 aplikasi terpisah + 17 database SQL Server | Kompleks dirawat, backup/restore rumit, integrasi rapuh (cross-DB query, sync `.vbs`) |
| P3 | Otentikasi terpisah per aplikasi (forms-auth per sub-app) | Pengalaman pengguna terpecah, tata kelola akses lemah |
| P4 | UI postback non-responsif, tidak mobile-friendly | Produktivitas sales/operasional terbatas, terutama saat hari-H launching |
| P5 | Kredensial & token tersebar di config (plaintext) | Risiko keamanan tinggi |
| P6 | Job terjadwal berbasis `.vbs` yang mem-POST ke halaman `.aspx` | Rapuh, sulit dimonitor, tidak ada retry/observability |

**Kebutuhan:** membangun ulang sistem dengan arsitektur modern (Laravel 11 monolith modular + MySQL 8 tunggal) **tanpa mengubah** proses bisnis, terminologi, kode dokumen, dan format cetak yang sudah dikenal pengguna.

---

## 2. Tujuan Bisnis (Business Objectives)

| Kode | Tujuan | Indikator keberhasilan (KPI) |
| --- | --- | --- |
| OBJ-1 | Memodernkan platform agar dapat dirawat in-house | Source code dimiliki sendiri; perubahan fitur dapat dirilis mandiri |
| OBJ-2 | Menyatukan 5 aplikasi & 17 DB menjadi 1 sistem + 1 DB | Single sign-on; satu prosedur backup/restore |
| OBJ-3 | Mempercepat operasional sales & hari-H launching | Waktu proses closing NUP turun; update peta unit real-time < 1 detik |
| OBJ-4 | Memperkuat keamanan & tata kelola | 2FA untuk role kritis; audit trail 100% aksi tulis; tidak ada secret hardcoded |
| OBJ-5 | Menjaga kesinambungan dokumen & data | Format cetak dikenali; migrasi data nol kehilangan (reconciliation cocok) |
| OBJ-6 | Menyiapkan fondasi multi-tenant (opsional masa depan) | Kolom `tenant_id` siap; isolasi data per tenant |

---

## 3. Stakeholder & Peran

| Peran | Kebutuhan utama | Modul yang dipakai |
| --- | --- | --- |
| **Sales / Marketing** | Daftar customer, reservasi, closing, lihat stok unit live, komisi | FO: Launching, MarketingSales, NUP, Komisi |
| **Customer Service / Collection** | Tagih, follow-up, surat jatuh tempo, surat lunas | FO: Collection, FinanceAR, Legal |
| **Finance / AR** | Terima pembayaran (TTS/BKM), VA, faktur pajak, rekonsiliasi | FO: FinanceAR, Approval; BO: AR, CB |
| **Accounting** | Jurnal, buku besar, hutang/piutang, kas-bank, inventory, payroll | BO: GL, AR, AP, CB, IVT, HR |
| **Legal** | PPJB, AJB, IMB/PBG, BAST, sertifikat | FO: Legal; BO: LGL |
| **KPR Officer** | Proses kredit bank (BI Checking → akad → pencairan → retensi) | BankLoan; FO: KpaView |
| **Admin Sales / Master Data** | Master agent, unit, price list, skema, site plan | FO: Adminjual, Settings; System: Masters |
| **IT / Operations** | User & akses, backup, monitoring, konfigurasi | System; Backup |
| **Calon Pembeli (publik)** | Mendaftar mandiri & dapat nomor antrian (NUP) | NUP publik (`/nup/daftar`) |

### 3.1 RACI Ringkas (proses inti)

| Proses | Sales | Finance | KPR Officer | Legal | Accounting | Manager/Approver |
| --- | --- | --- | --- | --- | --- | --- |
| Registrasi NUP | A/R | I | - | - | - | - |
| Closing & Kontrak | R | C | I | I | I | A |
| Pembayaran (TTS→BKM) | I | A/R | I | - | I | C |
| Proses KPR | C | I | A/R | I | - | A |
| Dokumen Legal (PPJB→AJB→BAST) | I | I | I | A/R | - | A |
| Penagihan/Collection | I | R | - | - | I | A |
| Posting Jurnal (FOBO) | - | C | - | - | A/R | A |
| Adjustment/Batal kontrak | R | C | I | I | I | **A** |

(R=Responsible, A=Accountable, C=Consulted, I=Informed)

---

## 4. Ruang Lingkup Bisnis

### 4.1 In-Scope (per fase rilis)

| Fase | Cakupan |
| --- | --- |
| Fase 1 (Fondasi) | System (user, role, master data, numerator, audit), otentikasi SSO, dashboard |
| Fase 2 (BankLoan) | Modul KPR penuh (skema sudah modern, paling siap di-port) |
| Fase 3 (FO operasional) | NUP publik, Launching, MarketingSales, FinanceAR, Collection, Legal, Komisi, Approval, Adminjual |
| Fase 4 (BO akuntansi) | GL, AR, AP, CB, IVT, HR + FOBO bridge |
| Fase 5 (Backup & cutover) | Backup terjadwal (pengganti TuxArch), dual-run, cutover bertahap |

### 4.2 Out-of-Scope (rilis awal)

- Aplikasi mobile native (semua tetap web responsif).
- Data warehouse / BI lanjutan di luar dashboard collection & finance AR yang ada.
- Portal customer pasca-pembelian lengkap (saat ini hanya registrasi NUP yang publik).
- Multi-bahasa (hanya id-ID; English hanya untuk error developer).

---

## 5. Kebutuhan Bisnis per Area (Business Requirements)

> Penomoran `BR-<AREA>-<n>`. Setiap kebutuhan ditelusuri ke fitur produk (PRD) dan alur (Business Flow).

### 5.1 NUP — Registrasi Publik (BR-NUP)

| Kode | Kebutuhan |
| --- | --- |
| BR-NUP-01 | Calon pembeli dapat mendaftarkan diri secara mandiri (online) untuk memperoleh **Nomor Urut Pemilihan (NUP)** sebelum hari launching. |
| BR-NUP-02 | Form registrasi mencakup data diri (sesuai KTP), data pasangan (jika menikah), alamat KTP & surat-menyurat, preferensi unit (tidak mengikat), referensi sales, serta unggah dokumen (KTP, NPWP, KTP pasangan). |
| BR-NUP-03 | Pendaftaran harus terlindung dari bot/spam (verifikasi captcha). |
| BR-NUP-04 | Setelah valid, sistem membuat data customer (lead) dan mengirim konfirmasi nomor antrian (email dan/atau WhatsApp). |
| BR-NUP-05 | Tim internal dapat memverifikasi, merevisi, menandai lunas-bayar, atau melakukan refund atas NUP. |

### 5.2 Launching — Hari-H (BR-LNC)

| Kode | Kebutuhan |
| --- | --- |
| BR-LNC-01 | Operator dapat mengaktifkan event launching dan mengelola reception (registrasi kedatangan, hold, unhold, cancel). |
| BR-LNC-02 | Sistem dapat **memanggil NUP** sesuai urutan (otomatis & manual) dan menampilkannya di layar besar/TV. |
| BR-LNC-03 | Status ketersediaan unit pada peta site plan harus terupdate **real-time** (tersedia/booking/terjual) untuk mencegah double-booking. |
| BR-LNC-04 | Closing NUP berjalan sebagai wizard bertahap; pembayaran booking/NUP dapat diterima on-the-spot (TTS/BKM). |
| BR-LNC-05 | Tersedia kalkulator skema & ilustrasi KPR untuk membantu customer memilih. |

### 5.3 Marketing / Sales (BR-MKT)

| Kode | Kebutuhan |
| --- | --- |
| BR-MKT-01 | Mengelola master customer, unit, price list, skema pembayaran (cash/cicilan/KPR), diskon, dan bunga. |
| BR-MKT-02 | Membuat reservasi/booking dengan batas waktu (expire) dan jadwal tagihan. |
| BR-MKT-03 | Mengubah reservasi: ganti nama, ganti unit, reschedule, custom tagihan, batal — **melalui approval bertingkat**. |
| BR-MKT-04 | Menerbitkan kontrak (PPJB) beserta penjadwalan tagihan dan adjustment (reguler & PPN). |
| BR-MKT-05 | Mendukung penjualan langsung tanpa NUP (closing langsung) dengan persetujuan. |

### 5.4 KPR / Bank Loan (BR-KPR)

| Kode | Kebutuhan |
| --- | --- |
| BR-KPR-01 | Mengelola pengajuan kredit ke satu/lebih bank per kontrak, lengkap dengan checklist berkas persyaratan per bank. |
| BR-KPR-02 | Mencatat hasil **BI Checking** debitur beserta dokumen pendukung. |
| BR-KPR-03 | Mengelola tahapan: pre-interview → interview → **SP3K** → **akad** → realisasi pencairan (**RA**). |
| BR-KPR-04 | Mengelola **retensi** (dana ditahan bank): pengajuan (**PR**) dan realisasi pencairan (**RR**), termasuk sisa (remaining amount). |
| BR-KPR-05 | Mendukung **buyback** (pembelian kembali/pembatalan) dengan posting balik. |
| BR-KPR-06 | Data kontrak & customer harus tersinkronisasi dua arah dengan modul FO (sumber unit & customer). |
| BR-KPR-07 | Menyediakan dashboard KPI kontrak & reminder otomatis (BI Checking jatuh tempo, pengajuan retensi). |

### 5.5 Finance AR (BR-FIN)

| Kode | Kebutuhan |
| --- | --- |
| BR-FIN-01 | Menerima pembayaran customer melalui **TTS** dan mem-posting menjadi **BKM**. |
| BR-FIN-02 | Mengelola **Virtual Account** per bank (BCA/BNI/BRI/Mandiri) termasuk impor/ekspor file standar bank. |
| BR-FIN-03 | Mengelola **Bilyet Giro** (status kliring/tolak), **MEMO** (cashback/koreksi/refund), dan **TTR** (kuitansi resmi). |
| BR-FIN-04 | Menerbitkan & memposting **Faktur Pajak** (integrasi e-Faktur DJP). |
| BR-FIN-05 | Menangani **transfer anonim** (dana masuk tak teridentifikasi) hingga teralokasi ke customer/kontrak. |
| BR-FIN-06 | Pembayaran via gateway (Xendit VA/payment link) otomatis menghasilkan TTS melalui callback. |

### 5.6 Collection (BR-COL)

| Kode | Kebutuhan |
| --- | --- |
| BR-COL-01 | Menghasilkan **PJT** (pemberitahuan jatuh tempo) otomatis berdasarkan tanggal jatuh tempo tagihan. |
| BR-COL-02 | Menghitung **denda** keterlambatan otomatis. |
| BR-COL-03 | Mencatat **follow-up** penagihan (telepon/WA/email/surat) beserta riwayat & tindak lanjut. |
| BR-COL-04 | Menerbitkan **SKL** (surat keterangan lunas) saat kontrak lunas. |
| BR-COL-05 | Menyediakan dashboard tunggakan & daftar kontrak menunggak. |

### 5.7 Legal (BR-LGL)

| Kode | Kebutuhan |
| --- | --- |
| BR-LGL-01 | Mengelola siklus dokumen: **PPJB → AJB → IMB/PBG → ST/BAST → Sertifikat**, masing-masing dengan target date, status proses, dan tanda tangan (TTD). |
| BR-LGL-02 | Mengelola berkas pendukung per dokumen dan akad dengan bank. |

### 5.8 Komisi (BR-KOM)

| Kode | Kebutuhan |
| --- | --- |
| BR-KOM-01 | Menghitung **closing fee (CF)** dan **komisi** sales/agent berdasarkan skema komisi multi-termin. |
| BR-KOM-02 | Mengelola **reward** dan pemotongan/refund komisi. |
| BR-KOM-03 | Menerbitkan **BLT** (bukti laporan transaksi) untuk Sales, Kantor, dan Koordinator. |
| BR-KOM-04 | Komisi ter-trigger setelah kontrak final / pencairan KPR. |

### 5.9 Accounting / Back Office (BR-ACC)

| Kode | Kebutuhan |
| --- | --- |
| BR-ACC-01 | Menyelenggarakan **General Ledger**: chart of account, jurnal, approval, closing periode, neraca/laba-rugi/arus kas. |
| BR-ACC-02 | Mengelola **AP** (hutang: PO & non-PO, kuitansi, memorial, aging) dan **AR** (piutang internal). |
| BR-ACC-03 | Mengelola **Cash & Bank** (BKM/BKK/BGM/BGK/BS/ADM, rekonsiliasi bank). |
| BR-ACC-04 | Mengelola **Inventory** (penerimaan/pengeluaran barang, PR, price list) dan **HR** (karyawan, departemen, budget request). |
| BR-ACC-05 | **Posting otomatis jurnal dari FO ke BO** (FOBO bridge): kas masuk, sales, komisi, buyback, refund. |
| BR-ACC-06 | Mendukung **multi-company** dan **multi-cost-center**. |

### 5.10 Pembayaran (BR-PAY)

| Kode | Kebutuhan |
| --- | --- |
| BR-PAY-01 | Integrasi gateway pembayaran (Xendit): pembuatan VA dinamis & payment link, penanganan kedaluwarsa, dan callback rekonsiliasi otomatis. |

### 5.11 Backup & Operasi (BR-BCK)

| Kode | Kebutuhan |
| --- | --- |
| BR-BCK-01 | Backup database otomatis terjadwal (harian) + kompresi + retensi. |
| BR-BCK-02 | Notifikasi kegagalan backup (email + Trello) bila gagal beruntun. |
| BR-BCK-03 | Dashboard monitoring backup & laporan bulanan. |

### 5.12 Sistem & Keamanan (BR-SYS)

| Kode | Kebutuhan |
| --- | --- |
| BR-SYS-01 | Manajemen user, role, dan hak akses granular (per modul/aksi). |
| BR-SYS-02 | **Single sign-on** lintas seluruh modul. |
| BR-SYS-03 | **Audit trail** lengkap untuk semua aksi tulis (kepatuhan legal & pajak). |
| BR-SYS-04 | **Numerator dokumen** terpusat dengan reset periodik dan multi-company. |
| BR-SYS-05 | Pengaturan global (email, batas reserve, format dokumen, master data). |

---

## 6. Aturan Bisnis Utama (Business Rules)

| Kode | Aturan |
| --- | --- |
| RULE-01 | **Numerator atomic** — nomor dokumen (KPR/RA/PR/RR/TTS/BKM/PPJB/AJB/dll.) tidak boleh diduplikasi; reset per tahun/bulan sesuai konfigurasi; sequence per company. |
| RULE-02 | **Batas reserve** — reservasi memiliki masa berlaku (expire); lewat batas otomatis kedaluwarsa & unit kembali tersedia. |
| RULE-03 | **Approval bertingkat** — perubahan kontrak (ADJ, batal, custom tagihan, diskon, ganti nama, ganti unit, reschedule) wajib disetujui sesuai batas wewenang (limit nominal & urutan approver). |
| RULE-04 | **Denda otomatis** — tagihan lewat jatuh tempo dikenakan denda sesuai parameter. |
| RULE-05 | **Retensi bank** — sebagian dana akad ditahan bank; pelepasan via pengajuan (PR) → realisasi (RR); sisa (remaining) dilacak hingga nol. |
| RULE-06 | **Anti double-booking** — satu unit tidak boleh dipesan dua customer; status unit dikunci real-time saat dipilih. |
| RULE-07 | **Posting jurnal seimbang** — setiap posting GL harus balance (total debet = total kredit). |
| RULE-08 | **Status transition terkontrol** — entitas berstatus (Kontrak, Contract KPR, Tagihan, NUP) hanya boleh berpindah status sesuai alur yang sah (lihat [04_BusinessFlow.md](04_BusinessFlow.md)). |
| RULE-09 | **Soft delete & audit** — data transaksi tidak dihapus permanen; semua perubahan tercatat dengan pelaku & waktu. |
| RULE-10 | **Lokalisasi** — semua nilai uang & tanggal mengikuti format Indonesia (id-ID); dokumen cetak berbahasa Indonesia. |

---

## 7. Asumsi, Batasan, Dependensi, Risiko

### 7.1 Asumsi
- Proses bisnis legacy tetap berlaku; clone tidak mengubah proses, hanya platform.
- File `.bak` tersedia untuk migrasi data; DB yang tidak ada `.bak`-nya diperoleh dari production.
- Pengguna sudah terbiasa dengan terminologi & format dokumen yang ada.

### 7.2 Batasan (Constraints)
- Format dokumen cetak harus dikenali pengguna (tidak boleh berubah signifikan tanpa sign-off operator).
- Kode dokumen & numerator harus identik dengan legacy.
- Format file backup `.bak` lama harus tetap dapat di-restore untuk kebutuhan arsip/audit.

### 7.3 Dependensi
- Layanan eksternal: Xendit, Google reCAPTCHA, SMTP (Gmail), Trello, e-Faktur DJP, BI Checking/SLIK, provider WhatsApp, format file VA bank.
- Infrastruktur: MySQL 8, Redis, server WebSocket (Reverb), object storage untuk file.

### 7.4 Risiko Bisnis & Mitigasi

| Risiko | Dampak | Mitigasi |
| --- | --- | --- |
| Skema FO/BO sebagian inferred | Salah model data | Restore `.bak` & verifikasi sebelum implementasi final |
| Format cetak tidak byte-compatible | Penolakan pengguna/customer | Visual diff + sign-off operator sebelum go-live |
| Migrasi data kehilangan/duplikat | Sengketa finansial/legal | Reconciliation (row count + sum amount) + dual-run sebelum cutover |
| Porting stored procedure KPR (103 migrasi) | Logika tak konsisten | Port berurutan, semantik identik, test per prosedur |
| Provider WA/e-Faktur belum final | Fitur tertunda | Abstraksi provider; tetapkan vendor di fase perencanaan |
| Retensi backup 365 hari < kebutuhan pajak (10 thn) | Risiko audit pajak | Tetapkan kebijakan retensi sesuai regulasi (lihat pertanyaan terbuka) |

---

## 8. Pertanyaan Terbuka (perlu keputusan business owner)

1. **Multi-tenant**: clone tetap single-tenant (MUP) atau disiapkan SaaS multi-developer?
2. **Retensi backup**: berapa tahun untuk kepatuhan pajak Indonesia (umumnya 10 tahun)?
3. **e-Faktur**: tetap CSV/manual atau pindah ke Host-to-Host (API DJP)?
4. **WhatsApp Blast**: provider mana (Wablas/Twilio/self-hosted)?
5. **Modul BO dormant** (`AG`, `SA`, `ARC`, `BC`, `CS`, `SP`, `EM`, `TN`, `UC`): mana yang masih dipakai dan perlu di-clone?
6. **`marketingjualold`**: apakah masih ada data/akses aktif sebelum diabaikan?
