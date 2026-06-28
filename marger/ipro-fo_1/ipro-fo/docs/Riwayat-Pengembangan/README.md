# Folder Riwayat Pengembangan

Backup ringkasan **seluruh percakapan & pekerjaan pengembangan** Java Core System,
disusun rapi untuk arsip.

## Isi folder

| Berkas | Keterangan |
|--------|-----------|
| `Riwayat-Pengembangan-Java-Core-System.md` | Sumber utama (Markdown) |
| `Riwayat-Pengembangan-Java-Core-System.html` | Versi web (buka di browser) |
| `Riwayat-Pengembangan-Java-Core-System.pdf` | Versi cetak (A4) |
| `Riwayat-Pengembangan-Java-Core-System.doc` | Versi Microsoft Word |
| `_build.php` | Skrip generator (MD → HTML + DOC) |

## Cara memperbarui

```bash
php docs/Riwayat-Pengembangan/_build.php          # regen .html + .doc dari .md
# lalu PDF (Chrome/Edge headless):
chrome --headless=new --print-to-pdf="<abs>/...pdf" "<abs>/...html"
```

Dokumen terkait (spesifikasi software, bukan riwayat): lihat folder `docs/` induk —
PRD, Spesifikasi Modul Detail, Diagram Alur Modul, Ringkasan Eksekutif.
