# 📁 docs/ — Dokumentasi Java Core System

Folder ini berisi **PRD lengkap** dan **Ringkasan Eksekutif** dalam berbagai format siap pakai.

## Isi & kegunaan

| Berkas | Format | Untuk apa |
|---|---|---|
| **PRD-Java-Core-System.pdf** | PDF | **Bagikan / presentasi** (rapi, diagram ter-render). Buka di mana saja. |
| **PRD-Java-Core-System.doc** | Word | **Edit di Microsoft Word** (klik 2×). Bisa Save As `.docx`. |
| **PRD-Java-Core-System.html** | HTML | **Lihat di browser** (diagram interaktif) · bisa di-`Ctrl+P → Save as PDF`. |
| **PRD-Java-Core-System.md** | Markdown | Sumber teks (render + diagram di GitHub/VS Code). |
| **Spesifikasi-Modul-Detail.pdf / .doc / .html / .md** | semua | **Rincian SANGAT detail tiap modul** (field, status, alur, aturan, jurnal, penomoran, endpoint) — 44 modul, 4 bagian. |
| **Diagram-Alur-Modul.pdf / .doc / .html / .md** | semua | **Flowchart alur per modul** (langkah, keputusan, jurnal) — 40+ diagram. Diagram tampil di **HTML/PDF**. |
| **Ringkasan-Eksekutif.pdf / .doc / .html / .md** | semua | **Versi 2 halaman untuk manajemen** (sama 4 format). |
| `_diagrams.md` | Markdown | Sumber diagram Mermaid (ERD + alur). |
| `_build.php` | Script | Generator MD→HTML/Word (lihat "Regenerasi"). |

> **Rekomendasi:** untuk dibagikan ke manajemen/eksternal → pakai **PDF**. Untuk diedit/ditambah → pakai **.doc** (Word). Untuk lihat cepat dengan diagram → **.html**.

## Isi dokumen
- **PRD (lengkap):** 21 bab + 4 lampiran — ringkasan, tujuan, ruang lingkup, RBAC, arsitektur, aturan bisnis, **rincian tiap modul FO & BO**, model data, integrasi, non-fungsional, user journeys, **template jurnal**, numerator, pengujian, deployment, glosarium, **peta route lengkap**, **diagram (arsitektur, alur penjualan, alur akuntansi, ERD, siklus SPK)**.
- **Ringkasan Eksekutif:** 2 halaman — apa ini, nilai bisnis, cakupan, bukti kesiapan, keamanan, deployment, langkah lanjut.

## Regenerasi (bila PRD.md diperbarui)
```bash
# 1) Bangun ulang MD gabungan + HTML + Word
php docs/_build.php

# 2) Cetak ulang PDF via Chrome/Edge headless (PowerShell):
#    (lihat perintah di bawah — ganti path bila perlu)
```
```powershell
$exe = "C:\Program Files\Google\Chrome\Application\chrome.exe"
$docs = "$PWD\docs"
foreach ($b in @("PRD-Java-Core-System","Ringkasan-Eksekutif")) {
  $html = Join-Path $docs ($b+".html"); $pdf = Join-Path $docs ($b+".pdf")
  & $exe --headless=new --disable-gpu --run-all-compositor-stages-before-draw --virtual-time-budget=15000 ("--print-to-pdf="+$pdf) ("file:///"+($html -replace '\\','/')) | Out-Null
}
```

*Dibuat 2026-06-04. Sumber kebenaran tetap kode di `app/Domain` & `routes/web.php`; deployment di `DEPLOY.md`.*
