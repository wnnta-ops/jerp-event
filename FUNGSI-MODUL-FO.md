# Fungsi Modul & Sub-Modul FO (Front Office) — J-ERP
Catatan ringkas: tiap sub-modul = fungsinya.

---

## Marketing & Sales (Customer, Booking, Kontrak SPRS/SPR, Adjustment)
- Customer = input & kelola data pembeli (KTP/NPWP, pasangan, alamat, rekening)
- Master Booking = pemesanan/reservasi unit oleh sales
- Verifikasi Data Booked = cek bukti transfer, setujui, bentuk SPRS + booking fee
- SPRS = pembuatan kontrak sementara (booking)
- SPR = pembuatan kontrak final + jadwal tagihan + faktur penjualan
- Adjustment Kontrak = koreksi nilai/harga kontrak (via approval)
- Pengalihan Hak = pindah kepemilikan ke customer lain
- Pindah Unit = tukar unit dalam 1 kontrak
- Pembatalan Kontrak = batalkan kontrak + hitung refund otomatis
- Upload Kontrak / Jadwal Tagihan / Pembayaran = migrasi data massal dari sistem lama

## Setup Sales (master penjualan & unit)
- Skema Bayar = master termin pembayaran (DP / angsuran / cash / KPR)
- Tipe Sales / Level Sales = master klasifikasi & jenjang sales
- Pendaftaran Sales = input data agen/sales (rekening, NPWP)
- Sumber Data = master sumber lead (iklan, referral)
- Price List = daftar harga unit
- Tipe Gimmick / Pendaftaran Gimmick = master & input promo/hadiah
- Tipe Unit / Unit = master tipe rumah & data unit (status, harga, koordinat)
- Upload Unit / Price List / Site Plan / Koordinat = import data massal
- Polygon Editor = gambar/edit area unit di siteplan

## Finance AR (penerimaan & piutang)
- TTS = input Tanda Terima Sementara (penerimaan awal)
- Pembayaran AR = lihat riwayat pembayaran per SPR (BF/DP/Angsuran + Pencairan KPR), read-only
- Pelunasan DP, Angsuran & TDP = terima cicilan, terbit kwitansi otomatis
- Pengajuan KPR = ajukan pembiayaan KPR ke bank
- Kwitansi = kelola bukti bayar + kirim ke Approval BO (Posting BO)
- Faktur Penjualan = dokumen penjualan resmi
- Bilyet Giro = kelola pembayaran via giro
- Refund / CB = pengembalian dana (batal / lebih bayar)
- Transfer Anonim = kelola dana masuk yang belum teridentifikasi
- GMC (Gimmick) = biaya promo developer (FREE) yang mengikat SPR/unit/customer
- Setup Hold = aturan periode hold unit
- Dashboard AR = ringkasan piutang, jatuh tempo, reminder

## Collection (penagihan)
- Surat SP = terbitkan Surat Peringatan (SP1/SP2/SP3) tunggakan
- Pemberkasan = kelengkapan dokumen penagihan
- Setup SP = aturan surat peringatan
- Setup Termin SP = jadwal & tahapan penagihan

## Komisi (insentif sales)
- Setup Tarif / Skema Komisi / Termin Komisi = aturan & tahapan perhitungan komisi
- Skema BLT / Termin BLT = aturan & tahapan perhitungan BLT
- Setup Skema Reward = aturan reward
- Generate Komisi = hitung komisi dari penjualan
- Pengajuan Komisi = ajukan pencairan komisi (via approval)
- Realisasi Komisi = cairkan komisi + Posting BO
- Generate / Pengajuan / Realisasi BLT = sama untuk BLT (insentif)
- Daftar Reward = program penghargaan sales

## Approval (persetujuan berjenjang 2 level)
- Approval Pengalihan Hak / Pindah Unit / Pembatalan = setujui perubahan kontrak
- Approval Diskon / Diskon SPRS / Adjustment / Reschedule / Customize = setujui perubahan harga/jadwal
- Approval BLT / Komisi = setujui pencairan insentif
- Log File = jejak audit seluruh approval

## NUP (event launching)
- Setup NUP = konfigurasi tipe/aturan NUP
- Event Launching = buka periode pemesanan (Nomor Urut Pemesanan)
- Pendaftaran NUP = calon pembeli daftar + bayar UTJ
- Verifikasi Data = cek keabsahan data pendaftar

## Launching
- Aktivasi NUP = buka pemilihan unit saat launching
- Pilih Unit (Live) = peta unit real-time (rebutan unit)
- Closing NUP = finalisasi jadi kontrak SPR

## Report
- Daftar Laporan = kumpulan semua laporan (isi menyusul, sesuai definisi tim)

## Pengaturan / Setup Unit
- Pengaturan FO = konfigurasi umum modul FO
- Lokasi / Tahap = master lokasi & tahap proyek
