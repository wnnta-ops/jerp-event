# Diagram Alur per Modul — Java Core System
### Lampiran Diagram · Flowchart Detail Tiap Modul

| | |
|---|---|
| **Versi** | 1.0 · 2026-06-04 |
| **Isi** | Diagram alur (flowchart) detail untuk **setiap modul** — langkah, titik keputusan, dan jurnal otomatis |
| **Pendamping** | `PRD-Java-Core-System` (gambaran) · `Spesifikasi-Modul-Detail` (field & aturan) |

> Diagram menggunakan **Mermaid** (ter-render otomatis di versi HTML & PDF). Notasi: persegi = langkah/aksi · belah ketupat = keputusan · kotak "Jurnal" = posting GL otomatis.

---

# BAGIAN A — FRONT OFFICE (PENJUALAN & CRM)

## 1. CRM — Leads
```mermaid
flowchart TD
  A["Prospek masuk (sumber: iklan/referral/walk-in)"] --> B["Buat Lead (nama, telp, minat unit)"]
  B --> C["Tahap: Baru"]
  C --> D["Follow-up / catat aktivitas"]
  D --> E{"Hasil?"}
  E -->|"Tertarik"| F["Pindah tahap (Kontak → Kualifikasi → Penawaran)"]
  E -->|"Tidak"| G["Tandai LOST (+alasan)"]
  F --> H{"Deal?"}
  H -->|"Ya"| I["CONVERT → Customer / Booking"]
  H -->|"Belum"| D
  I --> J["Lead selesai (terhubung ke customer_id/booking_id)"]
  G --> K["Lead ditutup"]
```

## 2. Customer / CIF
```mermaid
flowchart TD
  A["Buka form Pendaftaran Customer"] --> B["Isi data pribadi + identitas (KTP)"]
  B --> C{"Centang 'Sama dengan KTP'?"}
  C -->|"Ya"| D["AddressClone → salin alamat ke surat-menyurat/kantor"]
  C -->|"Tidak"| E["Isi alamat manual"]
  D --> F["Isi NPWP"]
  E --> F
  F --> G{"NPWP sama dengan KTP?"}
  G -->|"Ya"| H["npwp_name = Nama, npwp = No.Identitas"]
  G -->|"Tidak"| I["Isi NPWP manual"]
  H --> J["Simpan (No.Identitas TERENKRIPSI)"]
  I --> J
  J --> K["Upload dokumen (KTP/NPWP/KK)"]
  K --> L["Customer siap dipakai (CIF)"]
```

## 3. Master Properti & Siteplan
```mermaid
flowchart TD
  A["Setup Project → Cluster → Block → Tipe Unit"] --> B["Daftar Unit (kode, harga dasar, LT/LB)"]
  B --> C["Tempel ke Siteplan (titik x,y)"]
  C --> D["Siteplan interaktif"]
  D --> E{"Status unit"}
  E -->|"Available"| F["Klik unit → 'Buat Booking'"]
  E -->|"Hold/Booked/Sold"| G["Tampilkan status + info"]
```

## 4. Pricelist
```mermaid
flowchart LR
  A["Buat Pricelist (periode berlaku)"] --> B["Isi harga + diskon maks per unit"]
  B --> C["Aktifkan (1 aktif per periode)"]
  C --> D["Harga unit mengikuti pricelist aktif"]
  D --> E["Perubahan harga dicatat di History Harga"]
```

## 5. NUP — Nomor Urut Pemesanan
```mermaid
flowchart TD
  A["Buka skema NUP (saat launching)"] --> B{"Daftar via?"}
  B -->|"Publik (anonim)"| C["Form publik → store → done"]
  B -->|"Internal"| D["Sales daftarkan customer"]
  C --> E["Bayar NUP (queue_no diberikan)"]
  D --> E
  E --> F["Verifikasi admin"]
  F --> G{"Lanjut?"}
  G -->|"Beli unit"| H["PROMOTE → Booking"]
  G -->|"Batal"| I["Refund (− penalti bila ada)"]
```

## 6. Hold Unit
```mermaid
flowchart LR
  A["Pilih unit Available"] --> B["Buat Hold (set masa berlaku)"]
  B --> C["Unit terkunci (status Hold)"]
  C --> D{"Tindakan"}
  D -->|"Lanjut beli"| E["Buat Booking"]
  D -->|"Batal"| F["Release manual"]
  D -->|"Lewat waktu"| G["Kedaluwarsa otomatis (scheduler 00:30)"]
  F --> H["Unit kembali Available"]
  G --> H
```

## 7. Booking
```mermaid
flowchart TD
  A["Pilih unit (siteplan/hold/NUP)"] --> B["Pilih skema pembayaran + customer"]
  B --> C["Preview harga (diskon + gimmick + PPN + total)"]
  C --> D{"Ada diskon?"}
  D -->|"Ya"| E["Ajukan APPROVAL diskon"]
  E --> F{"Disetujui?"}
  F -->|"Tidak"| C
  F -->|"Ya"| G["Booking CONFIRMED"]
  D -->|"Tidak"| G
  G --> H["Buat Jadwal Tagihan (payment_schedules)"]
  G --> I["Jurnal Kontrak: Dr Piutang / Cr Pendapatan + PPN Keluaran"]
  G --> J{"Tidak dibayar tepat waktu?"}
  J -->|"Ya"| K["Kedaluwarsa otomatis (booking:expire)"]
```

## 8. Contract / PPJB
```mermaid
flowchart TD
  A["Booking CONFIRMED"] --> B["Buat Kontrak (snapshot harga)"]
  B --> C["Cetak PPJB (nomor sistem/manual)"]
  C --> D["Start Signing"]
  D --> E["Lengkapi dokumen (checklist compliance)"]
  E --> F{"Dokumen lengkap & ditandatangani?"}
  F -->|"Belum"| E
  F -->|"Ya"| G["PPJB Complete / Signed"]
  G --> H["Operasi kontrak (mis. Adjust PPN) bila perlu"]
```

## 9. Gimmick
```mermaid
flowchart LR
  A["Buka kontrak"] --> B["Apply PAKET gimmick (sesuai cluster)"]
  B --> C["Item gimmick menempel (qty, target lunas)"]
  C --> D{"Sudah diberikan ke customer?"}
  D -->|"Ya"| E["Toggle 'diterima' (+tanggal)"]
  D -->|"Batal"| F["Remove gimmick"]
```

## 10. TTS — Tanda Terima Sementara
```mermaid
flowchart TD
  A["Customer bayar (tunai/transfer/giro/VA)"] --> B["Buat TTS (nominal, metode)"]
  B --> C["Alokasikan ke termin (jadwal tagihan)"]
  C --> D{"Metode = Giro?"}
  D -->|"Ya"| E["Catat Giro (pending)"]
  E --> F{"Kliring?"}
  F -->|"Cair"| G["clear-giro"]
  F -->|"Tolak"| H["reject-giro"]
  D -->|"Tunai/Transfer"| I["POST TTS"]
  G --> I
  I --> J["Jurnal: Dr Kas/Bank / Cr Piutang"]
  J --> K["Kuitansi otomatis terbit"]
```

## 11. Kuitansi
```mermaid
flowchart LR
  A["TTS di-POST"] --> B["Kuitansi otomatis (nomor + terbilang)"]
  B --> C["Cetak PDF (tampil No Unit)"]
  C --> D["Diserahkan ke customer"]
```

## 12. Memo (Free DP / Subsidi DP)
```mermaid
flowchart TD
  A["Booking butuh keringanan DP"] --> B["Buat Memo (Free DP / Subsidi DP)"]
  B --> C["Ajukan APPROVAL"]
  C --> D{"Disetujui?"}
  D -->|"Ya"| E["Alokasikan ke jadwal (pengganti uang masuk)"]
  D -->|"Tidak"| F["Ditolak"]
  E --> G{"Batal?"}
  G -->|"Ya"| H["Cancel Memo"]
```

## 13. Cashback & Refund
```mermaid
flowchart LR
  A["Ajukan Cashback / Refund"] --> B["APPROVAL"]
  B --> C{"Disetujui?"}
  C -->|"Ya"| D["Bayar (pay) → dana keluar via Memo"]
  C -->|"Tidak"| E["Ditolak"]
```

## 14. Komisi
```mermaid
flowchart TD
  A["Booking confirmed / event pemicu"] --> B["Hitung Komisi (skema per agent_type)"]
  B --> C["Buat baris komisi per agen (tier, rate)"]
  C --> D["Ajukan Payout (APPROVAL)"]
  D --> E{"Disetujui?"}
  E -->|"Ya"| F["Bayar payout (via Memo) → status paid"]
```

## 15. KPA / Bank Loan
```mermaid
flowchart TD
  A["Buat Pengajuan KPA (bank, plafon, tenor)"] --> B["SUBMIT ke bank"]
  B --> C["Bank terbit SP3K (approved_amount, bunga)"]
  C --> D{"SP3K disetujui?"}
  D -->|"Ya"| E["AKAD (notaris)"]
  E --> F["Akad Complete"]
  F --> G["Realisasi/Pencairan (− retensi bank)"]
  G --> H["Retensi dicairkan kemudian / klaim"]
  D -->|"Tidak / kedaluwarsa"| I["Reject (+alasan)"]
```

## 16. Legal (AJB/BAST/IMB/Sertifikat)
```mermaid
flowchart LR
  A["PPJB selesai + pembayaran tuntas"] --> B["AJB (akta jual beli, notaris)"]
  B --> C["BAST (serah terima + checklist)"]
  C --> D["IMB (per unit)"]
  D --> E["Sertifikat (balik nama ke pemilik)"]
```

## 17. Collection — Penagihan
```mermaid
flowchart TD
  A["Jadwal tagihan jatuh tempo (scheduler 01:00)"] --> B["Generate PJT + update Tunggakan (02:00/02:30)"]
  B --> C["Follow-up ke customer"]
  C --> D{"Bayar?"}
  D -->|"Tidak"| E["Hitung Denda (rate/hari)"]
  E --> F["Terbit SP (berjenjang SP1→SP2→SP3)"]
  F --> G{"Bayar setelah SP?"}
  G -->|"Tidak"| H["Eskalasi / pembatalan"]
  D -->|"Lunas"| I["Terbit SKL (Surat Keterangan Lunas) + PDF"]
  G -->|"Lunas"| I
  E --> J{"Pemutihan?"}
  J -->|"Ya"| K["Waive denda (sebagian/penuh)"]
```

## 18. Exception (GN / GU / Reschedule / Pembatalan)
```mermaid
flowchart TD
  A["Permintaan perubahan"] --> B{"Jenis"}
  B -->|"Ganti Nama"| C["GN: old→new customer (+ admin fee)"]
  B -->|"Ganti Unit"| D["GU: old→new unit (+ selisih harga + fee)"]
  B -->|"Reschedule"| E["Jadwal baru (+ admin fee)"]
  B -->|"Batal"| F["Pembatalan (+ penalti, refund)"]
  C --> G["APPROVAL"]
  D --> G
  E --> G
  F --> G
  G --> H{"Disetujui?"}
  H -->|"Ya"| I["Terapkan perubahan (+ Surat Peringatan/biaya)"]
  H -->|"Tidak"| J["Ditolak"]
```

## 19. Virtual Account & Pembayaran
```mermaid
flowchart TD
  A["Assign VA ke customer"] --> B["Customer transfer ke VA"]
  B --> C["Webhook Xendit masuk"]
  C --> D["Buat va_transaction + TTS otomatis"]
  D --> E["Jurnal penerimaan"]
  F["Pembayaran anonim (tak teridentifikasi)"] --> G["Alokasikan manual ke customer/tagihan"]
```

## 20. Faktur Pajak (FO) & 21. Kalkulator
```mermaid
flowchart LR
  A["Penjualan properti"] --> B["Terbit Faktur Pajak (DPP, PPN)"]
  B --> C["Masuk rekap Faktur Keluaran (modul Pajak BO)"]
  D["Kalkulator (standalone)"] --> E["BPHTB / KPR / Skema → simulasi (tanpa simpan)"]
```

# BAGIAN B — BACK OFFICE / BUSINESS SUPPORT (AKUNTANSI)

## 22–23. Master Akuntansi (CoA & Dimensi)
```mermaid
flowchart LR
  A["Bagan Akun (CoA 6 level)"] --> B["Tandai akun postable (daun)"]
  B --> C["Master Dimensi: Cost Center, Departemen, Ledger, Lokasi"]
  C --> D["Master Supplier"]
  D --> E["Siap dipakai semua transaksi & jurnal"]
```

## 24. General Ledger / Jurnal Manual
```mermaid
flowchart TD
  A["Buat Jurnal Manual (baris Dr/Cr + dimensi)"] --> B{"Total Dr = Cr & periode terbuka?"}
  B -->|"Tidak"| A
  B -->|"Ya"| C["Status: Baru"]
  C --> D["Finalize"]
  D --> E{"Approve?"}
  E -->|"Approve"| F["POSTED ke Buku Besar"]
  E -->|"Reject"| C
  G["Import CSV (REFERENSI)"] --> H{"Dry-run?"}
  H -->|"Ya"| I["Cek saja (tidak disimpan)"]
  H -->|"Tidak"| J["Posting per jurnal (validasi balance)"]
```

## 25. Hutang — AP (Invoice → Kuitansi → Pajak)
```mermaid
flowchart TD
  A["Buat Invoice Hutang (No.Invoice Supplier WAJIB)"] --> B["Isi DPP, PPN, PPh, jadwal, Struktur jurnal"]
  B --> C["Jurnal: Dr Beban/Persediaan + PPN Masukan / Cr Hutang"]
  C --> D["Status: OPEN"]
  D --> E["Kuitansi Hutang (bayar 1+ invoice)"]
  E --> F["Jurnal: Dr Hutang / Cr Kas"]
  F --> G{"Lunas?"}
  G -->|"Ya"| H["Status: PAID"]
  D --> I["Set NSFP → Faktur Masukan"]
  D --> J{"Penyesuaian?"}
  J -->|"Non-tunai"| K["Memorial Hutang"]
  J -->|"Hapus"| L["Penghapusan (write-off)"]
```

## 26. Piutang — AR (Tagih → Reschedule → Terima)
```mermaid
flowchart TD
  A["Buat Invoice Piutang (DPP, PPN, PPh, jadwal)"] --> B["Jurnal: Dr Piutang / Cr Pendapatan + PPN Keluaran"]
  B --> C["Status: OPEN"]
  C --> D{"Perlu jadwal ulang?"}
  D -->|"Ya"| E["RESCHEDULE sisa (angsuran terbayar dikunci) — tanpa jurnal"]
  D -->|"Tidak"| F["Kuitansi Piutang (terima bayar)"]
  E --> F
  F --> G["Jurnal: Dr Kas / Cr Piutang"]
  G --> H{"Lunas?"}
  H -->|"Ya"| I["Status: PAID"]
```

## 27–28. Kas/Bank & Kas Kecil
```mermaid
flowchart TD
  A["Transaksi kas/bank"] --> B{"Jenis"}
  B -->|"Masuk"| C["Jurnal: Dr Kas/Bank / Cr Sumber"]
  B -->|"Keluar"| D["Jurnal: Dr Beban / Cr Kas/Bank"]
  E["Kas Kecil"] --> F{"Jenis"}
  F -->|"Top-up"| G["Jurnal: Dr Kas Kecil / Cr Bank"]
  F -->|"Belanja"| H["Jurnal: Dr Beban / Cr Kas Kecil"]
```

## 29. Bon Sementara (Uang Muka)
```mermaid
flowchart TD
  A["Terbitkan Panjar"] --> B["Jurnal: Dr Uang Muka / Cr Kas"]
  B --> C["Realisasi (input beban)"]
  C --> D["Jurnal: Dr Beban / Cr Uang Muka"]
  D --> E{"Selisih?"}
  E -->|"Sisa (kembali)"| F["REFUND → Dr Kas / Cr Uang Muka"]
  E -->|"Kurang (nombok)"| G["REIMBURSE → Dr Uang Muka / Cr Kas"]
  E -->|"Pas"| H["Selesai (settled)"]
```

## 30. Cek / Giro Masuk
```mermaid
flowchart TD
  A["Terima Giro dari customer"] --> B["Jurnal: Dr Piutang Giro / Cr counter"]
  B --> C["Status: In Hand"]
  C --> D{"Jatuh tempo"}
  D -->|"Cair"| E["KLIRING → Dr Bank / Cr Piutang Giro (cleared)"]
  D -->|"Tolak"| F["BOUNCE → balik jurnal (bounced)"]
```

## 31. Rekonsiliasi Bank
```mermaid
flowchart LR
  A["Pilih akun + saldo rekening koran"] --> B["Cocokkan baris buku ↔ koran"]
  B --> C["Centang baris cleared"]
  C --> D{"Saldo cocok?"}
  D -->|"Ya"| E["FINALIZE"]
  D -->|"Tidak"| B
```

## 32. Aset Tetap
```mermaid
flowchart TD
  A["Registrasi Aset (harga, umur, akun)"] --> B["Jurnal perolehan: Dr Aset / Cr Dana"]
  B --> C["Penyusutan periodik / batch"]
  C --> D["Jurnal: Dr Beban Penyusutan / Cr Akumulasi"]
  D --> E{"Pelepasan?"}
  E -->|"Hapus"| F["Dr Akum + Rugi / Cr Aset (disposed)"]
  E -->|"Jual"| G["Dr Kas+Akum / Cr Aset + Laba | − Rugi (sold)"]
  H["Migrasi aset berjalan"] --> I["Saldo Awal Penyusutan: Dr Laba Ditahan / Cr Akumulasi"]
```

## 33. Persediaan / Stock
```mermaid
flowchart TD
  A["FPB / Permintaan Barang"] --> B["Approve FPB"]
  B --> C{"Pergerakan"}
  C -->|"Penerimaan"| D["Dr Persediaan / Cr Sumber + update Kartu Stok (in)"]
  C -->|"Pengeluaran"| E["Dr Beban/CIP / Cr Persediaan + Kartu Stok (out)"]
```

## 34. Pembelian / PO
```mermaid
flowchart TD
  A["Buat PO (baris item, PPN)"] --> B["Approve PO"]
  B --> C{"Tindakan"}
  C -->|"Revisi"| D["Edit baris + hitung ulang (revision_count++)"]
  C -->|"Posting Termin"| E["Uang Muka: Dr Uang Muka Pembelian / Cr Kas"]
  C -->|"Adjust PPN"| F["Set ulang % PPN + total"]
  C -->|"Selesai"| G["Close PO"]
```

## 35. Kontraktor / SPK
```mermaid
flowchart TD
  A["Buat SPK (nilai kontrak, retensi%, PPN%)"] --> B["Progress termin"]
  B --> C["Jurnal: Dr CIP + PPN / Cr Hutang + Retensi"]
  C --> D["Bayar termin: Dr Hutang / Cr Kas"]
  A --> E{"Aksi lain (SPK aktif)"}
  E -->|"Addendum"| F["± nilai kontrak (guard ≥ progress)"]
  E -->|"Adjust PPN"| G["PPN progress berikutnya"]
  E -->|"Pemakaian Barang"| H["Dr CIP / Cr Persediaan"]
  E -->|"Mutasi Progress"| I["Dr CIP-tujuan / Cr CIP-asal"]
  E -->|"Batalkan"| J["status Cancelled (guard: tak ada unpaid/retensi)"]
  D --> K["Cairkan Retensi: Dr Hutang Retensi / Cr Kas → SPK Closed"]
```

## 36. Budget Control
```mermaid
flowchart TD
  A["Alokasi anggaran (tahun/akun/cost-center)"] --> B["Status: Draft"]
  B --> C["AJUKAN (submit)"]
  C --> D{"Setujui?"}
  D -->|"Approve"| E["Status: Disetujui"]
  D -->|"Reject"| F["Status: Ditolak"]
  E --> G{"Mutasi?"}
  G -->|"Transfer"| H["Pindah nilai antar baris anggaran"]
  G -->|"Koreksi"| I["Sesuaikan nilai 1 baris"]
  E --> J["Tampil Budget vs Realisasi (Σ jurnal beban)"]
```

## 37. COGS / HPP RealCost
```mermaid
flowchart TD
  A["Akumulasi biaya CIP per cluster"] --> B["GENERATE RealCost (pilih proyek + akun CIP)"]
  B --> C["Hitung biaya/unit (rata | proporsi harga)"]
  C --> D["Tampil unit: terjual / sudah-diakui / belum"]
  D --> E["Pilih unit terjual & belum diakui"]
  E --> F["POSTING HPP: Dr Beban Pokok / Cr CIP"]
  F --> G["Unit ditandai recognized (idempoten)"]
```

## 38. Payroll
```mermaid
flowchart TD
  A["Run Payroll (periode, % PPh21, % BPJS)"] --> B["Hitung tiap karyawan aktif (bruto, potongan, net)"]
  B --> C["Jurnal: Dr Beban Gaji / Cr PPh21 + BPJS + Kas"]
  C --> D["Payslip per karyawan"]
```

## 39. Pajak (e-Faktur & SPT)
```mermaid
flowchart TD
  A["Invoice AR/AP ber-PPN"] --> B["Set NSFP (no. seri faktur)"]
  B --> C["Rekap Faktur Keluaran / Masukan"]
  C --> D{"Output"}
  D -->|"e-Faktur"| E["Export CSV / XML (skema DJP)"]
  D -->|"SPT Masa"| F["Cetak SPT PPN / PPh + Bukti Potong"]
  C --> G["Dashboard Pajak (jatuh tempo setor/lapor)"]
```

## 40. Tutup Buku
```mermaid
flowchart TD
  A["Input transaksi sepanjang bulan"] --> B["TUTUP BULANAN → kunci periode"]
  B --> C{"Coba posting ke periode terkunci?"}
  C -->|"Ditolak"| D["Periode aman"]
  B --> E["...akhir tahun..."]
  E --> F["TUTUP TAHUNAN → Jurnal penutup"]
  F --> G["Dr Pendapatan / Cr Beban / (Cr|Dr) Laba Ditahan"]
  G --> H["Neraca Awal tahun berikutnya (carry-forward)"]
```

## 41. Upload Massal & Proyeksi
```mermaid
flowchart LR
  A["Upload CSV (Aset / Anggaran / Saldo Awal Stok)"] --> B{"Dry-run?"}
  B -->|"Ya"| C["Cek + laporan error per-baris"]
  B -->|"Tidak"| D["Simpan massal (+ jurnal saldo awal stok)"]
  E["Proyeksi Hutang/Piutang"] --> F["Bucket Jatuh Tempo + 12 bulan per supplier/customer"]
```

---

# BAGIAN C — SECURITY, SETUP & SETTINGS

## 42–44. Security / Setup Sales / Settings
```mermaid
flowchart TD
  A["Security (SDM lapangan)"] --> A1["Absensi · Riwayat Jabatan · Tiket Teknis · Matrikulasi akses"]
  B["Setup Sales"] --> B1["Skema Bayar · Sales · Agent · Pricelist · Sumber Data"]
  C["Settings"] --> C1["User (MFA, blokir gagal login) · Role/Permission · Numerator · Parameter · Notifikasi"]
```

---

## Catatan
Diagram di atas menyederhanakan alur utama tiap modul; rincian field, status, dan aturan ada di **`Spesifikasi-Modul-Detail`**. Untuk diagram arsitektur & ERD lihat **`PRD-Java-Core-System` (Lampiran D)**.

*— Java Core System · 2026-06-04 —*
