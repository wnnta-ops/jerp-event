# Panduan Kerja Tim — Biar Tidak Saling Menimpa Kerjaan

Panduan singkat cara kerja bareng di 1 repo (J-ERP + ipro-fo) **tanpa bentrok**.

---

## 🧠 Inti (cukup ingat ini)
> Tiap orang kerja di **salinan sendiri** (branch). Jangan langsung ke yang utama (`main`).
> Gabung ke `main` lewat **persetujuan (Pull Request)**.

---

## 📄 Analogi gampang
Bayangkan repo = **1 dokumen besar** yang dikerjakan rame-rame:

- A edit **halaman 1**, B edit **halaman 5** → aman, tinggal digabung. ✅
- A & B sama-sama edit **baris yang persis sama** → **BENTROK**, harus dipilih mana yang dipakai. ⚠️

**Semua aturan di bawah tujuannya satu:** biar jarang ada yang edit "baris yang sama".

---

## ✅ 5 Aturan Wajib

**1. Jangan kerja langsung di `main`.**
Bikin branch sendiri tiap mau kerja:
```bash
git checkout main
git pull                       # ambil yang terbaru dulu
git checkout -b feat/nama-fiturmu
```

**2. Tarik update terbaru SERING** (tiap mulai kerja pagi).
Biar selisihmu dengan tim kecil → konflik jarang & kecil:
```bash
git checkout feat/nama-fiturmu
git fetch origin
git merge origin/main
```

**3. Pegang folder bagianmu saja** (lihat tabel di bawah).
Kalau dua orang tak menyentuh file yang sama → **tidak akan bentrok**.

**4. Migrasi (perubahan database): selalu bikin FILE BARU.**
Jangan pernah edit file migrasi orang lain.

**5. Gabung lewat Pull Request (PR), bukan langsung.**
Minta teman/lead lihat dulu sebelum di-merge ke `main`.

---

## 👥 Siapa Pegang Apa

| Orang | Pegang folder | Jangan sentuh |
|---|---|---|
| **BO + Bankloan** | `marger/ipro-fo_1/ipro-fo/` | folder J-ERP (`app/Modules/FO/...`) |
| **Approval** | `app/Modules/FO/Approval/`, `app/Filament/Fo/Resources/Approval*`, `mobile/` | sync/GL J-ERP |
| **FO lain** (Sales, NUP, Finance, dll) | folder modulnya sendiri di `app/Modules/FO/...` | folder orang lain |

**⚠️ Zona bersama** — ubah HANYA setelah ngobrol dulu (jangan barengan):
- `app/Modules/BO/IproBridge/` (jembatan FO↔BO)
- `config/ipro.php`, `config/sso.php`
- Skema/tabel `ipro_bo` (dipakai BO & Approval)

---

## 🔁 Alur Harian (cheat sheet)

**Mulai kerja:**
```bash
git checkout feat/fiturmu
git fetch origin && git merge origin/main      # ambil update tim
```

**Selesai (simpan kerjaan):**
```bash
git add <file-yang-kamu-ubah>
git commit -m "ringkasan singkat yang kamu kerjakan"
git push
```

**Mau digabung ke main:**
1. `git fetch origin && git merge origin/main` (resolve bentrok di branch sendiri dulu)
2. `git push`
3. Buka **Pull Request** di GitHub: branch kamu → `main`
4. Tunggu di-review → **Merge**

---

## 🛠️ Kalau Kena BENTROK (conflict)
Tenang, normal. Saat `git merge`, Git akan tandai file yang bentrok:
```
<<<<<<< HEAD
(kode kamu)
=======
(kode teman)
>>>>>>> origin/main
```
Caranya:
1. Buka file itu, **pilih/gabung** kode yang benar, **hapus** tanda `<<<<`, `====`, `>>>>`.
2. ```bash
   git add <file>
   git commit
   ```
Kalau ragu kode mana yang benar → **tanya orang yang menulis kode itu** sebelum dihapus.

---

## ❌ Yang TIDAK boleh
- Commit/push langsung ke `main`.
- Edit file/migrasi orang lain tanpa izin.
- 1 branch dipakai banyak orang sekaligus (bikin branch sendiri-sendiri).
- Commit file `.env`, backup database, atau file besar/sampah.

---

## 🚀 Catatan deploy
Yang nge-deploy ke server: tarik branch yang sudah di-merge, lalu jalankan langkah di `marger/DEPLOY.md` / `marger/CATATAN-SERVER.md`.
