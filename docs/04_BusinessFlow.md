# Business Flow — Alur Bisnis eProperty

**eProperty (Clone) — Property Developer ERP**
Companion: [00_README.md](00_README.md) · [01_BRD.md](01_BRD.md) · [02_PRD.md](02_PRD.md) · [03_ERD.md](03_ERD.md) · [05_NFR_dan_Kebutuhan_Teknis.md](05_NFR_dan_Kebutuhan_Teknis.md)

> Dokumen ini menggambarkan **alur proses bisnis** end-to-end & per proses dengan diagram Mermaid. Setiap sub-flow menyertakan aktor, dokumen, status, event integrasi, dan **pemetaan ke padanan Laravel 11**. Diagram dapat dirender di GitHub & VS Code (ekstensi Markdown Preview Mermaid).

---

## 1. Flow Induk — Siklus Hidup Penjualan Properti

```mermaid
flowchart TD
    A[Calon pembeli daftar NUP - publik] --> B[Aktivasi Launching]
    B --> C[Hari-H: Reception & Panggil NUP]
    C --> D[Pilih Unit - peta live]
    D --> E[Closing NUP 4 langkah]
    E --> F[Reservasi / Booking]
    F --> G[Kontrak / PPJB]
    G --> H{Cara bayar?}
    H -->|KPR| I[Proses KPR / Bank Loan]
    H -->|Cash / Cicilan| J[Jadwal Tagihan]
    I --> K[Finance AR: TTS to BKM]
    J --> K
    K --> L[Collection: PJT / denda / SKL]
    G --> M[Legal: PPJB to AJB to IMB to BAST to Sertifikat]
    G --> N[Komisi: CF to Komisi to Reward to BLT]
    K --> O[Posting Jurnal FO to BO - FOBO]
    I --> O
    N --> O
    O --> P[General Ledger / Closing]
```

**Aktor lintas tahap:** calon pembeli → sales/marketing → finance AR → KPR officer → legal → collection → accounting.
**Cabang paralel** setelah kontrak: KPR (jika dibiayai bank), Legal (selalu), Finance/Collection (selalu), Komisi (setelah kontrak final/pencairan).

---

## 2. Sub-flow 1 — Registrasi NUP Publik (BR-NUP)

```mermaid
sequenceDiagram
    actor C as Calon Pembeli
    participant W as Web NUP daftar
    participant RC as reCAPTCHA v3
    participant S as NupRegistrationService
    participant DB as MySQL
    participant Q as Queue (Horizon)
    C->>W: Isi form (data diri, pasangan, alamat, upload KTP/NPWP)
    C->>W: Centang pernyataan + submit
    W->>RC: Verifikasi token
    RC-->>W: Skor >= ambang
    W->>S: Validasi (FormRequest) + simpan file
    S->>DB: Insert fo_customers (lead) + akun user terbatas + fo_nup (no urut)
    S->>Q: Dispatch KirimKonfirmasiNupJob
    Q-->>C: Email + WhatsApp (nomor antrian)
    W-->>C: Tampilkan nomor NUP
```

- **Output**: customer (status lead) + entri `fo_nup` (status BOOKING) + notifikasi.
- **Validasi**: NIK, email, ukuran/MIME file (maks 5 MB), captcha.
- **Padanan Laravel**: route publik tanpa auth + rate limit; Livewire/Blade form; FormRequest; Service; Job (email/WA) di queue `email`/`whatsapp`.
- **Internal lanjutan**: tim verifikasi/revisi/lunas-bayar/refund via FO NUP.

---

## 3. Sub-flow 2 — Hari-H Launching (BR-LNC)

```mermaid
flowchart LR
    subgraph Reception
      R1[Regis kedatangan NUP] --> R2{Status}
      R2 -->|Hold| R3[Hold]
      R2 -->|Cancel| R4[Cancel]
      R2 -->|Lanjut| P1[Panggil NUP]
    end
    P1 --> P2[Tampil di layar TV]
    P2 --> U1[Customer pilih unit di peta live]
    U1 --> U2{Unit tersedia?}
    U2 -->|Tidak| U1
    U2 -->|Ya, kunci real-time| CL[Closing NUP 4 langkah]
    CL --> PAY[Bayar booking - TTS/BKM on the spot]
    PAY --> RES[Buat Reservasi]
```

```mermaid
sequenceDiagram
    actor SL as Sales
    participant UI as UnitPetaLive (Livewire)
    participant RV as Reverb (WebSocket)
    participant SVC as UnitService
    participant DB as MySQL
    SL->>UI: Klik unit X
    UI->>SVC: Kunci unit X (lock)
    SVC->>DB: fo_units.status = BOOKING (atomic)
    SVC-->>RV: broadcast UnitStatusChanged(X)
    RV-->>UI: Semua layar update (< 1 detik)
    Note over UI,RV: Mencegah double-booking (RULE-06)
```

- **Real-time**: legacy SignalR `unitHub` → **Laravel Reverb**, channel presence `project.{id}.units`.
- **Kalkulator skema & ilustrasi KPR** membantu keputusan customer.
- **Status unit**: TERSEDIA → BOOKING (saat dipilih) → TERJUAL (saat closing) / kembali TERSEDIA (jika batal/expire).

---

## 4. Sub-flow 3 — Reservasi & Kontrak/PPJB + Approval (BR-MKT, RULE-03)

```mermaid
flowchart TD
    RS[Reservasi/Booking + expire date] --> JT[Generate Jadwal Tagihan]
    JT --> KO[Buat Kontrak / PPJB]
    KO --> ST{Perubahan kontrak?}
    ST -->|Ganti Nama GN| AP
    ST -->|Ganti Unit GU| AP
    ST -->|Reschedule| AP
    ST -->|Diskon| AP
    ST -->|Custom Tagihan| AP
    ST -->|Adjustment ADJ| AP
    ST -->|Batal| AP
    AP[Approval bertingkat - 2 step] --> CK{Disetujui?}
    CK -->|Ya| AParr[Terapkan perubahan]
    CK -->|Tidak| REJ[Ditolak - kembali]
    RS -.->|expire lewat| EXP[Batal otomatis, unit kembali TERSEDIA]
```

```mermaid
stateDiagram-v2
    [*] --> RESERVASI
    RESERVASI --> BOOKING
    BOOKING --> KONTRAK_DRAFT: closing
    KONTRAK_DRAFT --> KONTRAK_AKTIF: PPJB ditandatangani
    KONTRAK_AKTIF --> LUNAS: semua tagihan lunas
    KONTRAK_AKTIF --> BATAL: approval batal
    RESERVASI --> BATAL: expire / approval
    BOOKING --> BATAL: expire / approval
```

- **Approval**: setiap jenis perubahan (ADJ/Batal/Custom/Diskon/GN/GU/Reschedule) melewati 2 langkah approve sesuai batas wewenang (`fo_approval_detail.limit_nominal`, `urutan`).
- **Padanan Laravel**: Event `KontrakChangeRequested` → masuk antrian approval (`fo_approval`); Policy + middleware untuk wewenang; setelah disetujui jalankan Service penerap perubahan.
- **Expire reservasi** (RULE-02): Scheduler harian menandai reservasi kedaluwarsa & membebaskan unit.

---

## 5. Sub-flow 4 — Proses KPR / Bank Loan (BR-KPR)

```mermaid
flowchart TD
    SYNC[Kontrak FO disinkron ke BankLoan] --> CON[Buat Contract KPR]
    CON --> PI[Pre-Interview: sumber dana, tujuan]
    PI --> BIC[BI Checking + upload dokumen]
    BIC --> CB[Ajukan ke Bank - checklist requirement per bank]
    CB --> IV[Interview]
    IV --> SP[SP3K - persetujuan bank]
    SP --> AK[Akad / Agreement + notaris]
    AK --> RA[Realisasi Akad - RA pencairan]
    RA --> RET{Ada retensi?}
    RET -->|Ya| PR[Pengajuan Retensi - PR]
    PR --> RR[Realisasi Retensi - RR + remaining]
    RR --> DONE[Selesai]
    RET -->|Tidak| DONE
    CON -.->|pembatalan| BB[Buyback - posting balik]
```

```mermaid
sequenceDiagram
    participant FO as Modul FO Kontrak
    participant EV as Event Bus
    participant BL as Modul BankLoan
    participant FA as Finance AR
    FO->>EV: KontrakCreated / KontrakUpdated
    EV->>BL: SyncContractToBankLoan (Listener, queue 'sync')
    BL->>BL: Proses lifecycle (BIChecking..RA..RR)
    BL->>EV: AgreementRealized (RA)
    EV->>FA: Tarik data TTS/BKM (pengganti TTSBkmService.asmx)
    BL->>EV: ContractStatusChanged
    EV->>FO: Update status_kpr di kontrak
```

- **Sinkronisasi dua arah** menggantikan stored procedure `SP_FOtoStandAlone_*` legacy dengan **Event/Listener** (queue `sync`), idempotent.
- **Status**: DRAFT → REGISTERED → ACTIVE → CLOSED/CANCELLED (lihat [03_ERD.md §8](03_ERD.md)).
- **Reminder** (BR-KPR-07): Horizon job harian — BI Checking jatuh tempo, pengajuan retensi (output Excel).
- **Retensi** (RULE-05): `remaining_amount` dilacak hingga nol.

---

## 6. Sub-flow 5 — Finance AR (BR-FIN)

```mermaid
flowchart TD
    P{Sumber pembayaran} -->|Tunai/Transfer| T1[Input TTS]
    P -->|VA bank| VA[VA masuk - impor file bank]
    P -->|Giro| BG[Bilyet Giro - kliring]
    P -->|Xendit| XEN[Callback Xendit auto-TTS]
    VA --> T1
    BG --> T1
    XEN --> T1
    T1 --> POST[Posting TTS to BKM]
    POST --> TTR[Terbitkan TTR - kuitansi resmi]
    POST --> FP[Faktur Pajak / e-Faktur]
    POST --> JNL[Event: kas masuk - posting GL via FOBO]
    AN[Transfer Anonim - tak teridentifikasi] -->|alokasi| T1
    MEMO[MEMO: cashback/koreksi/refund] --> JNL
```

- **TTS → BKM**: TTS (status BELUM_POSTING) → posting → BKM (kumpulan TTS).
- **VA**: impor/ekspor file format bank (BCA/BNI/BRI/Mandiri) — job parsing per bank.
- **Transfer anonim**: dana masuk UNALLOCATED → dialokasikan ke customer/kontrak → jadi TTS.
- **Padanan Laravel**: Service per dokumen; Event `KasMasukPosted` memicu FOBO; Job impor/ekspor VA di queue.

---

## 7. Sub-flow 6 — Collection (BR-COL)

```mermaid
flowchart TD
    SCH[Scheduler harian] --> CK{Tagihan lewat jatuh tempo?}
    CK -->|Ya| PJT[Generate PJT otomatis]
    CK -->|Ya| DN[Hitung denda otomatis]
    PJT --> FU[Follow-up: telp/WA/email/surat]
    DN --> FU
    FU --> HS[Catat hasil + next action]
    HS --> PAY{Lunas?}
    PAY -->|Belum| FU
    PAY -->|Ya| SKL[Terbitkan SKL - Surat Keterangan Lunas]
```

- **PJT & denda otomatis** (BR-COL-01/02, RULE-04): menggantikan `AutoPJT.vbs` & `AutoDenda.vbs` legacy dengan **Scheduler + Job** (queue `default`).
- **Follow-up**: histori per kontrak; grouping untuk distribusi tim.
- **SKL**: saat kontrak LUNAS.

---

## 8. Sub-flow 7 — Legal (BR-LGL)

```mermaid
flowchart LR
    PPJB[PPJB + TTD] --> AJB[AJB + notaris + TTD]
    AJB --> IMB[IMB / PBG]
    IMB --> ST[ST / BAST - serah terima + TTD]
    ST --> SERT[Sertifikat]
    PPJB -. target date & status proses .-> MON[Monitoring per dokumen]
    AJB -. target .-> MON
    IMB -. target .-> MON
    ST -. target .-> MON
    SERT -. target .-> MON
```

- Setiap dokumen punya **target date**, **status proses**, **TTD**, dan **berkas** pendukung.
- Akad dengan bank tercatat (terhubung ke proses KPR).
- **Padanan Laravel**: satu tabel per jenis dokumen (`fo_legal_*`), Filament/Livewire untuk monitoring target; notifikasi mendekati target date.

---

## 9. Sub-flow 8 — Komisi (BR-KOM)

```mermaid
flowchart TD
    TRG{Trigger} -->|Kontrak final / RA cair| CF[Generate Closing Fee - CF]
    CF --> KOM[Hitung Komisi - skema multi-termin]
    KOM --> RW[Reward]
    KOM --> POT[Potong / Refund - CFP/CFR]
    KOM --> BLT[Terbitkan BLT: Sales / Kantor / Koor]
    BLT --> JNL[Posting komisi ke GL via FOBO]
```

- **Trigger**: setelah kontrak final atau pencairan KPR (event `KontrakFinalized` / `AgreementRealized`).
- **Skema komisi** multi-termin + reward; pemotongan/refund.
- **BLT** 3 jenis (Sales/Kantor/Koordinator) — cetak masing-masing.

---

## 10. Sub-flow 9 — Posting Jurnal FO → BO (FOBO Bridge, BR-ACC-05)

```mermaid
sequenceDiagram
    participant FO as Modul FO
    participant EV as Event Bus
    participant FOBO as FoBoBridge (Listener)
    participant GL as bo_jurnal_header/detil
    FO->>EV: KasMasukPosted / SalesClosed / KomisiGenerated / BuybackPosted / RefundCreated
    EV->>FOBO: Handle (queue 'sync', idempotent)
    FOBO->>FOBO: Tentukan akun debet/kredit (mapping)
    FOBO->>GL: Insert jurnal (balance: total debet = kredit)
    Note over FOBO,GL: RULE-07 jurnal harus seimbang
    GL->>GL: Approval -> Posted -> Closing periode
```

| Event FO | Jurnal BO (ilustratif) |
| --- | --- |
| Kas masuk (BKM) | D: Kas/Bank — K: Piutang/Uang Muka |
| Sales/closing | D: Piutang — K: Pendapatan/Persediaan unit |
| Komisi | D: Beban Komisi — K: Hutang Komisi |
| Buyback | jurnal balik penjualan |
| Refund lebih bayar | D: Hutang Customer — K: Kas/Bank |

- **Padanan Laravel**: menggantikan SP/halaman `FOBO/*.aspx` dengan **Listener** (idempotent, outbox-style). Mapping akun di konfigurasi.

---

## 11. Sub-flow 10 — Pembayaran Xendit (BR-PAY)

```mermaid
sequenceDiagram
    actor C as Customer
    participant X as Xendit
    participant WH as Webhook (/webhooks/xendit)
    participant SVC as PaymentService
    participant FA as Finance AR
    FA->>X: Buat VA dinamis / payment link
    C->>X: Bayar
    X->>WH: Callback (signature-verified)
    WH->>SVC: Validasi signature + idempotency
    SVC->>FA: Auto-buat TTS
    FA-->>SVC: TTS dibuat -> posting BKM
    Note over WH,SVC: Verifikasi tanda tangan webhook (bukan token statis legacy)
```

- Menggantikan `XenditPayment.asmx` + `AutoXendit.vbs` legacy.
- **Keamanan**: verifikasi signature webhook (bukan token statis `abc123`).
- **Idempotency**: cegah TTS ganda dari callback berulang (queue `webhook`).

---

## 12. Sub-flow 11 — Backup Terjadwal (BR-BCK)

```mermaid
flowchart TD
    SCH[Laravel Scheduler] --> JOB[BackupRunJob - Horizon queue 'backup']
    JOB --> BK[Spatie Backup: dump DB + file]
    BK --> ZIP[Kompresi + simpan ke disk local/S3]
    ZIP --> RET[Terapkan retensi]
    JOB --> LOG[Catat backup_logs: sukses/gagal]
    LOG --> CK{Gagal beruntun?}
    CK -->|Ya| ALERT[Alert email + kartu Trello]
    SCH --> RPT[Laporan bulanan - email + Trello]
```

- Menggantikan TuxArch (Windows Service) dengan **Scheduler + Horizon job + Spatie Backup**.
- Dashboard & laporan via Filament (modul Backup).

---

## 13. Status Transition Ringkas (semua entitas berstatus)

```mermaid
stateDiagram-v2
    state "Unit" as U {
        [*] --> TERSEDIA
        TERSEDIA --> BOOKING: dipilih lock real-time
        BOOKING --> TERJUAL: closing
        BOOKING --> TERSEDIA: batal/expire
        TERJUAL --> TERSEDIA: buyback
    }
    state "NUP" as N {
        [*] --> BOOKING_NUP
        BOOKING_NUP --> LUNAS_NUP: bayar
        LUNAS_NUP --> REFUND: refund
        BOOKING_NUP --> BATAL_NUP: batal
    }
    state "Tagihan" as T {
        [*] --> BELUM
        BELUM --> TERLAMBAT: lewat jatuh tempo
        BELUM --> LUNAS: bayar
        TERLAMBAT --> LUNAS: bayar + denda
    }
    state "Contract KPR" as K {
        [*] --> DRAFT
        DRAFT --> REGISTERED
        REGISTERED --> ACTIVE: akad
        ACTIVE --> CLOSED
        ACTIVE --> CANCELLED: buyback
    }
```

---

## 14. Pemetaan Mekanisme Legacy → Laravel 11

| Mekanisme legacy | Padanan Laravel 11 |
| --- | --- |
| SignalR (`unitHub`) update peta unit | **Laravel Reverb** broadcast (channel presence) |
| Job `.vbs` POST ke `.aspx` (AutoDailyBackUp, AutoDailyReport, AutoDenda, AutoPJT, AutoXendit) | **Scheduler** + **Horizon Job** |
| Stored procedure `SP_FOtoStandAlone_*` (sync FO↔KPR) | **Event/Listener** (queue `sync`, idempotent) |
| Halaman/SP `FOBO/*` posting jurnal | **Listener** outbox-style FO→BO |
| `XenditPayment.asmx` + token statis | **Webhook** dengan verifikasi signature + idempotency |
| `TTSBkmService.asmx` (KPR tarik data BKM) | **Service/Event** internal antar-modul |
| Postback WebForms (UpdatePanel) | **Livewire 3** (FO) / **Filament 3** (BO) |
| Forms-auth per sub-app | **Sanctum + Spatie Permission** (SSO) |
| wkhtmltopdf | **Browsershot** (Puppeteer) / DomPDF |
| Hangfire (KPR) | **Horizon** (Redis) |
| TuxArch Windows Service | **Scheduler + Spatie Backup** |
