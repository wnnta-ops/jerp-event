# Bot Telegram Deploy J-ERP

Menjalankan deploy, merge, dan promote dari Telegram — untuk saat Anda tidak di
depan komputer.

| Perintah | Yang dilakukan |
|---|---|
| `/status` | Cabang, commit terakhir dev/main/bo, kondisi container |
| `/pull` | `git pull --ff-only` di server |
| `/deploy fo` · `bo` · `portal` | Menjalankan `marger/up-*.sh` |
| `/mergebo` | Merge `bo` → `dev`, lalu push |
| `/promote` | Naikkan `dev` → `main`, lalu push |
| `/logs fo` · `bo` | 30 baris log terakhir |
| `/backup` | Backup penuh (DB + berkas) |
| `/backups` | Daftar backup yang ada, ukurannya, dan sisa ruang disk |

Bot menjalankan **perintah tetap** yang tertulis di skrip — bukan shell bebas.
Bocornya token berarti orang lain bisa memicu deploy, tetapi tidak bisa
menjalankan perintah sembarang di server.

---

## Yang harus dipahami sebelum memasang

**WAJIB bot yang BERBEDA dari bot persetujuan SSH.** Keduanya membaca antrean
lewat `getUpdates`, dan antrean itu bersifat merusak — update yang diambil satu
pihak hilang dari yang lain. Bot deploy menunggu terus-menerus, jadi ia akan
menelan jawaban tombol Izinkan. Karena persetujuan SSH dirancang "gagal = tolak",
akibatnya **tidak ada yang bisa login SSH sama sekali**.

**Server perlu kredensial tulis GitHub** untuk `/mergebo` dan `/promote`. Pakai
*fine-grained token* yang dibatasi ke repo ini saja dengan izin `Contents: write`
— bukan token akun penuh. Tanpa itu, kedua perintah gagal saat push (deploy dan
status tetap jalan).

**`/mergebo` dan `/promote` tidak memindahkan cabang aktif server.** Keduanya
bekerja di *worktree* sementara, lalu push langsung ke `origin`. Checkout produksi
tetap di `main`. Ini penting: kalau merge dikerjakan dengan `git checkout dev` di
tempat, setiap `/deploy` sesudahnya akan mem-build kode `dev` di server produksi
tanpa ada tanda apa pun. Untuk benar-benar menaikkan kode ke server, urutannya
tetap `/mergebo` → `/promote` → `/pull` → `/deploy`.

**Konflik merge tidak pernah diselesaikan bot.** Bila `/mergebo` atau `/promote`
menemui konflik, bot membatalkan dan melapor berkas mana yang bentrok. Tidak ada
yang berubah. Konflik butuh penilaian manusia atas kode mana yang benar.

**Bot berjalan sebagai pemilik repo, bukan root.** Menjalankannya sebagai root
berarti bocornya token bot setara akses root.

---

## 1. Buat bot baru

@BotFather → `/newbot` → simpan token. **Jangan** pakai token bot persetujuan SSH.

Chat id Anda (dan/atau grup) didapat dengan cara yang sama seperti bot persetujuan.

## 2. Kredensial GitHub di server

GitHub → Settings → Developer settings → **Fine-grained tokens** → repo ini saja,
izin **Contents: Read and write**, dengan tanggal kedaluwarsa (jangan "No expiration").

Dipasang sebagai user **`ptmup`** — bot berjalan sebagai user itu, bukan root:

```bash
cd ~/docker/jerp
git remote set-url origin https://github.com/arieyogian/jerp.git

git config --global credential.helper store
printf 'https://arieyogian:github_pat_TOKEN_ANDA@github.com
' > ~/.git-credentials
chmod 600 ~/.git-credentials

git config --global user.name  "Deploy Bot"
git config --global user.email "deploy@javaresidence.com"
```

**Jangan menempelkan token ke dalam URL remote.** Bot meneruskan keluaran git ke
Telegram, dan pesan error `git push` sering memuat URL remote secara utuh — token
akan tercetak di riwayat chat sekaligus di journald. Dengan credential helper,
`git remote -v` tetap bersih dan token hanya ada di satu berkas ber-mode 600.

Uji sebelum dipakai lewat bot:

```bash
git fetch origin && git push origin main --dry-run
```

`Everything up-to-date` tanpa ditanyai username/password berarti sudah benar.

## 3. Pasang

```bash
sudo apt update && sudo apt install -y jq curl

sudo cp ops/telegram-deploy-bot/deploy-bot.sh /usr/local/bin/deploy-bot.sh
sudo chmod 755 /usr/local/bin/deploy-bot.sh
sudo mkdir -p /var/lib/deploy-bot && sudo chown ptmup:ptmup /var/lib/deploy-bot
```

```bash
sudo tee /etc/deploy-bot.conf >/dev/null <<'EOF'
BOT_TOKEN="8716665652:AAEMsCJ9J2DVZ9xgd_fJe6XulD-ZE3x4vKE"
# HANYA chat ini yang dilayani. Dipisah spasi atau koma.
ALLOWED_CHATS="7599732703"
REPO_DIR="/home/ptmup/docker/jerp"
EOF
sudo chmod 640 /etc/deploy-bot.conf
sudo chown root:ptmup /etc/deploy-bot.conf
```

```bash
sudo cp ops/telegram-deploy-bot/deploy-bot.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now deploy-bot
systemctl status deploy-bot --no-pager | head -6
```

## 4. Uji berurutan, dari yang paling aman

Kirim ke bot, satu per satu:

1. `/help` — memastikan bot menerima perintah
2. `/status` — hanya membaca, tidak mengubah apa pun
3. `/pull` — menarik kode, belum men-deploy
4. `/deploy fo` — build sungguhan

Baru setelah keempatnya benar, coba `/mergebo` dan `/promote`.

## Pemulihan

```bash
sudo systemctl stop deploy-bot      # hentikan sementara
sudo systemctl disable --now deploy-bot   # matikan permanen
sudo journalctl -t deploy-bot -n 50 --no-pager
```

Menghentikan bot **tidak** mengganggu aplikasi maupun persetujuan SSH.

---

## Batasan yang disengaja

**Tidak ada perintah shell bebas.** Menambah perintah berarti mengubah skrip ini
dan men-deploy ulang — bukan sesuatu yang bisa dilakukan lewat chat.

**Tidak ada `/rollback`.** Mengembalikan versi menyentuh database dan migrasi,
dan itu butuh penilaian atas keadaan data saat itu. Kerjakan lewat SSH.

**Keluaran dipangkas 2800 karakter.** Balasan Telegram dibatasi 4096, dan pesan
yang terpotong di tengah lebih menyesatkan daripada ekor yang utuh. Untuk log
penuh, pakai `/logs` atau SSH.
