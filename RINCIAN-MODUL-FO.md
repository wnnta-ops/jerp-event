# Rincian Modul FO (Front Office) — J-ERP
### Bahan Presentasi untuk GM

---

## 1. Ringkasan
Modul **FO (Front Office)** adalah tulang punggung **proses penjualan properti** — dari calon pembeli (lead) sampai unit **lunas** — dalam satu sistem terpadu. FO mengelola: pendaftaran & booking, kontrak (SPRS → SPR), penerimaan pembayaran (DP, angsuran, KPR), komisi sales, penagihan, sampai persetujuan berjenjang (approval). Semua transaksi keuangan FO **otomatis terhubung ke BO (akuntansi/GL) & Bankloan (KPR)** sehingga data penjualan dan pembukuan selalu sinkron — **tanpa entri ganda**.

**Nilai untuk manajemen:** satu sumber data, alur terkontrol (tidak bisa lompat tahap), jejak audit lengkap, dan laporan real-time.

---

## 2. Alur Utama — 2 Jalur Masuk Kontrak

Ada **dua pintu masuk** ke kontrak. Keduanya bertemu di **SPR** lalu lanjut ke pembayaran & pelunasan.

### 🅰️ Jalur BOOKED MANUAL *(pemesanan langsung, di luar event)*
```
Customer / Sales
   └─▶ Master Booking              (pilih unit diinginkan + bukti transfer)
        └─▶ Verifikasi Data Booked      (finance cocokkan bukti transfer / Transfer Anonim)
             └─▶ Approve → Pilih Unit + Booking Fee
                  └─▶ Kontrak SPRS  ── unit jadi BOOKING ──┐
                                                            │  (promosi)
                                                            ▼
                                                     SPR (Final)
```
> SPRS = booking (sementara). Dipromosikan ke SPR final saat disetujui / disimpan sebagai SPR.

### 🅱️ Jalur NUP / LAUNCHING *(event rebutan unit)*
```
Setup NUP  ─▶  Event Launching
   └─▶ Pendaftaran NUP             (calon pembeli daftar + bayar UTJ)
        └─▶ Verifikasi Data NUP         (cek keabsahan data)
             └─▶ Aktivasi NUP → Pilih Unit (peta unit LIVE / rebutan)
                  └─▶ Closing NUP
                       └─▶ Kontrak SPR (Final)  ── unit jadi TERJUAL ──▶
```
> NUP langsung ke **SPR final** (komitmen kuat: sudah bayar UTJ + closing).

### ➡️ Kelanjutan *(SAMA untuk kedua jalur, setelah SPR)*
```
SPR (Final)
   └─▶ Jadwal Tagihan otomatis (ikut Skema Bayar)
        └─▶ Pembayaran:  Booking Fee → DP → Angsuran → Pencairan KPR
             ├─ Kwitansi terbit otomatis
             ├─ Posting BO → Approval UTJ & DP → Jurnal GL (akuntansi)
             └─ (bila telat) Collection / Surat SP
                  └─▶ LUNAS
                       └─▶ Komisi & BLT sales cair
```

> **Kontrol alur:** tiap tahap **mengunci tahap sebelumnya** (mis. Angsuran 2 baru muncul setelah Angsuran 1 lunas) → cegah salah urut & salah bayar. Perubahan sensitif (diskon, batal, pindah unit) wajib lewat **Approval**.

---

## 3. Sub-Modul & Fungsinya

### A. NUP & Launching *(pra-penjualan / event unit)*
| Fitur | Fungsi bisnis |
|---|---|
| Setup & Event NUP | Buka periode pemesanan (Nomor Urut Pemesanan) untuk launching |
| Pendaftaran NUP | Calon pembeli daftar + bayar UTJ |
| Verifikasi Data NUP | Tim cek keabsahan data pendaftar |
| Aktivasi / Pilih Unit / Peta Unit Live | Konversi NUP jadi booking unit; siteplan interaktif status unit |
| Closing NUP | Finalisasi jadi kontrak |

### B. Marketing & Sales *(inti penjualan)*
| Fitur | Fungsi bisnis |
|---|---|
| **Customer** | Master data pembeli (KTP/NPWP, pasangan, alamat) |
| **Master Booking** | Pemesanan unit |
| **Verifikasi Data Booked** | Tim cek bukti transfer → setujui → unit jadi booking |
| **SPRS (Surat Pemesanan)** | Kontrak sementara (booking) |
| **SPR (Final)** | Kontrak resmi + jadwal tagihan + faktur penjualan |
| **Adjustment Kontrak** | Koreksi nilai/skema kontrak (lewat approval) |
| **Pengalihan Hak** | Pindah kepemilikan ke customer lain |
| **Pindah Unit** | Tukar unit dalam 1 kontrak |
| **Pembatalan Kontrak** | Batalkan SPR/SPRS → hitung refund otomatis dari yang sudah dibayar |

### C. Finance AR *(penerimaan & piutang)*
| Fitur | Fungsi bisnis |
|---|---|
| **TTS** | Tanda Terima Sementara (bukti terima pembayaran awal) |
| **Pelunasan DP, Angsuran & TDP** | Terima pembayaran cicilan → Kwitansi otomatis |
| **Pengajuan KPR** | Ajukan pembiayaan bank; pencairan ditarik dari **Bankloan/BO** |
| **Kwitansi (BKM)** | Bukti resmi pembayaran; kirim ke Approval UTJ & DP BO |
| **Pembayaran AR** | Riwayat pembayaran per SPR (BF/DP/Angsuran + Pencairan KPR) read-only |
| **Faktur Penjualan** | Dokumen penjualan resmi |
| **Refund / Cash Back** | Pengembalian dana (batal/lebih bayar) → hutang di BO per jenis |
| **GMC (Gimmick)** | Biaya promo/hadiah ke customer (FREE), ditanggung developer |
| **Transfer Anonim** | Dana masuk belum teridentifikasi → dicocokkan ke customer |
| **Dashboard AR** | Ringkasan piutang, jatuh tempo, reminder |

### D. Komisi & Insentif *(motivasi sales)*
| Fitur | Fungsi bisnis |
|---|---|
| Skema/Tipe Tarif Komisi | Aturan perhitungan komisi per level/skema |
| Generate → Pengajuan → Realisasi **Komisi** | Alur pencairan komisi sales bertahap + approval |
| Generate → Pengajuan → Realisasi **BLT** | Insentif/bonus tambahan |
| Rewards | Program penghargaan sales |

### E. Collection *(penagihan)*
| Fitur | Fungsi bisnis |
|---|---|
| Surat SP (Peringatan) | Terbitkan SP1/SP2/SP3 otomatis untuk tunggakan |
| Pemberkasan | Kelengkapan dokumen penagihan |
| Setup & Termin SP | Aturan jadwal & tahapan penagihan |

### F. Approval *(kontrol & tata kelola)*
Persetujuan **berjenjang (2 level)** untuk perubahan sensitif — mencegah penyalahgunaan:
Diskon, Adjustment Harga, Pembatalan Kontrak, Ganti Nama, Pindah Unit, Reschedule, Pengajuan BLT & Komisi. Tersedia juga **approval via aplikasi mobile** untuk approver + **log** lengkap.

### G. Setup & Master *(fondasi data)*
Skema Bayar, Tipe & Level Sales, Pendaftaran Sales, Sumber Data (lead), Price List, Tipe & Pendaftaran Gimmick, Tipe Unit, Unit, Upload massal (Unit / Price List / Site Plan / Koordinat), **Polygon Editor** siteplan.

---

## 4. Integrasi FO ↔ BO (Akuntansi) & Bankloan (KPR)

Semua transaksi keuangan FO **mengalir otomatis ke BO** — inti nilai sistem terpadu:

| Dari FO | Ke BO | Hasil |
|---|---|---|
| Pelunasan (DP/Angsuran/TDP) | **Approval UTJ & DP** | BO pilih akun → setujui → **jurnal GL otomatis** |
| Refund / Komisi / BLT / GMC | **Posting Invoice** (per Sumber) | Jadi **hutang** di Account Payable → dibayar via kuitansi |
| Pengajuan KPR | **Bankloan** | Pencairan bertahap + retensi ditarik ke laporan AR |
| Master (customer, unit, kontrak) | Sinkron ke `ipro_bo` | Data konsisten 2 sisi |

**Kontrol jurnal:** GL berstatus *draft* → akuntan verifikasi akun sebelum final → **tidak ada jurnal ganda**.

---

## 5. Highlight / Nilai Kunci untuk Manajemen

- ✅ **Satu alur terkunci** — tak bisa lompat tahap; jadwal tagihan urut & akurat.
- ✅ **Otomatis & anti-dobel** — Kwitansi, faktur, jurnal, refund, komisi terbentuk sendiri.
- ✅ **Terhubung akuntansi & bank** — penjualan ↔ pembukuan ↔ KPR selalu sinkron.
- ✅ **Kontrol berjenjang + mobile approval** — perubahan sensitif wajib disetujui, ada jejak audit.
- ✅ **Refund & biaya per jenis** — Refund (SPR/SPRS/Transfer Anonim), Komisi, BLT, Gimmick masuk hutang BO dengan sumber jelas.
- ✅ **Read-only report** — Pembayaran AR & Dashboard AR untuk pantau piutang real-time.

---

*Dokumen ringkas untuk presentasi. Detail teknis per fitur bisa didemokan langsung di aplikasi (FO: menu per grup di sidebar).*

---

## 6. Lampiran — Daftar Lengkap Menu FO & Fungsinya
*(Seluruh menu yang ada di sidebar aplikasi FO, per grup.)*

### 🛍️ Marketing & Sales
| Menu | Fungsi |
|---|---|
| Customer | Master data pembeli: identitas, KTP/NPWP, pasangan, alamat, rekening |
| Master Booking | Pemesanan/reservasi unit oleh sales |
| Verifikasi Data Booked | Cek bukti transfer (booked manual) → setujui → bentuk SPRS + booking fee |
| SPRS (Surat Pemesanan) | Kontrak **sementara** (booking); bisa diskon SPRS |
| SPR (Final) | Kontrak **resmi**: jadwal tagihan (ikut skema), faktur penjualan, cetak SPR |
| Adjustment Kontrak | Koreksi nilai/harga kontrak (lewat approval) |
| Pengalihan Hak | Pindah kepemilikan ke customer lain (lewat approval) |
| Pindah Unit | Tukar unit dalam 1 kontrak (lewat approval) |
| Pembatalan Kontrak | Batalkan SPR/SPRS + **hitung refund otomatis** dari yang sudah dibayar |
| Upload Kontrak / Jadwal Tagihan / Pembayaran | Migrasi data massal dari sistem lama |

### ⚙️ Setup Sales
| Menu | Fungsi |
|---|---|
| Skema Bayar | Master termin pembayaran (DP / angsuran / cash keras / KPR) |
| Tipe Sales · Level Sales | Klasifikasi & jenjang sales |
| Pendaftaran Sales | Master agen/sales (rekening, NPWP) |
| Sumber Data | Master sumber lead (iklan, referral, dll) |
| Price List | Daftar harga unit |
| Tipe Gimmick · Pendaftaran Gimmick | Master promo/hadiah |
| Tipe Unit · Unit | Master tipe & unit rumah (status, harga, koordinat) |
| Upload Unit / Price List / Site Plan / Koordinat | Import data massal |
| Polygon Editor | Editor siteplan interaktif (gambar area unit) |

### 💰 Finance AR
| Menu | Fungsi |
|---|---|
| TTS | Tanda Terima Sementara — bukti penerimaan pembayaran awal |
| **Pembayaran AR** | Riwayat pembayaran per SPR (BF/DP/Angsuran + Pencairan KPR) — read-only |
| Pelunasan DP, Angsuran & TDP | Terima cicilan → Kwitansi otomatis → kirim ke **Approval UTJ & DP** BO |
| Pengajuan KPR | Ajukan KPR ke bank; pencairan ditarik dari **Bankloan/BO** |
| Kwitansi | Bukti resmi bayar; tombol **Posting BO** → Approval UTJ & DP |
| Faktur Penjualan | Dokumen penjualan resmi (otomatis saat SPR) |
| Bilyet Giro | Kelola pembayaran via giro |
| Refund / CB | Pengembalian dana → **Posting Invoice** BO (per jenis: SPR/SPRS/Transfer Anonim) |
| Transfer Anonim | Dana masuk belum teridentifikasi → cocokkan customer / batalkan + refund |
| **GMC (Gimmick)** | Biaya promo developer (FREE), ikat No. SPR/Unit/Customer → **Posting BO** |
| Setup Hold | Aturan periode hold unit |
| Dashboard AR | Ringkasan piutang, jatuh tempo, reminder |

### 📨 Collection (Penagihan)
| Menu | Fungsi |
|---|---|
| Surat SP | Terbitkan Surat Peringatan (SP1/SP2/SP3) untuk tunggakan |
| Pemberkasan | Kelengkapan dokumen penagihan |
| Setup SP · Setup Termin SP | Aturan & jadwal/tahapan penagihan |

### 🏆 Komisi & Insentif
| Menu | Fungsi |
|---|---|
| Setup Tarif · Skema Komisi · Termin Komisi | Aturan perhitungan komisi |
| Skema BLT · Termin BLT | Aturan perhitungan BLT (insentif) |
| Setup Skema Reward | Aturan reward |
| Generate → Pengajuan → Realisasi **Komisi** | Alur pencairan komisi bertahap (+ approval, + Posting BO) |
| Generate → Pengajuan → Realisasi **BLT** | Alur pencairan BLT bertahap (+ approval, + Posting BO) |
| Daftar Reward | Program penghargaan sales |

### ✅ Approval *(persetujuan berjenjang 2 level)*
| Menu | Fungsi |
|---|---|
| Approval Pengalihan Hak / Pindah Unit / Pembatalan | Setujui perubahan kontrak sensitif |
| Approval Diskon · Diskon SPRS · Adjustment · Reschedule · Customize | Setujui perubahan harga/jadwal |
| Approval BLT · Komisi | Setujui pencairan insentif |
| Log File | Jejak audit seluruh approval |

*(Approval juga tersedia via **aplikasi mobile** untuk approver.)*

### 📋 NUP *(event launching)*
| Menu | Fungsi |
|---|---|
| Setup NUP | Konfigurasi tipe/aturan NUP |
| Event Launching | Buka periode pemesanan (Nomor Urut Pemesanan) |
| Pendaftaran NUP | Calon pembeli daftar + UTJ |
| Verifikasi Data | Cek keabsahan data pendaftar |

### 🚀 Launching
| Menu | Fungsi |
|---|---|
| Aktivasi NUP | Buka pemilihan unit saat launching |
| Pilih Unit (Live) | Peta unit real-time (rebutan unit) |
| Closing NUP | Finalisasi jadi kontrak |

### 🔧 Pengaturan / Setup Unit
| Menu | Fungsi |
|---|---|
| Pengaturan FO | Konfigurasi umum modul FO |
| Lokasi · Tahap | Master lokasi & tahap proyek |
