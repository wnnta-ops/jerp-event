# Ringkasan Eksekutif — Java Core System
### ERP Pengembang Properti (Sales + Akuntansi) · PT. Mitra Usaha Propertindo

| | |
|---|---|
| **Versi** | 1.0 · 2026-06-04 |
| **Status** | **Production-ready** (terverifikasi) |
| **Acuan** | Pengganti setia eProperty (FO) + Batavianet (BO) |

---

## Apa ini?

**Java Core System** adalah satu aplikasi web yang menyatukan **penjualan properti** dan **akuntansi penuh** dalam satu sistem terpadu — menggantikan dua sistem lama (eProperty & Batavianet) yang terpisah dan sulit dikembangkan.

Keunggulan utama: **setiap transaksi penjualan & keuangan otomatis membentuk jurnal akuntansi yang seimbang** (Debit = Kredit), dari booking unit sampai laporan keuangan dan SPT pajak — tanpa input ganda.

---

## Nilai untuk Perusahaan

| Manfaat | Penjelasan |
|---|---|
| **Satu sistem terpadu** | Sales ↔ Akuntansi terhubung otomatis. Booking → jurnal kontrak; pembayaran → buku besar. Tidak ada pencatatan ganda. |
| **Akuntansi akurat & aman** | Double-entry dijamin seimbang, periode bisa dikunci, jejak audit lengkap (siapa/kapan/apa). |
| **Patuh pajak Indonesia** | PPN, PPh 21/23/4(2), **e-Faktur (CSV/XML)**, **SPT Masa** — sesuai format DJP. |
| **Milik sendiri** | Kode dimiliki perusahaan → bisa dikustomisasi kapan saja, tanpa biaya lisensi pihak ketiga. |
| **Modern & mudah** | Bahasa Indonesia, antarmuka modern (mode gelap), bisa diakses banyak user dari mana saja. |

---

## Cakupan Sistem (ringkas)

**Front Office — Penjualan & CRM**
CRM/Leads · Customer/CIF · Unit & Siteplan · Pricelist · NUP · Hold · **Booking** · **PPJB** · Gimmick · **TTS/Kuitansi** · Memo/Cashback/Refund · Komisi · **KPA/KPR** · Legal (AJB/BAST/Sertifikat) · **Collection** (Denda/SP/SKL) · Exception (Ganti Nama/Unit/Reschedule) · Virtual Account · Kalkulator (BPHTB/KPR/Skema).

**Back Office — Akuntansi ("Batavianet")**
Bagan Akun · **General Ledger/Jurnal** · Kas/Bank · Kas Kecil · Bon Sementara · Cek/Giro · **Hutang (AP)** · **Piutang (AR)** · **Aset Tetap** · Persediaan · Pembelian (PO) · **Kontraktor (SPK)** · **Budget** · **COGS/HPP** (real-cost per unit) · **Payroll** · **Pajak** (e-Faktur/SPT) · **Tutup Buku**.

**Laporan**: Neraca · Laba/Rugi · Arus Kas · Neraca Lajur · Komparasi · Aging · Kartu Hutang/Piutang · Proyeksi · Dashboard Pajak.

---

## Skala & Bukti Kesiapan

| Metrik | Angka |
|---|---|
| Modul/halaman aplikasi | **171 halaman** — semua render OK |
| Skenario alur inti teruji otomatis | **24 / 24 PASS** |
| Pengujian transaksi end-to-end (akuntansi) | **39 sub-transaksi — seluruh jurnal seimbang** |
| Tabel database | 72 |
| Model domain | 152 |
| Hak akses (RBAC) | 193 permission / 11 peran |
| Endpoint/route | ~300 |

> Seluruh sub-menu eProperty (FO) & Batavianet (BO) yang teridentifikasi sudah terimplementasi. Pengujian end-to-end menemukan & memperbaiki bug; tidak ada jurnal yang tidak seimbang.

---

## Keamanan & Tata Kelola

- **RBAC granular** (11 peran: Direktur, Finance, Sales, Collection, dll.) — tiap user hanya melihat yang berhak.
- **Jejak audit** untuk perubahan penting; data sensitif (No. KTP/NPWP) **terenkripsi**.
- **Periode terkunci** mencegah perubahan jurnal masa lalu; **persetujuan berjenjang** untuk diskon, cashback, refund, anggaran, jurnal.

---

## Deployment (Operasional 24/7)

- Dirancang berjalan **24 jam di server** (bukan laptop). Rekomendasi gratis: **Oracle Cloud Always Free**.
- Paket deploy siap pakai (web server + HTTPS otomatis, scheduler jatuh-tempo, backup harian). Panduan lengkap: `DEPLOY.md` & `MULAI-DISINI.md`.

---

## Rekomendasi & Langkah Lanjut

1. **Go-live**: aktifkan server, migrasikan saldo awal (tersedia Upload Massal + Saldo Awal), buat akun user per peran.
2. **Pelatihan singkat** tim Sales & Finance per modul.
3. **Roadmap opsional**: migrasi DB ke MySQL untuk skala besar, integrasi bank, e-Meterai/tanda tangan digital.

*Dokumen lengkap (spesifikasi tiap modul, jurnal, data, route) ada di `PRD-Java-Core-System` (PDF/Word/HTML).*
