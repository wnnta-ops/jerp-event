
---

## Lampiran D — Diagram

### D.1 Arsitektur Modul (Front Office ↔ Back Office)

```mermaid
flowchart TB
  subgraph FO["FRONT OFFICE — Sales & CRM"]
    CRM["CRM / Leads"]
    SLS["NUP · Hold · Booking · PPJB · TTS · Kuitansi"]
    COL["Collection (Denda/SP/SKL)"]
    KPA["KPA / Bank Loan"]
    LGL["Legal (AJB/BAST/Sertifikat)"]
  end
  subgraph BO["BACK OFFICE — Akuntansi (Batavianet)"]
    GL["General Ledger / Jurnal"]
    APAR["Hutang (AP) · Piutang (AR)"]
    KAS["Kas/Bank · Kas Kecil · Bon · Giro"]
    OPS["Aset · Persediaan · PO · SPK"]
    PJK["Pajak (e-Faktur/SPT) · COGS · Payroll"]
    TTP["Tutup Buku · Saldo Awal"]
  end
  FO -->|"auto-jurnal"| GL
  APAR --> GL
  KAS --> GL
  OPS --> GL
  PJK --> GL
  GL --> TTP
  GL --> LAP["Laporan & Dashboard"]
```

### D.2 Alur Penjualan Unit (End-to-End)

```mermaid
flowchart LR
  A["Lead (CRM)"] --> B["Customer / CIF"]
  B --> C["NUP / Hold"]
  C --> D["Booking"]
  D -->|"diskon"| E["Approval"]
  D --> F["Jurnal Kontrak (Dr Piutang / Cr Pendapatan+PPN)"]
  D --> G["PPJB (print/sign)"]
  G --> H["TTS / Kuitansi (Dr Kas / Cr Piutang)"]
  H --> I["KPA / KPR"]
  I --> J["Legal: AJB · BAST · Sertifikat"]
  J --> K["Komisi"]
```

### D.3 Alur Posting Akuntansi (Integritas GL)

```mermaid
flowchart LR
  T["Transaksi:<br/>AP · AR · Kas · SPK · Aset · Payroll · COGS"] --> S["Service Domain"]
  S --> J["JournalService.post(header, lines)"]
  J -->|"validasi Dr = Cr,<br/>periode tidak terkunci"| L["Buku Besar (GL)"]
  L --> R["Laporan: Neraca · L/R · Arus Kas · Aging"]
  L --> C["Tutup Buku:<br/>bulanan = kunci<br/>tahunan = L/R ke Laba Ditahan"]
```

### D.4 ERD Ringkas (Entitas Inti)

```mermaid
erDiagram
  CUSTOMERS ||--o{ BOOKINGS : memesan
  UNITS ||--o{ BOOKINGS : dipesan
  BOOKINGS ||--o{ CONTRACTS : "menjadi PPJB"
  CONTRACTS ||--o{ CONTRACT_DOCUMENTS : dokumen
  BOOKINGS ||--o{ PAYMENT_ALLOCATIONS : pembayaran
  BS_JOURNALS ||--o{ BS_JOURNAL_LINES : berisi
  BS_ACCOUNTS ||--o{ BS_JOURNAL_LINES : diposting
  BS_AP_INVOICES ||--o{ BS_JOURNALS : "membentuk jurnal"
  BS_AR_INVOICES ||--o{ BS_JOURNALS : "membentuk jurnal"
  BS_WORK_ORDERS ||--o{ BS_WO_PROGRESS : termin
  BS_WORK_ORDERS ||--o{ BS_WO_MATERIALS : "pemakaian barang"
  BS_FIXED_ASSETS ||--o{ BS_DEPRECIATION : penyusutan
  BS_BUDGETS ||--o{ BS_BUDGET_MUTATIONS : mutasi
```

### D.5 Siklus SPK (Kontraktor) — State

```mermaid
stateDiagram-v2
  [*] --> Active : Buat SPK
  Active --> Active : Progress termin / Bayar / Addendum / Pemakaian Barang / Mutasi Progress
  Active --> Cancelled : Pembatalan (tanpa termin belum-bayar & retensi)
  Active --> Closed : Cairkan Retensi
  Cancelled --> [*]
  Closed --> [*]
```

### D.6 Lifecycle Persetujuan Jurnal Manual

```mermaid
flowchart LR
  N["Baru"] --> F["Finalize"]
  F --> A["Approve → Terposting ke GL"]
  F --> R["Reject → kembali"]
```
